<?php

namespace App\Services;

use App\Models\CDR;
use Aws\S3\S3Client;
use Carbon\Carbon;
use App\Models\DefaultSettings;
use App\Models\DomainSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Models\RecordingWebhookDelivery;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;

/**
 * Moves one call recording file from local disk to the domain's S3-compatible
 * bucket: converts WAV → MP3, uploads, verifies size, repoints every CDR that
 * shares the file (ring-group legs) at record_path='S3' / record_name=<key>,
 * keeps recording_webhook_deliveries in step with the rename, and deletes the
 * local copy.
 *
 * Shared by the per-call queue job (ArchiveRecordingToS3) and the nightly
 * sweeper (fs:upload-call-recordings-to-s3-storage).
 */
class RecordingArchiveService
{
    const STATUS_ARCHIVED = 'archived';
    const STATUS_ALREADY_ARCHIVED = 'already_archived';
    const STATUS_MISSING = 'missing';

    /** @var array<string, S3Client> */
    protected array $clients = [];

    public function __construct(
        protected S3StorageConfigService $s3StorageConfigService
    ) {
    }

    /**
     * @return array{status: string, object_key: ?string, bucket: ?string}
     * @throws \RuntimeException on conversion/upload/verification failure
     */
    public function archive(CDR $rec, array $settings, ?string $timeZone = null): array
    {
        $originalRecordPath = $rec->record_path;
        $originalRecordName = $rec->record_name;
        $recordingFile = rtrim((string) $originalRecordPath, '/') . '/' . $originalRecordName;

        if ($originalRecordPath === 'S3') {
            return $this->result(self::STATUS_ALREADY_ARCHIVED, $originalRecordName, $settings['bucket']);
        }

        // The file can vanish between selection and processing when a sibling
        // CDR sharing it was archived moments ago; re-read before declaring it lost.
        if (!file_exists($recordingFile)) {
            $rec->refresh();

            if (str_contains((string) $rec->record_path, 'S3')) {
                return $this->result(self::STATUS_ALREADY_ARCHIVED, $rec->record_name, $settings['bucket']);
            }

            CDR::where('xml_cdr_uuid', $rec->xml_cdr_uuid)
                ->update(['record_path' => null, 'record_name' => null]);

            return $this->result(self::STATUS_MISSING, null, null);
        }

        $timeZone = $timeZone ?? $this->timeZoneForDomain($rec->domain_uuid);
        $s3 = $this->clientFor($settings);

        $mp3File = $this->convertToMp3IfNeeded($recordingFile);

        if (!$mp3File || !file_exists($mp3File)) {
            throw new \RuntimeException('MP3 conversion failed or file missing: ' . $recordingFile);
        }

        $objectKey = $this->buildObjectKey($rec, $settings, $mp3File, $timeZone);

        Log::info('Archiving recording ' . $mp3File . ' -> s3://' . $settings['bucket'] . '/' . $objectKey);

        $localSize = filesize($mp3File);

        $s3->putObject([
            'Bucket'     => $settings['bucket'],
            'SourceFile' => $mp3File,
            'Key'        => $objectKey,
        ]);

        $head = $s3->headObject([
            'Bucket' => $settings['bucket'],
            'Key'    => $objectKey,
        ]);

        $remoteSize = (int) ($head['ContentLength'] ?? 0);

        if ($remoteSize !== (int) $localSize) {
            throw new \RuntimeException(
                "Upload verification failed (size mismatch). Local={$localSize}, Remote={$remoteSize}"
            );
        }

        $recordingStart = Carbon::parse($rec->start_stamp);

        DB::transaction(function () use ($rec, $originalRecordName, $originalRecordPath, $recordingStart, $objectKey) {
            // Every CDR leg sharing this file — ring group / transfer legs
            CDR::where('record_name', $originalRecordName)
                ->where('record_path', $originalRecordPath)
                ->whereBetween('start_stamp', [
                    $recordingStart->copy()->subDay(),
                    $recordingStart->copy()->addDay(),
                ])
                ->update([
                    'record_path' => 'S3',
                    'record_name' => $objectKey,
                ]);

            // The webhook dispatcher dedupes on (domain_uuid, record_name); keep
            // the claim row pointing at the renamed file or the same recording
            // would be re-announced under its new name.
            RecordingWebhookDelivery::where('domain_uuid', $rec->domain_uuid)
                ->where('record_name', $originalRecordName)
                ->update(['record_name' => $objectKey]);
        });

        if ($mp3File !== $recordingFile && file_exists($mp3File)) {
            unlink($mp3File);
        }

        if (strtolower(pathinfo($recordingFile, PATHINFO_EXTENSION)) === 'wav' && file_exists($recordingFile)) {
            unlink($recordingFile);
        }

        return $this->result(self::STATUS_ARCHIVED, $objectKey, $settings['bucket']);
    }

