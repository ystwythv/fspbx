<?php

namespace App\Jobs;

use App\Models\ApiWebhook;
use App\Models\ApiWebhookDelivery;
use App\Models\CDR;
use App\Services\Cdr\CdrCallDetailService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Delivers one cdr.finalized webhook (issue #9).
 *
 * Signing matches the recording webhooks exactly (same headers, same
 * t=<ts>,v0=<hmac-sha256 hex> scheme) so a receiver verifies both event
 * families with one implementation — see docs/recording-webhooks.md.
 */
class DeliverCdrWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // initial attempt + 6 retries spread over ~24h
    public $tries = 7;

    public $backoff = [60, 300, 1800, 7200, 21600, 57600];

    public function __construct(public string $deliveryUuid)
    {
    }

    public function handle(CdrCallDetailService $detailService): void
    {
        $delivery = ApiWebhookDelivery::find($this->deliveryUuid);
        if (! $delivery || $delivery->status === ApiWebhookDelivery::STATUS_SENT) {
            return;
        }

        $webhook = ApiWebhook::find($delivery->webhook_uuid);
        if (! $webhook || ! $webhook->enabled) {
            $delivery->update([
                'status' => ApiWebhookDelivery::STATUS_SKIPPED,
                'last_error' => 'Webhook deleted or disabled before delivery.',
            ]);
            return;
        }

        $cdr = CDR::query()->where('xml_cdr_uuid', $delivery->resource_uuid)->first();
        if (! $cdr) {
            $delivery->update([
                'status' => ApiWebhookDelivery::STATUS_FAILED,
                'last_error' => 'CDR row no longer exists.',
            ]);
            return;
        }

        $payload = [
            'event' => $delivery->event_type,
            'delivery_uuid' => (string) $delivery->delivery_uuid,
            'domain_uuid' => (string) $delivery->domain_uuid,
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
            // same shape as GET /api/v1/domains/{domain}/cdr/calls/{uuid}
            'call' => $detailService->detail($cdr)->toArray(),
        ];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp . '.' . $body, $webhook->secret);

        $delivery->increment('attempts');

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Voxra-Event' => $delivery->event_type,
            'X-Voxra-Delivery' => (string) $delivery->delivery_uuid,
            'X-Voxra-Timestamp' => (string) $timestamp,
            'X-Voxra-Signature' => 't=' . $timestamp . ',v0=' . $signature,
        ])->withBody($body, 'application/json')
            ->timeout(30)
            ->post($webhook->url);

        if ($response->successful()) {
            $delivery->update([
                'status' => ApiWebhookDelivery::STATUS_SENT,
                'sent_at' => now(),
                'last_error' => null,
            ]);
            $webhook->update([
                'last_success_at' => now(),
                'consecutive_failures' => 0,
            ]);
            return;
        }

        $error = 'HTTP ' . $response->status() . ': ' . mb_substr($response->body(), 0, 500);
        $delivery->update(['last_error' => $error]);
        $webhook->update([
            'last_failure_at' => now(),
            'consecutive_failures' => $webhook->consecutive_failures + 1,
        ]);

        // throw so the queue retries with backoff
        throw new \RuntimeException("cdr webhook delivery {$this->deliveryUuid} failed: {$error}");
    }

    public function failed(\Throwable $exception): void
    {
        $delivery = ApiWebhookDelivery::find($this->deliveryUuid);
        if ($delivery && $delivery->status !== ApiWebhookDelivery::STATUS_SENT) {
            $delivery->update([
                'status' => ApiWebhookDelivery::STATUS_FAILED,
                'last_error' => mb_substr($exception->getMessage(), 0, 1000),
            ]);
        }

        Log::warning('DeliverCdrWebhook exhausted retries', [
            'delivery_uuid' => $this->deliveryUuid,
            'error' => $exception->getMessage(),
        ]);
    }
}
