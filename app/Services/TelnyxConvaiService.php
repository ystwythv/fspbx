<?php

namespace App\Services;

use RuntimeException;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

/**
 * Thin client for the Telnyx AI assistant + SIP attach (UAC connection) APIs.
 *
 * Every Telnyx AI assistant auto-creates a TeXML app with a SIP subdomain
 * `assistant-<id>.sip.telnyx.com` that accepts INVITEs from anyone, so the
 * PBX can always bridge to the agent without registration. SIP attach (a UAC
 * connection) additionally makes Telnyx register INTO the PBX as a SIP
 * endpoint; calls to the registered extension reach the assistant.
 */
class TelnyxConvaiService
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey  = (string) config('services.telnyx.api_key', '');
        $this->baseUrl = rtrim((string) config('services.telnyx.base_url', 'https://api.telnyx.com'), '/');
        $this->timeout = (int) config('services.telnyx.timeout', 60);

        if ($this->apiKey === '') {
            throw new RuntimeException('Telnyx API key is not configured. Please set TELNYX_API_KEY in your environment file.');
        }
    }

    /**
     * Create a new Telnyx AI assistant.
     * POST /v2/ai/assistants
     */
    public function createAssistant(string $name, ?string $instructions, ?string $greeting, ?string $voice, ?string $model, string $language = 'en'): array
    {
        $body = array_filter([
            'name'         => $name,
            'instructions' => $instructions ?? '',
            'greeting'     => $greeting ?? '',
            'model'        => $model,
            'voice_settings' => $voice ? ['voice' => $voice] : null,
            'transcription'  => ['language' => $language],
        ], fn ($v) => $v !== null);

        $response = $this->http()->post('v2/ai/assistants', $body);

        if (!$response->successful()) {
            logger('Telnyx create assistant error: ' . $response->body());
            throw new RuntimeException('Failed to create Telnyx assistant: ' . $this->errorDetail($response));
        }

        return $response->json();
    }

    /**
     * Update an existing Telnyx assistant.
     * POST /v2/ai/assistants/{assistant_id}
     *
     * voice_settings is replaced wholesale by the API, so merge the new
     * voice into the assistant's current settings — otherwise portal-side
     * tweaks (voice_speed, background audio, ...) get reset on every save.
     */
    public function updateAssistant(string $assistantId, ?string $name, ?string $instructions, ?string $greeting, ?string $voice, ?string $model, string $language = 'en'): array
    {
        $voiceSettings = null;
        if ($voice) {
            $voiceSettings = $this->getAssistant($assistantId)['voice_settings'] ?? [];
            $voiceSettings['voice'] = $voice;
        }

        $body = array_filter([
            'name'         => $name,
            'instructions' => $instructions ?? '',
            'greeting'     => $greeting ?? '',
            'model'        => $model,
            'voice_settings' => $voiceSettings,
            'transcription'  => ['language' => $language],
        ], fn ($v) => $v !== null);

        $response = $this->http()->post("v2/ai/assistants/{$assistantId}", $body);

        if (!$response->successful()) {
            logger('Telnyx update assistant error: ' . $response->body());
            throw new RuntimeException('Failed to update Telnyx assistant: ' . $this->errorDetail($response));
        }

        return $response->json();
    }

    /**
     * Register the reception-agent tool surface on a Telnyx assistant and wire
     * the dynamic-variables webhook (so each tool call carries conversation_id).
     *
     * Telnyx webhook tool shape: {"type":"webhook","webhook":{name,description,
     * url,method,headers,body_parameters}}. The assistant fills body_parameters;
     * conversation_id is injected via a templated header ({{conversation_id}}),
     * resolved from the dynamic-variables webhook at call start.
     *
     * POST /v2/ai/assistants/{assistant_id}
     */
    public function syncReceptionAgentTools(\App\Models\AiAgent $agent): array
    {
        if (!$agent->telnyx_assistant_id) {
            throw new RuntimeException('Agent has no Telnyx assistant id; create the assistant first');
        }

        $base = rtrim((string) config('app.url', ''), '/');
        if ($base === '') {
            throw new RuntimeException('APP_URL must be set for Telnyx tool webhooks');
        }
        // Telnyx-specific routes (the ElevenLabs /tool route is gated by an
        // ElevenLabs-signature middleware Telnyx calls can't satisfy).
        $toolUrl = $base . '/webhooks/voxra/reception-agent/tool-telnyx';
        $dynVarsUrl = $base . '/webhooks/voxra/reception-agent/dynamic-variables';

        $tools = [];
        foreach (\App\Services\ReceptionAgent\ReceptionAgentToolDefinitions::list((array) ($agent->tools_enabled ?? [])) as $t) {
            $tools[] = [
                'type' => 'webhook',
                'webhook' => [
                    'name' => $t['name'],
                    'description' => $t['description'],
                    // Tool name in the path — robust against the LLM omitting it
                    // from the body (causes a 422 'tool_name required').
                    'url' => $toolUrl . '/' . $t['name'],
                    'method' => 'POST',
                    'headers' => [
                        ['name' => 'Content-Type', 'value' => 'application/json'],
                        // conversation_id resolved from the dynamic-variables webhook.
                        ['name' => 'X-Voxra-Conversation-Id', 'value' => '{{conversation_id}}'],
                    ],
                    'body_parameters' => [
                        'type' => 'object',
                        'properties' => array_merge([
                            'tool_name' => ['type' => 'string', 'enum' => [$t['name']]],
                        ], $t['properties']),
                        'required' => array_values(array_unique(array_merge(['tool_name'], $t['required']))),
                    ],
                ],
            ];
        }

        $body = [
            'tools' => $tools,
            'dynamic_variables_webhook_url' => $dynVarsUrl,
        ];

        $response = $this->http()->post("v2/ai/assistants/{$agent->telnyx_assistant_id}", $body);

        if (!$response->successful()) {
            logger('Telnyx sync reception tools error: ' . $response->body());
            throw new RuntimeException('Failed to sync Telnyx reception tools: ' . $this->errorDetail($response));
        }

        return $response->json();
    }

    /**
     * Get an assistant's current configuration.
     * GET /v2/ai/assistants/{assistant_id}
     */
    public function getAssistant(string $assistantId): array
    {
        $response = $this->http()->get("v2/ai/assistants/{$assistantId}");

        if (!$response->successful()) {
            throw new RuntimeException('Failed to get Telnyx assistant: ' . $this->errorDetail($response));
        }

        return $response->json();
    }

    /**
     * Delete a Telnyx assistant.
     * DELETE /v2/ai/assistants/{assistant_id}
     */
    public function deleteAssistant(string $assistantId): void
    {
        $response = $this->http()->delete("v2/ai/assistants/{$assistantId}");

        if (!$response->successful() && $response->status() !== 404) {
            logger('Telnyx delete assistant error: ' . $response->body());
            throw new RuntimeException('Failed to delete Telnyx assistant: ' . $this->errorDetail($response));
        }
    }

    /**
     * Create a SIP attach (UAC) connection so Telnyx registers into the PBX.
     * POST /v2/uac_connections
     *
     * Telnyx quirks (verified 2026-06):
     *  - the AOR/realm domain is taken from the proxy host, so the PBX must
     *    have a directory domain whose name matches `proxy` (without port)
     *  - `outbound_proxy` requires a `sip:` scheme if used
     *  - the registration prober starts ~4-5 minutes after create/toggle
     *  - `expiration_sec` is not honoured reliably
     */
    public function createUacConnection(string $connectionName, string $username, string $password, string $proxy, string $destinationUri): array
    {
        $body = [
            'connection_name' => $connectionName,
            'active' => true,
            'external_uac_settings' => [
                'username'       => $username,
                'password'       => $password,
                'proxy'          => $proxy,
                'transport'      => 'UDP',
                'expiration_sec' => 300,
            ],
            'internal_uac_settings' => [
                'destination_uri' => $destinationUri,
            ],
        ];

        $response = $this->http()->post('v2/uac_connections', $body);

        if (!$response->successful()) {
            logger('Telnyx create UAC connection error: ' . $response->body());
            throw new RuntimeException('Failed to create Telnyx UAC connection: ' . $this->errorDetail($response));
        }

        return $response->json();
    }

    /**
     * Delete a UAC connection.
     * DELETE /v2/uac_connections/{id}
     */
    public function deleteUacConnection(string $connectionId): void
    {
        $response = $this->http()->delete("v2/uac_connections/{$connectionId}");

        if (!$response->successful() && $response->status() !== 404) {
            logger('Telnyx delete UAC connection error: ' . $response->body());
            throw new RuntimeException('Failed to delete Telnyx UAC connection: ' . $this->errorDetail($response));
        }
    }

    /**
     * List Telnyx-native TTS voices for the UI.
     * GET /v2/text-to-speech/voices
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getVoices(): array
    {
        $response = $this->http()->get('v2/text-to-speech/voices', ['provider' => 'telnyx']);

        if (!$response->successful()) {
            logger('Telnyx list voices error: ' . $response->body());
            return [];
        }

        return collect($response->json('voices', []))
            ->filter(fn ($v) => !empty($v['id']))
            ->map(function ($v) {
                // language/gender are not present on every voice
                $meta = implode(', ', array_filter([$v['language'] ?? null, $v['gender'] ?? null]));
                return [
                    'value' => $v['id'],
                    'label' => ($v['name'] ?? $v['id']) . ($meta !== '' ? " ({$meta})" : ''),
                ];
            })
            ->values()
            ->toArray();
    }

    /**
     * List available LLM models for the UI.
     * GET /v2/ai/models
     *
     * @return array<int, array{value: string, label: string}>
     */
    public function getModels(): array
    {
        $response = $this->http()->get('v2/ai/models');

        if (!$response->successful()) {
            logger('Telnyx list models error: ' . $response->body());
            return [];
        }

        return collect($response->json('data', []))
            // /v2/ai/models also lists models the assistants API rejects
            // ("Model X is not available for AI Assistants")
            ->filter(fn ($m) => !empty($m['id']) && ($m['recommended_for_assistants'] ?? false))
            ->map(fn ($m) => ['value' => $m['id'], 'label' => $m['id']])
            ->values()
            ->toArray();
    }

    private function errorDetail(\Illuminate\Http\Client\Response $response): string
    {
        $errors = $response->json('errors');
        if (is_array($errors) && !empty($errors)) {
            return collect($errors)
                ->map(fn ($e) => trim(($e['title'] ?? '') . ': ' . ($e['detail'] ?? '')))
                ->implode('; ');
        }

        return $response->body();
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl($this->baseUrl . '/')
            ->timeout($this->timeout)
            ->withToken($this->apiKey)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])
            ->retry(
                3,
                500,
                function ($exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }
                    $response = method_exists($exception, 'response') ? $exception->response() : null;
                    $status = $response?->status();
                    return in_array($status, [429, 500, 502, 503, 504], true);
                },
                throw: false
            );
    }
}