    public function timeZoneForDomain(?string $domainUuid): string
    {
        $tz = null;

        if ($domainUuid) {
            $tz = DomainSettings::where('domain_uuid', $domainUuid)
                ->where('domain_setting_category', 'domain')
                ->where('domain_setting_subcategory', 'time_zone')
                ->where('domain_setting_enabled', true)
                ->value('domain_setting_value');
        }

        if (!$tz) {
            $tz = DefaultSettings::where('default_setting_category', 'domain')
                ->where('default_setting_subcategory', 'time_zone')
                ->where('default_setting_enabled', true)
                ->value('default_setting_value');
        }

        return $tz ?: 'UTC';
    }

    /**
     * Object key layout:
     *   custom (tenant-owned bucket): recordings/YYYY/MM/DD/HHMMSS_<direction>_<from>_<to>.mp3
     *   default (shared bucket):      <domain_name>/YYYY/MM/DD/HHMMSS_<direction>_<from>_<to>.mp3
     */
    public function buildObjectKey($rec, array $settings, string $filePath, string $timeZone = 'UTC'): string
    {
        $start = Carbon::parse($rec->start_stamp)->setTimezone($timeZone);

        if (($settings['type'] ?? 'default') === 'default') {
            $base = $rec->domain_name . '/'
                . $start->format('Y') . '/'
                . $start->format('m') . '/'
                . $start->format('d') . '/';
        } else {
            $base = 'recordings/'
                . $start->format('Y') . '/'
                . $start->format('m') . '/'
                . $start->format('d') . '/';
        }

        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        return $base
            . $start->format('His')
            . '_' . $this->sanitizePathSegment($rec->direction)
            . '_' . $this->sanitizePathSegment($rec->caller_id_number)
            . '_' . $this->sanitizePathSegment($rec->caller_destination)
            . '.' . $ext;
    }

    public function sanitizePathSegment($value): string
    {
        $value = (string) $value;
        $value = preg_replace('/[^\w\-\+\.]/', '_', $value);

        return trim($value, '_') ?: 'unknown';
    }

    protected function clientFor(array $settings): S3Client
    {
        $key = $this->s3StorageConfigService->getSettingsHash($settings);

        if (!isset($this->clients[$key])) {
            $this->clients[$key] = $this->s3StorageConfigService->buildClientFromSettings($settings);
        }

        return $this->clients[$key];
    }

    protected function convertToMp3IfNeeded(string $recordingFile): ?string
    {
        $ext = strtolower(pathinfo($recordingFile, PATHINFO_EXTENSION));

        if ($ext !== 'wav') {
            return $recordingFile;
        }

        $mp3File = preg_replace('/\.wav$/i', '.mp3', $recordingFile);

        $process = new Process([
            'ffmpeg',
            '-nostdin',
            '-y',
            '-i', $recordingFile,
            '-b:a', '16k',
            '-ac', '1',
            '-q:a', '5',
            $mp3File,
        ]);

        $process->setTimeout(7200);

        try {
            $process->mustRun();
            return $mp3File;
        } catch (ProcessTimedOutException $e) {
            logger('FFmpeg timed out for file: ' . $recordingFile . '. Error: ' . $e->getMessage());
            return null;
        } catch (ProcessFailedException $e) {
            logger($e->getMessage());
            return null;
        }
    }

    private function result(string $status, ?string $objectKey, ?string $bucket): array
    {
        return [
            'status' => $status,
            'object_key' => $objectKey,
            'bucket' => $bucket,
        ];
    }
}
