<?php

namespace App\Console\Commands;

use App\Models\CDR;
use Illuminate\Support\Str;
use Illuminate\Console\Command;
use App\Models\RecordingArchive;
use App\Jobs\ArchiveRecordingToS3;
use App\Services\S3StorageConfigService;

/**
 * Minute-cron: queue an S3 archive job for every fresh local recording in a
 * domain that has archiving enabled. Companion to webhooks:dispatch-recordings
 * and built the same way — an insertOrIgnore claim on
 * unique(domain_uuid, record_name) so both cluster nodes can run it.
 */
class DispatchRecordingArchives extends Command
{
    protected $signature = 'recordings:dispatch-archives
        {--lookback-hours=24 : Only consider CDRs that started within this window}
        {--retry-failed : Re-queue archives that previously failed}';

    protected $description = 'Queue S3 archive jobs for new call recordings in archive-enabled domains';

    public function handle(S3StorageConfigService $configService)
    {
        $targets = $configService->getArchiveTargets();

        if (empty($targets)) {
            return Command::SUCCESS;
        }

        if ($this->option('retry-failed')) {
            $this->retryFailed(array_keys($targets));
        }

        $lookback = now()->subHours(max(1, (int) $this->option('lookback-hours')));
        $dispatched = 0;

        foreach (array_keys($targets) as $domainUuid) {
            $cdrs = CDR::query()
                ->where('domain_uuid', $domainUuid)
                ->whereNotNull('record_name')
                ->where('record_name', '!=', '')
                ->whereNotNull('record_path')
                ->where('record_path', '!=', '')
                ->where('record_path', 'not like', '%S3%')
                ->where('record_path', 'not like', '%NFS%')
                ->where('hangup_cause', '<>', 'LOSE_RACE')
                ->where('start_stamp', '>=', $lookback)
                ->whereNotNull('end_stamp')
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('recording_archives')
                        ->whereColumn('recording_archives.domain_uuid', 'v_xml_cdr.domain_uuid')
                        ->whereColumn('recording_archives.record_name', 'v_xml_cdr.record_name');
                })
                ->orderBy('start_stamp')
                ->get(['xml_cdr_uuid', 'domain_uuid', 'record_path', 'record_name', 'billsec']);

            // One archive per file; legs sharing it are repointed by the service
            $primaryLegs = $cdrs->groupBy('record_name')->map(function ($legs) {
                return $legs->sortByDesc('billsec')->first();
            });

            foreach ($primaryLegs as $cdr) {
                $dir = rtrim((string) $cdr->record_path, '/');
                if ($dir === '' || !is_file($dir . '/' . $cdr->record_name)) {
                    // Other node's file — leave unclaimed for it
                    continue;
                }

                $inserted = RecordingArchive::insertOrIgnore([
                    'uuid' => (string) Str::uuid(),
                    'domain_uuid' => $cdr->domain_uuid,
                    'xml_cdr_uuid' => $cdr->xml_cdr_uuid,
                    'record_name' => $cdr->record_name,
                    'status' => RecordingArchive::STATUS_PENDING,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($inserted === 1) {
                    $archive = RecordingArchive::where('domain_uuid', $cdr->domain_uuid)
                        ->where('record_name', $cdr->record_name)
                        ->first();

                    ArchiveRecordingToS3::dispatch($archive->uuid);
                    $dispatched++;
                }
            }
        }

        if ($dispatched > 0) {
            $this->info("Queued {$dispatched} recording archive job(s)");
        }

        return Command::SUCCESS;
    }

    private function retryFailed(array $domainUuids): void
    {
        $failed = RecordingArchive::whereIn('domain_uuid', $domainUuids)
            ->where('status', RecordingArchive::STATUS_FAILED)
            ->get();

        foreach ($failed as $archive) {
            $archive->update([
                'status' => RecordingArchive::STATUS_PENDING,
                'last_error' => null,
            ]);

            ArchiveRecordingToS3::dispatch($archive->uuid);
            $this->info("Retrying failed archive {$archive->uuid}");
        }
    }
}
