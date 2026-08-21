<?php

namespace Tests\Feature;

use App\Jobs\DeliverVoicemailWebhook;
use App\Models\ApiWebhook;
use App\Models\ApiWebhookDelivery;
use App\Models\VoicemailMessages;
use App\Models\Voicemails;
use Tests\TestCase;

/**
 * voicemail.finalized webhook (voxragtm#25): payload contract and the
 * X-Voxra-Signature t=/v0= scheme shared with cdr.finalized — a receiver
 * verifies both event families with one implementation.
 */
class VoicemailFinalizedWebhookTest extends TestCase
{
    private function message(?string $transcript): VoicemailMessages
    {
        $message = new VoicemailMessages();
        $message->setRawAttributes([
            'voicemail_message_uuid' => 'msg-uuid-1',
            'domain_uuid' => 'dom-uuid-1',
            'caller_id_number' => '+447911123456',
            'message_length' => '14',
            'created_epoch' => 1755600000, // 2025-08-19T10:40:00Z
            'message_transcription' => $transcript,
        ]);

        $voicemail = new Voicemails();
        $voicemail->setRawAttributes(['voicemail_id' => '9260']);
        $message->setRelation('voicemail', $voicemail);

        return $message;
    }

    private function delivery(): ApiWebhookDelivery
    {
        $delivery = new ApiWebhookDelivery();
        $delivery->setRawAttributes(['delivery_uuid' => 'del-uuid-1']);

        return $delivery;
    }

    public function test_payload_matches_documented_contract(): void
    {
        $payload = (new DeliverVoicemailWebhook('del-uuid-1'))->buildPayload(
            $this->delivery(),
            $this->message('Hi, it\'s Dave about the boiler.'),
            'https://pbx.example/voicemail-messages/msg-uuid-1/stream?signature=abc'
        );

        $this->assertSame('voicemail.finalized', $payload['event']);
        $this->assertSame('dom-uuid-1', $payload['domain_uuid']);
        $this->assertSame('+447911123456', $payload['caller_id_number']);
        $this->assertSame('9260', $payload['extension']);
        $this->assertSame(14, $payload['duration_seconds']);
        $this->assertSame('Hi, it\'s Dave about the boiler.', $payload['transcript']);
        $this->assertSame('https://pbx.example/voicemail-messages/msg-uuid-1/stream?signature=abc', $payload['recording_url']);
        $this->assertSame('2025-08-19T10:40:00Z', $payload['left_at']);
        $this->assertSame('del-uuid-1', $payload['delivery_uuid']);
    }

    public function test_transcript_and_recording_url_null_when_unavailable(): void
    {
        $payload = (new DeliverVoicemailWebhook('del-uuid-1'))
            ->buildPayload($this->delivery(), $this->message(null), null);

        $this->assertNull($payload['transcript']);
        $this->assertNull($payload['recording_url']);

        // whitespace-only transcription still delivers null, not ""
        $payload = (new DeliverVoicemailWebhook('del-uuid-1'))
            ->buildPayload($this->delivery(), $this->message('  '), null);
        $this->assertNull($payload['transcript']);
    }

    public function test_signature_matches_cdr_finalized_scheme(): void
    {
        $body = '{"event":"voicemail.finalized"}';
        $secret = 'whsec_test';
        $timestamp = 1755600000;

        $expected = 't=1755600000,v0=' . hash_hmac('sha256', '1755600000.' . $body, $secret);

        $this->assertSame($expected, DeliverVoicemailWebhook::signature($body, $timestamp, $secret));
    }

    public function test_voicemail_finalized_is_a_supported_subscription_event(): void
    {
        $this->assertContains(ApiWebhook::EVENT_VOICEMAIL_FINALIZED, ApiWebhook::SUPPORTED_EVENTS);

        $webhook = new ApiWebhook();
        $webhook->setRawAttributes(['events' => json_encode(['cdr.finalized', 'voicemail.finalized'])]);
        $this->assertTrue($webhook->subscribesTo(ApiWebhook::EVENT_VOICEMAIL_FINALIZED));

        $webhook->setRawAttributes(['events' => json_encode(['cdr.finalized'])]);
        $this->assertFalse($webhook->subscribesTo(ApiWebhook::EVENT_VOICEMAIL_FINALIZED));
    }
}
