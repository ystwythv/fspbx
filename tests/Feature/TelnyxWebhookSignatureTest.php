<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * VerifyTelnyxSignature middleware on the Telnyx-facing reception-agent
 * webhooks: Ed25519 platform signature OR shared tool secret, failing closed
 * when neither is configured. (voxragtm#13)
 *
 * A 422 "conversation_id required" from the tool endpoint means the request
 * cleared auth and reached the controller — the tests use it as the
 * "accepted" marker so no session/Redis fixtures are needed.
 */
class TelnyxWebhookSignatureTest extends TestCase
{
    private const TOOL_URL = '/webhooks/voxra/reception-agent/tool-telnyx/lookup_user';
    private const DYNVARS_URL = '/webhooks/voxra/reception-agent/dynamic-variables';

    private string $secretKey;

    private string $publicKeyB64;

    protected function setUp(): void
    {
        parent::setUp();

        $keypair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($keypair);
        $this->publicKeyB64 = base64_encode(sodium_crypto_sign_publickey($keypair));

        config([
            'telnyx.public_key' => $this->publicKeyB64,
            'telnyx.tool_secret' => '',
            'telnyx.webhook_allow_unsigned' => false,
            'telnyx.webhook_tolerance' => 300,
        ]);
    }

    private function signedHeaders(array $payload, ?int $timestamp = null, ?string $secretKey = null): array
    {
        $timestamp ??= time();
        $signature = sodium_crypto_sign_detached(
            $timestamp . '|' . json_encode($payload),
            $secretKey ?? $this->secretKey,
        );

        return [
            'telnyx-signature-ed25519' => base64_encode($signature),
            'telnyx-timestamp' => (string) $timestamp,
        ];
    }

    public function test_valid_ed25519_signature_is_accepted(): void
    {
        $payload = ['query' => 'Alice'];

        $this->postJson(self::TOOL_URL, $payload, $this->signedHeaders($payload))
            ->assertStatus(422)
            ->assertJson(['error' => 'conversation_id required']);
    }

    public function test_bad_signature_is_rejected(): void
    {
        $payload = ['query' => 'Alice'];
        $otherKeypair = sodium_crypto_sign_keypair();

        $this->postJson(
            self::TOOL_URL,
            $payload,
            $this->signedHeaders($payload, null, sodium_crypto_sign_secretkey($otherKeypair)),
        )->assertStatus(401)->assertJson(['error' => 'invalid signature']);
    }

    public function test_tampered_body_is_rejected(): void
    {
        $headers = $this->signedHeaders(['query' => 'Alice']);

        $this->postJson(self::TOOL_URL, ['query' => 'Mallory'], $headers)
            ->assertStatus(401);
    }

    public function test_stale_timestamp_is_rejected(): void
    {
        $payload = ['query' => 'Alice'];

        $this->postJson(self::TOOL_URL, $payload, $this->signedHeaders($payload, time() - 4000))
            ->assertStatus(401);
    }

    public function test_unsigned_request_is_rejected_when_key_configured(): void
    {
        $this->postJson(self::TOOL_URL, ['query' => 'Alice'])
            ->assertStatus(401);
    }

    public function test_fails_closed_when_nothing_configured(): void
    {
        config(['telnyx.public_key' => '']);

        $this->postJson(self::TOOL_URL, ['query' => 'Alice'])
            ->assertStatus(500)
            ->assertJson(['error' => 'telnyx webhook auth not configured']);
    }

    public function test_allow_unsigned_escape_hatch(): void
    {
        config(['telnyx.public_key' => '', 'telnyx.webhook_allow_unsigned' => true]);

        $this->postJson(self::TOOL_URL, ['query' => 'Alice'])
            ->assertStatus(422)
            ->assertJson(['error' => 'conversation_id required']);
    }

    public function test_shared_secret_header_is_accepted(): void
    {
        config(['telnyx.tool_secret' => 'tool-secret-1']);

        $this->postJson(self::TOOL_URL, ['query' => 'Alice'], ['X-Voxra-Tool-Secret' => 'tool-secret-1'])
            ->assertStatus(422)
            ->assertJson(['error' => 'conversation_id required']);
    }

    public function test_wrong_shared_secret_is_rejected(): void
    {
        config(['telnyx.tool_secret' => 'tool-secret-1']);

        $this->postJson(self::TOOL_URL, ['query' => 'Alice'], ['X-Voxra-Tool-Secret' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_shared_secret_query_param_on_dynamic_variables(): void
    {
        // dynamic_variables_webhook_url cannot carry headers, so the secret
        // rides as ?s= (mirrors the voxraweb data-tool convention).
        config(['telnyx.tool_secret' => 'tool-secret-1']);

        $this->postJson(self::DYNVARS_URL . '?s=tool-secret-1', [])
            ->assertStatus(200)
            ->assertJsonStructure(['dynamic_variables' => ['conversation_id']]);

        $this->postJson(self::DYNVARS_URL, [])
            ->assertStatus(401);
    }
}
