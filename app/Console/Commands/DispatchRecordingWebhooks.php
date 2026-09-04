<?php

namespace App\Console\Commands;

use App\Models\CDR;
use Illuminate\Support\Str;
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

        $cdrs = $this->candidateCdrs($domainUuid, $config, $lookback, $event)->get([
            'xml_cdr_uuid',
            'domain_uuid',
            'record_path',
            'record_name',
            'direction',
            'billsec',
            'start_stamp',
        ]);

        foreach ($this->primaryLegs($cdrs) as $cdr) {
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

            if ($this->claim($cdr, $config, $event)) {
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
            ->get([
                'xml_cdr_uuid',
                'domain_uuid',
                'record_path',
                'record_name',
                'direction',
                'billsec',
                'start_stamp',
            ]);

        foreach ($this->primaryLegs($cdrs) as $cdr) {
            if ($this->claim($cdr, $config, $event)) {
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
     * per file, using the leg that carried the conversation.
     */
    private function primaryLegs($cdrs)
    {
        return $cdrs->groupBy('record_name')->map(function ($legs) {
            return $legs->sortBy('xml_cdr_uuid')->sortByDesc('billsec')->first();
        });
    }

    /**
     * insertOrIgnore + unique(domain_uuid, record_name, event) is the atomic
     * claim: whichever cluster node inserts the row sends the webhook.
     */
    private function claim($cdr, array $config, string $event): bool
    {
        $inserted = RecordingWebhookDelivery::insertOrIgnore([
            'uuid' => (string) Str::uuid(),
            'domain_uuid' => $cdr->domain_uuid,
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

        $delivery = RecordingWebhookDelivery::where('domain_uuid', $cdr->domain_uuid)
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
