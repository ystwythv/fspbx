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

        if (!$config) {
            $delivery->update([
                'status' => RecordingWebhookDelivery::STATUS_SKIPPED,
                'last_error' => 'Webhook disabled for domain at send time',
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
        }

        $delivery->increment('attempts');

        $urls = $urlService->urlsForCdr($cdr->xml_cdr_uuid, $config['url_ttl']);

        if (empty($urls['audio_url'])) {
            throw new \RuntimeException('No recording URL available for CDR ' . $cdr->xml_cdr_uuid);
        }

        $payload = [
            'event' => 'recording.available',
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
                'expires_at' => now()->addSeconds($config['url_ttl'])->toIso8601String(),
                'format' => strtolower(pathinfo($urls['filename'] ?? '', PATHINFO_EXTENSION)) ?: null,
            ],
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $config['secret']);

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Voxra-Event' => 'recording.available',
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
        ]);
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
