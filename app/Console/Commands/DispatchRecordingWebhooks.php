<?php

namespace App\Console\Commands;

use App\Models\CDR;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Console\Command;
use App\Jobs\SendRecordingWebhook;
use App\Models\RecordingWebhookDelivery;
use App\Services\S3StorageConfigService;
use App\Services\RecordingWebhookConfigService;

class DispatchRecordingWebhooks extends Command
{
    /**
     * When a domain archives to S3, hold recording.available until the file
     * is in the bucket so the payload carries the customer's presigned URL
     * and storage block. If the archive hasn't landed after this long, send
     * with the local URL anyway — recording.archived (if subscribed) follows.
     */
    const ARCHIVE_GRACE_MINUTES = 30;

    /**
     * A recording is named after the leg that recorded it (record_name =
     * ${uuid}.${record_ext}) and is announced under that leg's CDR, so the
     * receiver keys it on the same uuid as the live-transcript fork and the
     * CDR itself. Ring-group siblings that lose the race (LOSE_RACE,
     * billsec 0) and receptionist originate legs share the file but end, or
     * outlast, the recorded leg; their CDRs used to win the claim mid-call
     * and deliver the call as a 0-second recording under the wrong uuid.
     * Wait this long for the recorded leg's CDR before falling back to the
     * longest sibling.
     */
    const RECORDED_LEG_GRACE_MINUTES = 180;

