<?php

namespace App\Jobs;

use App\Models\ApiWebhook;
use App\Models\ApiWebhookDelivery;
use App\Models\VoicemailMessages;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Delivers one voicemail.finalized webhook (voxragtm#25).
 *
 * Queued from the FreeSWITCH voicemail_created event, so the message row and
 * audio file already exist. Transcription happens HERE, not in the
 * email-notification job: the FS lua only emits send_vm_email_notification
 * when the box has a voicemail_mail_to, and the fire-once-with-transcript
 * contract must hold without one. The whereNull-guarded persist keeps this
 * job and the email job from clobbering each other's transcript.
 *
 * Signing matches cdr.finalized / recording webhooks exactly (same headers,
 * same t=<ts>,v0=<hmac-sha256 hex> scheme).
 */
class DeliverVoicemailWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // initial attempt + 6 retries spread over ~24h
    public $tries = 7;

    public $backoff = [60, 300, 1800, 7200, 21600, 57600];

    /** TTL of the signed recording URL carried in the payload. */
    public const RECORDING_URL_TTL = 86400;

    public function __construct(public string $deliveryUuid)
    {
    }

    public function handle(): void
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

        $message = VoicemailMessages::query()
            ->with('voicemail:voicemail_uuid,voicemail_id,voicemail_transcription_enabled')
            ->with('domain:domain_uuid,domain_name')
            ->find($delivery->resource_uuid);
        if (! $message) {
            $delivery->update([
                'status' => ApiWebhookDelivery::STATUS_FAILED,
                'last_error' => 'Voicemail message no longer exists.',
            ]);
            return;
        }

        $this->maybeTranscribe($message);

        $payload = $this->buildPayload($delivery, $message, $this->recordingUrl($message));

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = time();

        $delivery->increment('attempts');

        $response = Http::withHeaders([
            'Content-Type' => 'application/json',
            'X-Voxra-Event' => $delivery->event_type,
            'X-Voxra-Delivery' => (string) $delivery->delivery_uuid,
            'X-Voxra-Timestamp' => (string) $timestamp,
            'X-Voxra-Signature' => self::signature($body, $timestamp, $webhook->secret),
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
        throw new \RuntimeException("voicemail webhook delivery {$this->deliveryUuid} failed: {$error}");
    }

    /** The documented voicemail.finalized payload. Transcript is null when
     *  transcription is disabled, unconfigured, or failed. */
    public function buildPayload(ApiWebhookDelivery $delivery, VoicemailMessages $message, ?string $recordingUrl): array
    {
        $transcript = trim((string) $message->message_transcription);

        return [
            'event' => ApiWebhook::EVENT_VOICEMAIL_FINALIZED,
            'delivery_uuid' => (string) $delivery->delivery_uuid,
            'domain_uuid' => (string) $message->domain_uuid,
            'caller_id_number' => (string) $message->caller_id_number,
            'extension' => (string) ($message->voicemail?->voicemail_id ?? ''),
            'duration_seconds' => (int) $message->message_length,
            'transcript' => $transcript !== '' ? $transcript : null,
            'recording_url' => $recordingUrl,
            'left_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $message->created_epoch),
            'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }

    /** Same t=/v0= scheme as cdr.finalized and the recording webhooks. */
    public static function signature(string $body, int $timestamp, string $secret): string
    {
        return 't=' . $timestamp . ',v0=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    private function maybeTranscribe(VoicemailMessages $message): void
    {
        if (trim((string) $message->message_transcription) !== '') {
            return;
        }
        if (($message->voicemail?->voicemail_transcription_enabled ?? 'false') !== 'true') {
            return;
        }

        $enabled = strtolower((string) get_domain_setting('transcribe_enabled', $message->domain_uuid));
        if (! in_array($enabled, ['true', 't', '1', 'yes', 'on'], true)) {
            return;
        }

        $path = $this->audioPath($message);
        if (! $path) {
            return;
        }

        // Transcription failure must not block delivery — transcript goes out null.
        try {
            $result = app(\App\Services\VoicemailTranscriptionService::class)->transcribe([
                'file_path' => Storage::disk('voicemail')->path($path),
                'provider' => get_domain_setting('transcribe_provider', $message->domain_uuid) ?? 'openai',
                'language' => get_domain_setting('transcribe_language', $message->domain_uuid) ?? 'en-US',
                'domain_uuid' => $message->domain_uuid,
            ]);
        } catch (\Throwable $e) {
            Log::warning('voicemail.finalized transcription failed', [
                'message_uuid' => $message->voicemail_message_uuid,
                'error' => $e->getMessage(),
            ]);
            return;
        }

        $text = is_array($result) ? trim((string) Arr::get($result, 'message', '')) : '';
        if ($text === '') {
            return;
        }

        // Persist without clobbering (idempotent on retries and vs the email job)
        $updated = VoicemailMessages::where('voicemail_message_uuid', $message->voicemail_message_uuid)
            ->whereNull('message_transcription')
            ->update(['message_transcription' => $text]);

        if ($updated) {
            $message->message_transcription = $text;
        } else {
            $message->refresh(); // the email job won the race — use its transcript
        }
    }

    private function recordingUrl(VoicemailMessages $message): ?string
    {
        if (! $this->audioPath($message)) {
            return null;
        }

        return URL::temporarySignedRoute(
            'voicemails.message.stream',
            now()->addSeconds(self::RECORDING_URL_TTL),
            ['uuid' => $message->voicemail_message_uuid]
        );
    }

    private function audioPath(VoicemailMessages $message): ?string
    {
        $base = ($message->domain?->domain_name ?? '') . '/'
            . ($message->voicemail?->voicemail_id ?? '') . '/msg_'
            . $message->voicemail_message_uuid;

        foreach ([$base . '.wav', $base . '.mp3'] as $path) {
            if (Storage::disk('voicemail')->exists($path)) {
                return $path;
            }
        }

        return null;
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

        Log::warning('DeliverVoicemailWebhook exhausted retries', [
            'delivery_uuid' => $this->deliveryUuid,
            'error' => $exception->getMessage(),
        ]);
    }
}
