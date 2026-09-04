<?php

namespace App\Services;

use App\Models\CDR;
use Illuminate\Support\Facades\URL;

class CallRecordingUrlService
{
    public function __construct(
        protected S3StorageConfigService $s3StorageConfigService
    ) {
    }

    /**
     * Return temporary URLs for a recording by CDR UUID.
     * - Local: returns signed routes to local stream/download endpoints.
     * - S3/S3-compatible: returns presigned object URLs.
     *
     * 'storage' describes where the object lives so a consumer that owns the
     * bucket can keep a durable pointer instead of a time-limited URL:
     *   ['type' => 's3', 'bucket', 'key', 'endpoint', 'region'] or ['type' => 'local'].
     * Never includes credentials.
     */
    public function urlsForCdr(string $xmlCdrUuid, int $ttlSeconds = 600): array
    {
        $rec = CDR::query()
            ->select('xml_cdr_uuid', 'record_path', 'record_name', 'domain_uuid')
            ->with('archive_recording:xml_cdr_uuid,object_key')
            ->where('xml_cdr_uuid', $xmlCdrUuid)
            ->first();

        if (!$rec) {
            return $this->empty();
        }

        if ($rec->record_path === 'S3') {
            $objectKey = $this->resolveS3ObjectKey($rec);

            if (!$objectKey) {
                return $this->empty();
            }

            $settings = $this->s3StorageConfigService->getSettingsForDomain($rec->domain_uuid);

            if (!$settings) {
                return $this->empty();
            }

            $disk = $this->s3StorageConfigService->buildDiskFromSettings($settings);
            $filename = basename($objectKey);

            $audioUrl = $disk->temporaryUrl(
                $objectKey,
                now()->addSeconds($ttlSeconds),
                [
                    'ResponseContentDisposition' => 'inline; filename="' . $filename . '"',
                ]
            );

            $downloadUrl = $disk->temporaryUrl(
                $objectKey,
                now()->addSeconds($ttlSeconds),
                [
                    'ResponseContentDisposition' => 'attachment; filename="' . $filename . '"',
                    'ResponseContentType' => 'application/octet-stream',
                ]
            );

            return [
                'audio_url' => $audioUrl,
                'download_url' => $downloadUrl,
                'filename' => $filename,
                'storage' => self::s3Storage($settings, $objectKey),
            ];
        }

        $filename = basename($rec->record_name ?: ($rec->archive_recording->object_key ?? 'recording'));

        return [
            'audio_url' => URL::temporarySignedRoute(
                'cdrs.recording.stream',
                now()->addSeconds($ttlSeconds),
                ['uuid' => $rec->xml_cdr_uuid]
            ),
            'download_url' => URL::temporarySignedRoute(
                'cdrs.recording.download',
                now()->addSeconds($ttlSeconds),
                ['uuid' => $rec->xml_cdr_uuid]
            ),
            'filename' => $filename,
            'storage' => ['type' => 'local'],
        ];
    }

    public static function s3Storage(array $settings, string $objectKey): array
    {
        $region = $settings['region'] ?? 'us-east-1';

        return [
            'type' => 's3',
            'bucket' => $settings['bucket'],
            'key' => $objectKey,
            'endpoint' => !empty($settings['endpoint'])
                ? $settings['endpoint']
                : 'https://s3.' . $region . '.amazonaws.com',
            'region' => $region,
        ];
    }

    private function empty(): array
    {
        return [
            'audio_url' => null,
            'download_url' => null,
            'filename' => null,
            'storage' => null,
        ];
    }

    private function resolveS3ObjectKey($rec): ?string
    {
        if (!empty($rec->record_name)) {
            return $rec->record_name;
        }

        if ($rec->archive_recording && !empty($rec->archive_recording->object_key)) {
            return $rec->archive_recording->object_key;
        }

        return null;
    }
}