    private const CDR_COLUMNS = [
        'xml_cdr_uuid',
        'domain_uuid',
        'record_path',
        'record_name',
        'direction',
        'billsec',
        'start_stamp',
        'end_stamp',
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webhooks:dispatch-recordings
        {--lookback-hours=24 : Only consider CDRs that started within this window}
        {--retry-failed : Re-dispatch deliveries that previously failed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue call recording webhooks for domains that have them enabled';

    public function handle(RecordingWebhookConfigService $configService, S3StorageConfigService $s3Config)
    {
        $configs = $configService->getEnabledDomainConfigs();

        if (empty($configs)) {
            return Command::SUCCESS;
        }

        if ($this->option('retry-failed')) {
            $this->retryFailed(array_keys($configs));
        }

        $lookback = now()->subHours(max(1, (int) $this->option('lookback-hours')));
        $archiveTargets = $s3Config->getArchiveTargets();
        $dispatched = 0;

        foreach ($configs as $domainUuid => $config) {
            $archiving = isset($archiveTargets[$domainUuid]);

            $dispatched += $this->dispatchAvailable($domainUuid, $config, $lookback, $archiving);

            if (in_array(RecordingWebhookConfigService::EVENT_ARCHIVED, $config['events'], true)) {
                $dispatched += $this->dispatchArchived($domainUuid, $config, $lookback);
            }
        }

        if ($dispatched > 0) {
            $this->info("Dispatched {$dispatched} recording webhook(s)");
        }

        return Command::SUCCESS;
    }

    /**
     * recording.available — one per recording file, sent from the node that
     * can serve it. For archiving domains, fresh local files are left for a
     * later minute (see ARCHIVE_GRACE_MINUTES) so the archive job can move
     * them first; once record_path='S3' any node can send.
     */
    private function dispatchAvailable(string $domainUuid, array $config, $lookback, bool $archiving): int
    {
        $event = RecordingWebhookConfigService::EVENT_AVAILABLE;
        $count = 0;

        $cdrs = $this->candidateCdrs($domainUuid, $config, $lookback, $event)->get(self::CDR_COLUMNS);

        foreach ($cdrs->groupBy('record_name') as $recordName => $legs) {
            $cdr = $this->announcingLeg((string) $recordName, $legs);

            if (!$cdr) {
                continue;
            }

            // Locally-stored recordings can only be served by the node that
            // holds the file: the webhook URL is signed with this node's
            // APP_KEY and points at this node's APP_URL. Leave the CDR
            // unclaimed so the node that recorded the call dispatches it.
            if (!$this->recordingIsServableHere($cdr)) {
                continue;
            }

            if (
                $archiving
                && $cdr->record_path !== 'S3'
                && now()->diffInMinutes($cdr->start_stamp) < self::ARCHIVE_GRACE_MINUTES
            ) {
                continue;
            }

            if ($cdr instanceof CDR && $cdr->isDirty('record_name')) {
                CDR::where('xml_cdr_uuid', $cdr->xml_cdr_uuid)
                    ->where(fn ($q) => $q->whereNull('record_name')->orWhere('record_name', ''))
                    ->update(['record_path' => $cdr->record_path, 'record_name' => $cdr->record_name]);
            }

            if ($this->claim($domainUuid, $cdr, $config, $event)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * recording.archived — only for files whose recording.available went out
     * while the recording was still local; when available already carried
     * the S3 storage block there is nothing new to say.
     */
    private function dispatchArchived(string $domainUuid, array $config, $lookback): int
    {
        $event = RecordingWebhookConfigService::EVENT_ARCHIVED;
        $count = 0;

        $cdrs = $this->candidateCdrs($domainUuid, $config, $lookback, $event)
            ->where('record_path', 'S3')
            ->whereExists(function ($query) {
                $query->selectRaw('1')
                    ->from('recording_webhook_deliveries')
                    ->whereColumn('recording_webhook_deliveries.domain_uuid', 'v_xml_cdr.domain_uuid')
                    ->whereColumn('recording_webhook_deliveries.record_name', 'v_xml_cdr.record_name')
                    ->where('recording_webhook_deliveries.event', RecordingWebhookConfigService::EVENT_AVAILABLE)
                    ->where('recording_webhook_deliveries.status', RecordingWebhookDelivery::STATUS_SENT)
                    ->where('recording_webhook_deliveries.storage_type', RecordingWebhookDelivery::STORAGE_LOCAL);
            })
            ->get(self::CDR_COLUMNS);

        foreach ($this->primaryLegs($cdrs) as $cdr) {
            if ($this->claim($domainUuid, $cdr, $config, $event)) {
                $count++;
            }
        }

        return $count;
    }

    private function candidateCdrs(string $domainUuid, array $config, $lookback, string $event)
    {
        return CDR::query()
            ->where('domain_uuid', $domainUuid)
            ->whereNotNull('record_name')
            ->where('record_name', '!=', '')
            ->whereIn('direction', $config['directions'])
            ->where('start_stamp', '>=', $lookback)
            ->whereNotNull('end_stamp')
            ->whereNotExists(function ($query) use ($event) {
                $query->selectRaw('1')
                    ->from('recording_webhook_deliveries')
                    ->whereColumn('recording_webhook_deliveries.domain_uuid', 'v_xml_cdr.domain_uuid')
                    ->whereColumn('recording_webhook_deliveries.record_name', 'v_xml_cdr.record_name')
                    ->where('recording_webhook_deliveries.event', $event);
            })
            ->orderBy('start_stamp');
    }

    /**
     * Ring group / transfer legs share one recording file — send one webhook
     * per file, under the leg that recorded it when that leg is among them.
     */
    private function primaryLegs($cdrs)
    {
        return $cdrs->groupBy('record_name')->map(function ($legs, $recordName) {
            $recordedUuid = self::recordedLegUuid((string) $recordName);

            return $legs->first(fn ($leg) => strtolower((string) $leg->xml_cdr_uuid) === $recordedUuid)
                ?? self::longestLeg($legs);
        });
    }

    /**
     * The CDR to announce a recording.available under, or null to wait a
     * minute. The recorded leg's own CDR can lack record_name (fspbx only
     * keeps it on some legs) or sit outside this domain's candidates, so it
     * is looked up by uuid; when it has no recording of its own it adopts
     * this file in memory, and dispatchAvailable() persists that once it
     * has decided to send, so the send job (which reads the file through
     * the CDR it is given) finds it.
     */
    private function announcingLeg(string $recordName, $legs): ?CDR
    {
        $recordedUuid = self::recordedLegUuid($recordName);
        $recordedLeg = null;

        if ($recordedUuid && !$legs->contains(fn ($leg) => strtolower((string) $leg->xml_cdr_uuid) === $recordedUuid)) {
            $recordedLeg = CDR::query()
                ->where('xml_cdr_uuid', $recordedUuid)
                ->whereNotNull('end_stamp')
                ->first(self::CDR_COLUMNS);

            if ($recordedLeg && ($recordedLeg->record_name ?? '') === '') {
                $recordedLeg->record_path = $legs->first()->record_path;
                $recordedLeg->record_name = $recordName;
            } elseif ($recordedLeg && $recordedLeg->record_name !== $recordName) {
                // it has a recording of its own; this file belongs to the siblings
                $recordedLeg = null;
                $recordedUuid = null;
            }
        }

        return self::selectLeg($recordName, $legs, $recordedLeg, now(), self::RECORDED_LEG_GRACE_MINUTES, $recordedUuid === null);
    }

    /**
     * Pure leg choice (unit-tested): the recorded leg — the one whose uuid
     * names the file — wins; while it is still on the call (no CDR yet) wait,
     * up to $graceMinutes from the first leg's start; files not named after
     * a uuid, and calls past the grace, fall back to the longest leg.
     *
     * @param  iterable<object>  $legs  ended CDRs sharing $recordName
     */
    public static function selectLeg(
        string $recordName,
        $legs,
        ?object $recordedLeg,
        Carbon $now,
        int $graceMinutes,
        bool $ignoreRecordedLeg = false
    ): ?object {
        $legs = collect($legs);
        $recordedUuid = $ignoreRecordedLeg ? null : self::recordedLegUuid($recordName);

        if ($recordedUuid === null) {
            return self::longestLeg($legs);
        }

        $own = $legs->first(fn ($leg) => strtolower((string) $leg->xml_cdr_uuid) === $recordedUuid);
        if ($own) {
            return $own;
        }

        if ($recordedLeg && strtolower((string) $recordedLeg->xml_cdr_uuid) === $recordedUuid) {
            return $recordedLeg;
        }

        $firstStart = $legs->min('start_stamp');
        if ($firstStart && Carbon::parse($firstStart)->addMinutes($graceMinutes)->greaterThan($now)) {
            return null;
        }

        return self::longestLeg($legs);
    }

    private static function longestLeg($legs): ?object
    {
        return collect($legs)->sortBy('xml_cdr_uuid')->sortByDesc('billsec')->first();
    }

    /**
     * The uuid a recording file is named after (`<uuid>.wav`, or an S3 key
     * ending in it), or null for any other name.
     */
    public static function recordedLegUuid(?string $recordName): ?string
    {
        $stem = pathinfo(basename((string) $recordName), PATHINFO_FILENAME);

        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $stem)
            ? strtolower($stem)
            : null;
    }

    /**
     * insertOrIgnore + unique(domain_uuid, record_name, event) is the atomic
     * claim: whichever cluster node inserts the row sends the webhook.
     */
    private function claim(string $domainUuid, $cdr, array $config, string $event): bool
    {
        // The configured domain, not the CDR's: the recorded leg looked up by
        // uuid can carry another domain_uuid (WhatsApp calls authenticate
        // against the node-hostname directory domain).
        $inserted = RecordingWebhookDelivery::insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'domain_uuid' => $domainUuid,
            'xml_cdr_uuid' => $cdr->xml_cdr_uuid,
            'record_name' => $cdr->record_name,
            'event' => $event,
            'url' => $config['url'],
            'status' => RecordingWebhookDelivery::STATUS_PENDING,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($inserted !== 1) {
            return false;
        }

        $delivery = RecordingWebhookDelivery::where('domain_uuid', $domainUuid)
            ->where('record_name', $cdr->record_name)
            ->where('event', $event)
            ->first();

        SendRecordingWebhook::dispatch($delivery->uuid);

        return true;
    }

    /**
     * Whether this node can serve the CDR's recording. S3-backed recordings
     * are reachable from any node (presigned object URLs); local recordings
     * only from the node whose disk holds the file.
     */
    private function recordingIsServableHere($cdr): bool
    {
        if ($cdr->record_path === 'S3') {
            return true;
        }

        $dir = rtrim($cdr->record_path ?: '', '/');

        return $dir !== '' && is_file($dir . '/' . $cdr->record_name);
    }

    private function retryFailed(array $domainUuids): void
    {
        $failed = RecordingWebhookDelivery::whereIn('domain_uuid', $domainUuids)
            ->where('status', RecordingWebhookDelivery::STATUS_FAILED)
            ->get();

        foreach ($failed as $delivery) {
            $delivery->update([
                'status' => RecordingWebhookDelivery::STATUS_PENDING,
                'last_error' => null,
            ]);

            SendRecordingWebhook::dispatch($delivery->uuid);
            $this->info("Retrying failed delivery {$delivery->uuid}");
        }
    }
}
