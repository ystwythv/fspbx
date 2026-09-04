<?php

namespace App\Jobs;

use App\Models\CDR;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Str;
use App\Models\RecordingArchive;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use App\Services\RecordingArchiveService;
use App\Services\S3StorageConfigService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Middleware\WithoutOverlapping;

/**
 * Per-call S3 archive (issue #106). Claimed by recordings:dispatch-archives on
 * the node that holds the file; Horizon/Redis is node-local so the job runs
 * there too.
 */
class ArchiveRecordingToS3 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public $backoff = [60, 300, 900, 1800];

    public $timeout = 1800;

    public function __construct(
        public string $archiveUuid
    ) {
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping($this->archiveUuid))->releaseAfter(60)->expireAfter(1900)];
    }

    public function handle(
        S3StorageConfigService $configService,
        RecordingArchiveService $archiveService
    ) {
        $archive = RecordingArchive::find($this->archiveUuid);

        if (!$archive || $archive->status === RecordingArchive::STATUS_ARCHIVED) {
            return;
        }

        $settings = $configService->getArchiveSettingsForDomain($archive->domain_uuid);

        if (!$settings) {
            $archive->update([
                'status' => RecordingArchive::STATUS_SKIPPED,
                'last_error' => 'S3 archive disabled or unconfigured for domain at run time',
            ]);
            return;
        }

        $cdr = CDR::query()
            ->select([
                'xml_cdr_uuid', 'domain_uuid', 'domain_name', 'direction',
                'caller_id_number', 'caller_destination', 'start_stamp',
                'record_path', 'record_name',
            ])
            ->where('xml_cdr_uuid', $archive->xml_cdr_uuid)
            ->first();

        if (!$cdr) {
            $archive->update([
                'status' => RecordingArchive::STATUS_FAILED,
                'last_error' => 'CDR no longer exists',
            ]);
            return;
        }

        if ($cdr->record_path !== 'S3') {
            $dir = rtrim((string) $cdr->record_path, '/');
            if ($dir === '' || !is_file($dir . '/' . $cdr->record_name)) {
                // Node-local queue: a retry here would never find the file.
                $archive->update([
                    'status' => RecordingArchive::STATUS_FAILED,
                    'last_error' => 'Recording file not present on this node (' . gethostname() . ')',
                ]);
                return;
            }
        }

        $archive->increment('attempts');

        $result = $archiveService->archive($cdr, $settings);

        if ($result['status'] === RecordingArchiveService::STATUS_MISSING) {
            $archive->update([
                'status' => RecordingArchive::STATUS_FAILED,
                'last_error' => 'Recording file not found; CDR record_name cleared',
            ]);
            return;
        }

        $archive->update([
            'status' => RecordingArchive::STATUS_ARCHIVED,
            'bucket' => $result['bucket'],
            'object_key' => $result['object_key'],
            'archived_at' => now(),
            'last_error' => null,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        RecordingArchive::where('uuid', $this->archiveUuid)
            ->update([
                'status' => RecordingArchive::STATUS_FAILED,
                'last_error' => Str::limit($exception->getMessage(), 500),
            ]);
    }
}
