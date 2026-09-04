<?php

namespace App\Jobs;

use App\Models\CDR;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use App\Models\RecordingWebhookDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Services\CallRecordingUrlService;
use Illuminate\Foundation\Bus\Dispatchable;
use App\Services\RecordingWebhookConfigService;
use Symfony\Component\Process\Process;

class SendRecordingWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 5;

    public $backoff = [60, 300, 900, 1800];

    public function __construct(
        public string $deliveryUuid
    ) {
    }

    public function handle(
        RecordingWebhookConfigService $configService,
        CallRecordingUrlService $urlService
    ) {
        $delivery = RecordingWebhookDelivery::find($this->deliveryUuid);

        if (!$delivery || $delivery->status === RecordingWebhookDelivery::STATUS_SENT) {
            return;
        }

        $config = $configService->getSettingsForDomain($delivery->domain_uuid);
        $event = $delivery->event ?: RecordingWebhookConfigService::EVENT_AVAILABLE;

        if (!$config || !in_array($event, $config['events'], true)) {
            $delivery->update([
                'status' => RecordingWebhookDelivery::STATUS_SKIPPED,
                'last_error' => 'Webhook or event disabled for domain at send time',
            ]);
            return;
        }

        $cdr = CDR::query()
            ->with([
                'extension:extension_uuid,extension,effective_caller_id_name',
                'domain:domain_uuid,domain_name',
            ])
            ->where('xml_cdr_uuid', $delivery->xml_cdr_uuid)
            ->first();

        if (!$cdr) {
            $delivery->update([
                'status' => RecordingWebhookDelivery::STATUS_FAILED,
                'last_error' => 'CDR no longer exists',
            ]);
            return;
        }

        // A locally-stored recording can only be served by the node that holds
        // the file — the signed URL uses this node's APP_URL/APP_KEY. Fail
        // loudly rather than deliver a URL that will 404 on fetch.
        if ($cdr->record_path !== 'S3') {
            $dir = rtrim($cdr->record_path ?: '', '/');
            if ($dir === '' || !is_file($dir . '/' . $cdr->record_name)) {
                $delivery->update([
                    'status' => RecordingWebhookDelivery::STATUS_FAILED,
                    'last_error' => 'Recording file not present on this node (' . gethostname() . ')',
                ]);
                return;
            }

            $this->transcodeOversizedWav($cdr, $dir);
        }

        $delivery->increment('attempts');

        $urls = $urlService->urlsForCdr($cdr->xml_cdr_uuid, $config['url_ttl']);

        if (empty($urls['audio_url'])) {
            throw new \RuntimeException('No recording URL available for CDR ' . $cdr->xml_cdr_uuid);
        }

        $payload = $this->buildPayload($event, $delivery, $cdr, $urls, $config['url_ttl']);

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $config['secret']);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Voxra-Event' => $event,
            'X-Voxra-Delivery' => $delivery->uuid,
            'X-Voxra-Timestamp' => (string) $timestamp,
            'X-Voxra-Signature' => 't=' . $timestamp . ',v0=' . $signature,
        ])
            ->timeout(30)
            ->withBody($body, 'application/json')
            ->post($config['url']);

        if (!$response->successful()) {
            $delivery->update([
                'last_error' => 'HTTP ' . $response->status() . ': ' . Str::limit($response->body(), 500),
            ]);
            throw new \RuntimeException(
                'Webhook endpoint returned HTTP ' . $response->status() . ' for delivery ' . $delivery->uuid
            );
        }

        $delivery->update([
            'status' => RecordingWebhookDelivery::STATUS_SENT,
            'sent_at' => now(),
            'last_error' => null,
            'storage_type' => $payload['recording']['storage']['type'] ?? null,
        ]);
    }

    /**
     * Payload contract — see docs/recording-webhooks.md §2. Identical shape
     * for recording.available and recording.archived; `recording.storage`
     * says where the object lives ({type: local} or {type: s3, bucket, key,
     * endpoint, region}) so a receiver that owns the bucket can keep a durable
     * pointer rather than the time-limited URLs.
     */
    public function buildPayload(string $event, RecordingWebhookDelivery $delivery, CDR $cdr, array $urls, int $urlTtl): array
    {
        return [
            'event' => $event,
            'delivery_uuid' => $delivery->uuid,
            'cdr_uuid' => $cdr->xml_cdr_uuid,
            'domain' => $cdr->domain->domain_name ?? null,
            'direction' => $cdr->direction,
            'extension' => $cdr->extension->extension ?? null,
            'extension_name' => $cdr->extension->effective_caller_id_name ?? null,
            'caller_id_name' => $cdr->caller_id_name,
            'caller_id_number' => $cdr->caller_id_number,
            'caller_destination' => $cdr->caller_destination,
            'destination_number' => $cdr->destination_number,
            'start' => $cdr->start_stamp ? Carbon::parse($cdr->start_stamp)->toIso8601String() : null,
            'end' => $cdr->end_stamp ? Carbon::parse($cdr->end_stamp)->toIso8601String() : null,
            'duration' => (int) $cdr->duration,
            'billsec' => (int) $cdr->billsec,
            'hangup_cause' => $cdr->hangup_cause,
            'recording' => [
                'url' => $urls['audio_url'],
                'download_url' => $urls['download_url'],
                'filename' => $urls['filename'],
                'expires_at' => now()->addSeconds($urlTtl)->toIso8601String(),
                'format' => strtolower(pathinfo($urls['filename'] ?? '', PATHINFO_EXTENSION)) ?: null,
                'storage' => $urls['storage'] ?? ['type' => 'local'],
            ],
        ];
    }

    /**
     * WebRTC/verto legs are recorded at their native format (48kHz stereo PCM,
     * ~11.5MB/min) instead of the usual 16kHz mono, producing WAVs of 100MB+
     * that downstream consumers cannot ingest (the CRM transcription pipeline
     * rejects payloads over 80MB). Before delivering the webhook, convert any
     * oversized WAV to 16kHz mono MP3 (~130KB/min) and point the CDR at the
     * new file so the signed URLs — and any later playback — use it.
     */
    private const TRANSCODE_THRESHOLD_BYTES = 25 * 1024 * 1024;

    private function transcodeOversizedWav(CDR $cdr, string $dir): void
    {
        $wavPath = $dir . '/' . $cdr->record_name;

        if (strtolower(pathinfo($wavPath, PATHINFO_EXTENSION)) !== 'wav') {
            return;
        }

        $size = filesize($wavPath);
        if ($size === false || $size <= self::TRANSCODE_THRESHOLD_BYTES) {
            return;
        }

        $mp3Name = preg_replace('/\.wav$/i', '.mp3', $cdr->record_name);
        $mp3Path = $dir . '/' . $mp3Name;

        $process = new Process([
            'ffmpeg',
            '-nostdin',
            '-y',
            '-i', $wavPath,
            '-ac', '1',
            '-ar', '16000',
            '-b:a', '32k',
            $mp3Path,
        ]);
        $process->setTimeout(1800);
        $process->run();

        if (!$process->isSuccessful() || !is_file($mp3Path) || filesize($mp3Path) === 0) {
            @unlink($mp3Path);
            logger()->warning('Recording webhook: ffmpeg transcode failed for ' . $wavPath
                . ' — delivering original WAV. ' . Str::limit($process->getErrorOutput(), 500));
            return;
        }

        CDR::where('xml_cdr_uuid', $cdr->xml_cdr_uuid)->update(['record_name' => $mp3Name]);
        // The dispatcher dedupes on (domain_uuid, record_name): rename the
        // claim row too or the same call is re-announced under the mp3 name.
        RecordingWebhookDelivery::where('domain_uuid', $cdr->domain_uuid)
            ->where('record_name', $cdr->record_name)
            ->update(['record_name' => $mp3Name]);
        $cdr->record_name = $mp3Name;
        @unlink($wavPath);

        logger()->info(sprintf(
            'Recording webhook: transcoded oversized WAV %s (%.1fMB -> %.1fMB)',
            $cdr->xml_cdr_uuid,
            $size / 1048576,
            filesize($mp3Path) / 1048576
        ));
    }

    public function failed(\Throwable $exception): void
    {
        RecordingWebhookDelivery::where('uuid', $this->deliveryUuid)
            ->update([
                'status' => RecordingWebhookDelivery::STATUS_FAILED,
                'last_error' => \Illuminate\Support\Str::limit($exception->getMessage(), 500),
            ]);
    }
}
