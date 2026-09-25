<?php

namespace App\Services\Voxra;

use App\Models\AiAgent;
use App\Models\Domain;
use App\Services\TelnyxConvaiService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Deletes Voxra call audio (voxragtm#83). The privacy policy keeps call audio
 * for 10 days (services.voxra.recording_retention_days); this service owns
 * every store that holds it:
 *
 *  - PBX call recordings: v_xml_cdr.record_path/record_name + the file under
 *    /var/lib/freeswitch/recordings/<domain>/… (the CDR row itself is a
 *    billing record and is kept, with its recording reference cleared);
 *  - PBX voicemail: v_voicemail_messages rows + msg_<uuid>.{wav,mp3} under
 *    /var/lib/freeswitch/storage/voicemail/default/<domain>/<box>/ (the row
 *    also holds the transcription and any base64 audio);
 *  - Telnyx: AI call recordings and the AI conversation copies (transcripts)
 *    for the tenant's reception assistant. voxraweb copies the transcript into
 *    its own store within hours of the call (transcripts backfill cron) and
 *    keeps it under the account-lifetime rule, so the Telnyx copy (held in
 *    the US, voxragtm#131) goes with the audio at the same 10 days.
 *
 * Scope is Voxra customers only (domain_description "voxra-tenant:<id>", plus
 * services.voxra.retention_extra_domains / _assistants for Voxra's own lines)
 * — other domains on these PBXs (iqmobile.uk, tel.et, reseller domains, and
 * the lon1/eu1.voxra.uk WhatsApp Business Calling realms that carry IQ
 * Mobile's WhatsApp calls) have their own policies. Nothing under a non-Voxra
 * domain's recordings directory is ever deleted, even when a Voxra CDR
 * points there; such rows are skipped and counted, not treated as errors.
 *
 * Three modes:
 *  - age sweep (daily `voxra:purge-media`): everything older than N days;
 *  - caller (`scope=caller`): one caller's media for a tenant, any age;
 *  - all (`scope=all`): every item for a tenant, any age (account erasure).
 * Every mode is idempotent, batch-limited and supports dry-run.
 */
class VoxraMediaPurgeService
{
    public const RECORDINGS_ROOT = '/var/lib/freeswitch/recordings';
    public const VOICEMAIL_ROOT = '/var/lib/freeswitch/storage/voicemail/default';

    /**
     * Domain descriptions that mark a PBX domain as not a Voxra customer's.
     * These are refused even if listed in retention_extra_domains: the
     * WhatsApp Business Calling digest realms (lon1/eu1.voxra.uk, IQ Mobile's
     * and Aerix's WhatsApp numbers) and iqportal reseller domains.
     */
    public const NON_VOXRA_DESCRIPTIONS = ['WhatsApp Business Calling%', 'reseller:%'];

    private string $recordingsRoot = self::RECORDINGS_ROOT;
    private string $voicemailRoot = self::VOICEMAIL_ROOT;

    private array $log = [];

    /** Every Voxra domain (all tenants + Voxra's own lines), for path/CDR ownership checks. */
    private array $scopeNames = [];
    private array $scopeUuids = [];

    public function __construct(private ?TelnyxConvaiService $telnyx = null)
    {
    }

    /** Point the service at other storage roots (tests). */
    public function withRoots(string $recordings, string $voicemail): static
    {
        $this->recordingsRoot = rtrim($recordings, '/');
        $this->voicemailRoot = rtrim($voicemail, '/');

        return $this;
    }

    /** Voxra tenant domains (optionally one tenant's). */
    public function domains(?string $tenantId = null): array
    {
        if ($tenantId !== null) {
            return Domain::where('domain_description', 'voxra-tenant:' . $tenantId)->get()->all();
        }
        $extra = self::csv((string) config('services.voxra.retention_extra_domains', ''));

        return Domain::where(fn ($q) => $q
                ->where('domain_description', 'like', 'voxra-tenant:%')
                ->when($extra !== [], fn ($w) => $w->orWhere(fn ($x) => $x
                    ->whereIn('domain_name', $extra)
                    ->where(fn ($y) => $y
                        ->whereNull('domain_description')
                        ->orWhere(function ($z) {
                            foreach (self::NON_VOXRA_DESCRIPTIONS as $pattern) {
                                $z->where('domain_description', 'not like', $pattern);
                            }
                        })))))
            ->get()
            ->all();
    }

    /** Telnyx assistant ids for the given domains (+ Voxra's own lines on a sweep). */
    public function assistantIds(array $domains, bool $includeExtra): array
    {
        $uuids = array_map(fn ($d) => $d->domain_uuid, $domains);
        $ids = $uuids === [] ? [] : AiAgent::whereIn('domain_uuid', $uuids)
            ->whereNotNull('telnyx_assistant_id')
            ->pluck('telnyx_assistant_id')
            ->all();
        if ($includeExtra) {
            $ids = array_merge($ids, self::csv((string) config('services.voxra.retention_extra_assistants', '')));
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /**
     * Run a purge. $opts: days (age sweep), tenant_id, scope (age|caller|all),
     * caller_numbers, dry_run, limit (max items per store), telnyx (bool).
     *
     * @return array{counts: array<string,int>, by_domain: array<string,array<string,int>>, log: string[]}
     */
    public function purge(array $opts): array
    {
        $this->log = [];
        $scope = $opts['scope'] ?? 'age';
        $dry = (bool) ($opts['dry_run'] ?? false);
        $limit = max(1, (int) ($opts['limit'] ?? 500));
        $cutoff = $scope === 'age' ? Carbon::now()->subDays(max(1, (int) ($opts['days'] ?? config('services.voxra.recording_retention_days', 10)))) : null;
        $numbers = $scope === 'caller' ? self::numberVariants((array) ($opts['caller_numbers'] ?? [])) : [];
        if ($scope === 'caller' && $numbers === []) {
            return ['counts' => [], 'by_domain' => [], 'log' => ['caller scope with no caller numbers — nothing to do']];
        }

        $domains = $this->domains($opts['tenant_id'] ?? null);
        $all = $this->domains();
        $this->scopeNames = array_map(fn ($d) => $d->domain_name, $all);
        $this->scopeUuids = array_map(fn ($d) => $d->domain_uuid, $all) ?: ['00000000-0000-0000-0000-000000000000'];
        $byDomain = [];
        $counts = [
            'domains' => count($domains),
            'pbx_recordings' => 0,
            'pbx_recording_files_orphaned' => 0,
            'pbx_voicemails' => 0,
            'pbx_recordings_skipped_non_voxra_dir' => 0,
            'telnyx_conversations' => 0,
            'telnyx_recordings' => 0,
            'errors' => 0,
        ];

        foreach ($domains as $domain) {
            $cdr = $this->purgeCdrRecordings($domain, $cutoff, $numbers, $dry, $limit, $counts);
            // Stray files only once the CDR-linked ones are done, so a file a
            // CDR still points at is always removed through its CDR first.
            $orphans = ($scope === 'age' || $scope === 'all') && $cdr < $limit
                ? $this->purgeOrphanRecordingFiles($domain, $cutoff ?? Carbon::now(), $dry, $limit)
                : 0;
            $vm = $this->purgeVoicemails($domain, $cutoff, $numbers, $dry, $limit, $counts);
            $counts['pbx_recordings'] += $cdr;
            $counts['pbx_recording_files_orphaned'] += $orphans;
            $counts['pbx_voicemails'] += $vm;
            $byDomain[$domain->domain_name] = ['pbx_recordings' => $cdr, 'pbx_recording_files_orphaned' => $orphans, 'pbx_voicemails' => $vm];
            $this->note(sprintf('domain %s: recordings=%d orphan_files=%d voicemails=%d', $domain->domain_name, $cdr, $orphans, $vm));
        }

        if (($opts['telnyx'] ?? true) && $this->telnyx) {
            $assistants = $this->assistantIds($domains, ($opts['tenant_id'] ?? null) === null);
            try {
                [$c, $r] = $this->purgeTelnyx($assistants, $cutoff, $numbers, $dry, $limit);
                $counts['telnyx_conversations'] += $c;
                $counts['telnyx_recordings'] += $r;
            } catch (Throwable $e) {
                $counts['errors']++;
                $this->note('telnyx: ' . $e->getMessage());
            }
        }

        $summary = sprintf(
            'voxra purge-media scope=%s%s%s: %s',
            $scope,
            $cutoff ? ' before=' . $cutoff->toIso8601String() : '',
            $dry ? ' (dry run)' : '',
            json_encode($counts)
        );
        logger()->info($summary);
        $this->note($summary);

        return ['counts' => $counts, 'by_domain' => $byDomain, 'log' => $this->log];
    }

    private function purgeCdrRecordings(Domain $domain, ?Carbon $cutoff, array $numbers, bool $dry, int $limit, array &$counts): int
    {
        $rows = DB::table('v_xml_cdr')
            ->where('domain_uuid', $domain->domain_uuid)
            ->whereNotNull('record_name')
            ->where('record_name', '!=', '')
            ->when($cutoff, fn ($q) => $q->where('start_stamp', '<', $cutoff))
            ->when($numbers !== [], fn ($q) => $q->where(fn ($w) => $w
                ->whereIn('caller_id_number', $numbers)
                ->orWhereIn('destination_number', $numbers)
                ->orWhereIn('caller_destination', $numbers)))
            ->orderBy('start_stamp')
            ->limit($limit)
            ->get(['xml_cdr_uuid', 'record_path', 'record_name']);

        $n = 0;
        foreach ($rows as $row) {
            $path = rtrim((string) $row->record_path, '/') . '/' . $row->record_name;
            if (str_contains((string) $row->record_path, 'S3')) {
                // Voxra tenants don't archive to S3; flag it rather than guess.
                $this->note("skip S3-archived recording {$row->record_name} ({$domain->domain_name})");
                $counts['errors']++;
                continue;
            }
            if (!$this->insideRoot($path, $this->recordingsRoot . '/' . $domain->domain_name)) {
                // A Voxra CDR whose file sits under another domain's
                // directory. Only delete when that directory is itself a
                // Voxra domain's and no non-Voxra CDR points at the file;
                // never touch other customers' trees (skipped, not an error,
                // so the nightly run stays green).
                if (!$this->insideVoxraRoot($path) || $this->referencedOutsideVoxra((string) $row->record_name)) {
                    $this->note("skip recording in a non-Voxra directory: {$path}");
                    $counts['pbx_recordings_skipped_non_voxra_dir']++;
                    continue;
                }
            }
            if (!$dry) {
                $this->unlinkVariants($path);
                DB::table('v_xml_cdr')->where('xml_cdr_uuid', $row->xml_cdr_uuid)
                    ->update(['record_path' => null, 'record_name' => null]);
            }
            $this->note(($dry ? 'would delete' : 'deleted') . " recording {$domain->domain_name}/{$row->record_name}");
            $n++;
        }

        return $n;
    }

    /** Is $path inside a recordings directory of an in-scope (Voxra) domain? */
    private function insideVoxraRoot(string $path): bool
    {
        foreach ($this->scopeNames as $name) {
            if ($this->insideRoot($path, $this->recordingsRoot . '/' . $name)) {
                return true;
            }
        }

        return false;
    }

    /** Does a CDR of a domain outside the Voxra scope reference this recording? */
    private function referencedOutsideVoxra(string $recordName): bool
    {
        return DB::table('v_xml_cdr')
            ->where('record_name', $recordName)
            ->whereNotIn('domain_uuid', $this->scopeUuids)
            ->exists();
    }

    /** Files on disk older than the cutoff with no CDR pointing at them. */
    private function purgeOrphanRecordingFiles(Domain $domain, Carbon $cutoff, bool $dry, int $limit): int
    {
        $root = $this->recordingsRoot . '/' . $domain->domain_name;
        if (!is_dir($root)) {
            return 0;
        }
        $n = 0;
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if ($n >= $limit) {
                break;
            }
            if (!$file->isFile() || !preg_match('/\.(wav|mp3)$/i', $file->getFilename())) {
                continue;
            }
            if ($file->getMTime() >= $cutoff->getTimestamp()) {
                continue;
            }
            if ($this->referencedOutsideVoxra($file->getFilename())) {
                // Filed under a Voxra directory but a non-Voxra CDR owns it.
                $this->note('skip file referenced by a non-Voxra CDR: ' . $file->getPathname());
                continue;
            }
            if (!$dry) {
                @unlink($file->getPathname());
            }
            $this->note(($dry ? 'would delete' : 'deleted') . ' old recording file ' . $file->getPathname());
            $n++;
        }

        return $n;
    }

    private function purgeVoicemails(Domain $domain, ?Carbon $cutoff, array $numbers, bool $dry, int $limit, array &$counts): int
    {
        $rows = DB::table('v_voicemail_messages as m')
            ->join('v_voicemails as v', 'v.voicemail_uuid', '=', 'm.voicemail_uuid')
            ->where('m.domain_uuid', $domain->domain_uuid)
            ->when($cutoff, fn ($q) => $q->where('m.created_epoch', '<', $cutoff->getTimestamp()))
            ->when($numbers !== [], fn ($q) => $q->whereIn('m.caller_id_number', $numbers))
            ->orderBy('m.created_epoch')
            ->limit($limit)
            ->get(['m.voicemail_message_uuid', 'v.voicemail_id']);

        $n = 0;
        foreach ($rows as $row) {
            $dir = $this->voicemailRoot . '/' . $domain->domain_name . '/' . $row->voicemail_id;
            if (!$dry) {
                $this->unlinkVariants($dir . '/msg_' . $row->voicemail_message_uuid . '.wav');
                @unlink($dir . '/intro_msg_' . $row->voicemail_message_uuid . '.wav');
                @unlink($dir . '/intro_msg_' . $row->voicemail_message_uuid . '.mp3');
                DB::table('v_voicemail_messages')->where('voicemail_message_uuid', $row->voicemail_message_uuid)->delete();
            }
            $this->note(($dry ? 'would delete' : 'deleted') . " voicemail {$domain->domain_name}/{$row->voicemail_id}/{$row->voicemail_message_uuid}");
            $n++;
        }

        return $n;
    }

    /** @return array{0:int,1:int} conversations, recordings */
    public function purgeTelnyx(array $assistants, ?Carbon $cutoff, array $numbers, bool $dry, int $limit): array
    {
        if ($assistants === []) {
            return [0, 0];
        }
        $convs = 0;
        $recs = 0;

        // Conversations (+ each one's recording, matched by call leg).
        foreach ($assistants as $assistant) {
            $filterSets = [];
            if ($numbers !== []) {
                foreach ($numbers as $num) {
                    $filterSets[] = ['metadata->assistant_id' => 'eq.' . $assistant, 'metadata->telnyx_end_user_target' => 'eq.' . $num];
                }
            } else {
                $filterSets[] = array_filter([
                    'metadata->assistant_id' => 'eq.' . $assistant,
                    'created_at' => $cutoff ? 'lt.' . $cutoff->copy()->utc()->format('Y-m-d\TH:i:s\Z') : null,
                ]);
            }
            foreach ($filterSets as $filters) {
                foreach ($this->telnyx->listConversations($filters, min(100, $limit)) as $conv) {
                    if ($convs >= $limit) {
                        break 3;
                    }
                    $leg = (string) ($conv['metadata']['call_leg_id'] ?? '');
                    if ($leg !== '') {
                        foreach ($this->telnyx->listRecordings(['filter[call_leg_id]' => $leg])['data'] as $rec) {
                            if (!$dry) {
                                $this->telnyx->deleteRecording((string) $rec['id']);
                            }
                            $recs++;
                        }
                    }
                    if (!$dry) {
                        $this->telnyx->deleteConversation((string) $conv['id']);
                    }
                    $this->note(($dry ? 'would delete' : 'deleted') . " telnyx conversation {$conv['id']} ({$assistant})");
                    $convs++;
                }
            }
        }

        // Recordings without a conversation: sweep by age / caller, matched to
        // our assistants by the SIP target (…@assistant-<id>.sip.telnyx.com).
        $filters = [];
        if ($cutoff) {
            $filters['filter[created_at][lte]'] = $cutoff->copy()->utc()->format('Y-m-d\TH:i:s\Z');
        }
        $numberFilters = $numbers !== [] ? array_map(fn ($n) => ['filter[from]' => $n], $numbers) : [[]];
        foreach ($numberFilters as $nf) {
            $page = 1;
            do {
                $res = $this->telnyx->listRecordings(array_merge($filters, $nf), $page);
                foreach ($res['data'] as $rec) {
                    if ($recs >= $limit) {
                        break 3;
                    }
                    $to = (string) ($rec['to'] ?? '');
                    $ours = false;
                    foreach ($assistants as $assistant) {
                        if ($to !== '' && str_contains($to, $assistant)) {
                            $ours = true;
                            break;
                        }
                    }
                    if (!$ours) {
                        continue;
                    }
                    if (!$dry) {
                        $this->telnyx->deleteRecording((string) $rec['id']);
                    }
                    $this->note(($dry ? 'would delete' : 'deleted') . " telnyx recording {$rec['id']} ({$to})");
                    $recs++;
                }
                $page++;
            } while ($page <= $res['total_pages'] && $page <= 20);
        }

        return [$convs, $recs];
    }

    private function unlinkVariants(string $path): void
    {
        $base = preg_replace('/\.(wav|mp3)$/i', '', $path);
        foreach ([$path, $base . '.wav', $base . '.mp3'] as $p) {
            if (is_file($p)) {
                @unlink($p);
            }
        }
    }

    private function insideRoot(string $path, string $root): bool
    {
        $norm = preg_replace('#/+#', '/', $path);

        return str_starts_with($norm, rtrim($root, '/') . '/') && !str_contains($norm, '/../');
    }

    private function note(string $line): void
    {
        $this->log[] = $line;
    }

    /** UK number forms stored on the PBX / Telnyx: +447…, 447…, 07…. */
    public static function numberVariants(array $raw): array
    {
        $out = [];
        foreach ($raw as $r) {
            $n = preg_replace('/[\s()\-]/', '', (string) $r);
            if ($n === '') {
                continue;
            }
            $out[] = $n;
            $d = ltrim($n, '+');
            if (ctype_digit($d)) {
                $out[] = $d;
                $out[] = '+' . $d;
                if (str_starts_with($d, '44')) {
                    $out[] = '0' . substr($d, 2);
                }
                if (str_starts_with($d, '0')) {
                    $out[] = '44' . substr($d, 1);
                    $out[] = '+44' . substr($d, 1);
                }
            }
        }

        return array_values(array_unique($out));
    }

    private static function csv(string $v): array
    {
        return array_values(array_filter(array_map('trim', explode(',', $v))));
    }
}
