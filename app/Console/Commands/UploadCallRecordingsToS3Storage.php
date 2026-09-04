<?php

namespace App\Console\Commands;

use App\Models\CDR;
use App\Models\DefaultSettings;
use Illuminate\Console\Command;
use App\Jobs\SendS3UploadReport;
use App\Services\RecordingArchiveService;
use App\Services\S3StorageConfigService;

/**
 * Nightly sweeper: archives any local recording in an archive-enabled domain
 * that the per-call queue (recordings:dispatch-archives → ArchiveRecordingToS3)
 * did not get to — backlog from before a tenant was enabled, jobs that
 * exhausted retries, files that were still being written at claim time.
 *
 * Only domains with s3_storage/enabled=true (effective) are considered; with
 * none enabled the run is a silent no-op — no failures, no report email.
 */
class UploadCallRecordingsToS3Storage extends Command
{
    protected $signature = 'fs:upload-call-recordings-to-s3-storage';

    protected $description = 'Upload archived call recordings to S3-compatible object storage';

    public function __construct(
        protected S3StorageConfigService $s3StorageConfigService,
        protected RecordingArchiveService $archiveService
    ) {
        parent::__construct();
    }

    public function handle()
    {
        $this->uploadRecordings();

        return 0;
    }

    public function uploadRecordings()
    {
        $targets = $this->s3StorageConfigService->getArchiveTargets();

        if (empty($targets)) {
            $this->info('No domains have S3 archiving enabled.');
            return;
        }

        $limit = $this->getUploadLimit();
        $recordingIds = $this->getCallRecordingIds(array_keys($targets), $limit);

        if (empty($recordingIds)) {
            $this->info('No recordings found for upload.');
            return;
        }

        $failed = [];
        $success = [];
        $timeZones = [];

        $this->processRecordingsInChunks($recordingIds, function ($rec) use (
            &$failed,
            &$success,
            &$timeZones,
            $targets
        ) {
            $settings = $targets[$rec->domain_uuid] ?? null;

            if (!$settings) {
                return; // domain disabled since selection
            }

            $timeZones[$rec->domain_uuid] ??= $this->archiveService->timeZoneForDomain($rec->domain_uuid);

            try {
                $result = $this->archiveService->archive($rec, $settings, $timeZones[$rec->domain_uuid]);
            } catch (\Throwable $ex) {
                logger($ex->getMessage());

                $failed[] = [
                    'msg' => $ex->getMessage(),
                    'name' => $rec->record_name,
                ];
                return;
            }

            if ($result['status'] === RecordingArchiveService::STATUS_MISSING) {
                $failed[] = [
                    'msg' => 'Recording file not found. DB entries cleared.',
                    'name' => $rec->record_name,
                ];
            } elseif ($result['status'] === RecordingArchiveService::STATUS_ARCHIVED) {
                $success[] = $rec->record_name . ' => ' . $result['object_key'];
            }
            // already_archived: a sibling leg's upload covered this file — nothing to report
        });

        $uploadNotificationEmail = DefaultSettings::where('default_setting_category', 's3_storage')
            ->where('default_setting_subcategory', 'upload_notification_email')
            ->where('default_setting_enabled', true)
            ->value('default_setting_value');

        if ($uploadNotificationEmail && ($failed || $success)) {
            SendS3UploadReport::dispatch([
                'email'   => $uploadNotificationEmail,
                'failed'  => $failed,
                'success' => $success,
            ])->onQueue('emails');
        }
    }

    protected function getUploadLimit(): int
    {
        $value = DefaultSettings::where('default_setting_category', 'scheduled_jobs')
            ->where('default_setting_subcategory', 's3_upload_limit')
            ->where('default_setting_enabled', true)
            ->value('default_setting_value');

        $limit = (int) $value;

        if ($limit <= 0) {
            $limit = 2000;
        }

        return min($limit, 20000);
    }

    protected function getCallRecordingIds(array $domainUuids, int $limit): array
    {
        $minimumAgeMinutes = 360;

        return CDR::query()
            ->whereIn('domain_uuid', $domainUuids)
            ->whereNotNull('record_name')
            ->where('record_name', '<>', '')
            ->whereNotNull('record_path')
            ->where('record_path', '<>', '')
            ->where('record_path', 'not like', '%S3%')
            ->where('record_path', 'not like', '%NFS%')
            ->where('hangup_cause', '<>', 'LOSE_RACE')
            ->where('start_stamp', '<=', now()->subMinutes($minimumAgeMinutes))
            ->orderBy('start_stamp', 'asc')
            ->limit($limit)
            ->pluck('xml_cdr_uuid')
            ->all();
    }

    protected function processRecordingsInChunks(array $ids, callable $callback): void
    {
        foreach (array_chunk($ids, 200) as $idChunk) {
            $recordings = CDR::select([
                'xml_cdr_uuid',
                'domain_uuid',
                'domain_name',
                'direction',
                'caller_id_number',
                'caller_destination',
                'start_stamp',
                'record_path',
                'record_name',
            ])
                ->whereIn('xml_cdr_uuid', $idChunk)
                ->orderBy('start_stamp', 'asc')
                ->get();

            foreach ($recordings as $rec) {
                // The file may live on the other node — the sweeper runs on one node only
                if ($rec->record_path !== 'S3') {
                    $dir = rtrim((string) $rec->record_path, '/');
                    if ($dir === '' || !is_file($dir . '/' . $rec->record_name)) {
                        $rec->refresh();
                        if ($rec->record_path !== 'S3') {
                            continue;
                        }
                    }
                }

                $callback($rec);
            }
        }
    }
}
