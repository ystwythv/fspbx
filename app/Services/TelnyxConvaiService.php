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
    /**
     * Default assistant voice when none is specified: "Alistair — Composed
     * Consultant", a British male Telnyx Ultra voice (voxra voice standard).
     * Without this, Telnyx assigns a US default (Katie), which is wrong for UK.
     */
    public const DEFAULT_VOICE = 'Telnyx.Ultra.c8f7835e-28a3-4f0c-80d7-c1302ac62aae';

    /**
     * Seconds of caller silence before the assistant checks in ("Sorry, I
     * didn't catch that — how can I help?"). Telnyx's default is 10 s; QA
     * (nutty.price, 26 Sep) lost a caller whose question overlapped the tail
     * of the un-interruptible greeting: Telnyx dropped the speech and sat
     * silent until the caller hung up. A prompt re-ask after a short silence
     * recovers that caller (and anyone else who's gone quiet).
     */
    public const USER_IDLE_REPLY_SECS = 4;

    /**
     * Backstop on a dead line (caller put the phone down without hanging up):
     * the assistant is stopped after this much caller silence. The prompt
     * closes politely after two unanswered check-ins long before this, so it
     * is generous enough never to cut a long answer or a transfer attempt.
     */
    public const USER_IDLE_TIMEOUT_SECS = 60;

    /** Mirrors voxraweb's default for tenants that haven't set one. */
    public const DEFAULT_URGENT_DEFINITION = "anything that can't wait for a normal call-back: a risk to someone's health or safety, damage happening now, or a problem caused by work the business has just done";

    private string $apiKey;
    private string $baseUrl;
    /** Ceiling on Telnyx waiting for the dynamic-variables webhook (ms). */
    public const DYNAMIC_VARIABLES_TIMEOUT_MS = 1500;

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
            'voice_settings' => ['voice' => $voice ?: self::DEFAULT_VOICE],
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
        // Telephony tools stay on this PBX (they need ESL). DATA tools (lead/
        // booking/memory) run in voxraweb, which owns customer data in Supabase
        // (voxragtm#88); Telnyx points those + the dynamic-variables webhook at
        // voxraweb, authed by a shared secret, with the tenant/caller context
        // templated into headers from the dynamic variables voxraweb returns.
        $toolUrl = $base . '/webhooks/voxra/reception-agent/tool-telnyx';

        $voxraBase = rtrim((string) config('services.voxra.app_url', ''), '/');
        $secret = (string) config('services.voxra.agent_tool_secret', '');
        // Shared secret authenticating telephony tool calls back to this PBX
        // (VerifyTelnyxSignature middleware, voxragtm#13).
        $toolSecret = (string) config('telnyx.tool_secret', '');
        $dataToolUrl = $voxraBase !== '' ? $voxraBase . '/api/agent/tool' : '';
        $dynVarsUrl = $voxraBase !== ''
            ? $voxraBase . '/api/agent/dynamic-variables' . ($secret !== '' ? '?s=' . urlencode($secret) : '')
            : $base . '/webhooks/voxra/reception-agent/dynamic-variables'
                . ($toolSecret !== '' ? '?s=' . urlencode($toolSecret) : '');

        $defs = \App\Services\ReceptionAgent\ReceptionAgentToolDefinitions::class;

        $tools = [];
        foreach ($defs::list((array) ($agent->tools_enabled ?? [])) as $t) {
            if ($defs::isDataTool($t['name'])) {
                if ($dataToolUrl === '') {
                    throw new RuntimeException('VOXRA_APP_URL must be set for reception data tools');
                }
                $url = $dataToolUrl;
                $headers = [
                    ['name' => 'Content-Type', 'value' => 'application/json'],
                    ['name' => 'Authorization', 'value' => 'Bearer ' . $secret],
                    ['name' => 'X-Voxra-Domain-Uuid', 'value' => '{{domain_uuid}}'],
                    ['name' => 'X-Voxra-Conversation-Id', 'value' => '{{conversation_id}}'],
                    ['name' => 'X-Voxra-Caller-Number', 'value' => '{{caller_number}}'],
                ];
            } else {
                // Tool name in the path — robust against the LLM omitting it
                // from the body (causes a 422 'tool_name required').
                $url = $toolUrl . '/' . $t['name'];
                $headers = [
                    ['name' => 'Content-Type', 'value' => 'application/json'],
                    ['name' => 'X-Voxra-Conversation-Id', 'value' => '{{conversation_id}}'],
                ];
                if ($toolSecret !== '') {
                    $headers[] = ['name' => 'X-Voxra-Tool-Secret', 'value' => $toolSecret];
                }
            }

            $webhook = [
                'name' => $t['name'],
                'description' => $t['description'],
                'url' => $url,
                'method' => 'POST',
                'headers' => $headers,
                'body_parameters' => [
                    'type' => 'object',
                    'properties' => array_merge([
                        'tool_name' => ['type' => 'string', 'enum' => [$t['name']]],
                    ], $t['properties']),
                    'required' => array_values(array_unique(array_merge(['tool_name'], $t['required']))),
                ],
            ];
            // Response field → dynamic variable (e.g. alert_owner's transfer_to
            // → {{owner_transfer_to}}, the owner-transfer target, voxragtm#122).
            if (!empty($t['store_as_variables'])) {
                $webhook['store_fields_as_variables'] = array_map(
                    fn ($var, $path) => ['name' => $var, 'value_path' => $path],
                    array_keys($t['store_as_variables']),
                    array_values($t['store_as_variables'])
                );
            }
            if (!empty($t['filler'])) {
                $webhook['messages'] = [
                    ['type' => 'request_response_delayed', 'content' => $t['filler'], 'timing_ms' => 1500],
                ];
            }

            $tool = ['type' => 'webhook', 'webhook' => $webhook];
            // Tool-level (a timeout nested in `webhook` is ignored by Telnyx).
            if (!empty($t['timeout_ms'])) {
                $tool['timeout_ms'] = (int) $t['timeout_ms'];
            }
            $tools[] = $tool;
        }

        // Warm transfer to the owner's mobile (voxragtm#30): Telnyx-native
        // transfer tool. from = the caller, so the owner sees who's being put
        // through and can ring straight back if missed.
        // Target {{owner_transfer_to}} (voxragtm#122): NOT set at call start —
        // only alert_owner's response fills it (store_fields_as_variables),
        // after voxraweb has recorded the urgent lead and alerted the owner.
        // So a transfer can't happen before the owner has been told; tried
        // early it has nothing to dial and the agent is told to alert first.
        // Without alert_owner enabled, fall back to {{owner_mobile}} (set by
        // the dynamic-variables webhook) rather than lose transfers.
        $enabled = (array) ($agent->tools_enabled ?? []);
        if (($enabled['transfer_to_owner'] ?? true) === true) {
            $gated = ($enabled['alert_owner'] ?? true) === true;
            $tools[] = [
                'type' => 'transfer',
                'transfer' => [
                    'targets' => [
                        ['name' => 'Owner', 'to' => $gated ? '{{owner_transfer_to}}' : '{{owner_mobile}}'],
                    ],
                    'from' => '{{telnyx_end_user_target}}',
                ],
            ];
        }

        // Native hang-up (voxragtm#84): lets the agent end abusive/spam calls
        // itself; voxraweb additionally schedules a hard PBX hang-up when
        // record_summary carries a spam/abuse outcome, so the call terminates
        // within a bounded time even if the model doesn't use this.
        if (($enabled['hangup'] ?? true) === true) {
            $tools[] = [
                'type' => 'hangup',
                'hangup' => [
                    'description' => 'End the call. Say your goodbye first, then call this without saying anything more — never announce that the call is ending or has ended. Always use it after recording a spam or abuse outcome, and after two check-ins the caller did not answer.',
                ],
            ];
        }

        $body = [
            'tools' => $tools,
            'dynamic_variables_webhook_url' => $dynVarsUrl,
            // Telnyx holds the call — no answer, no greeting — until this
            // webhook replies or times out. voxraweb answers within 1 s
            // (its own deadline); pin the ceiling so the greeting never
            // waits longer, falling back to the assistant's default
            // variables (applyVoxraCallPolicy). First-word latency.
            'dynamic_variables_webhook_timeout_ms' => self::DYNAMIC_VARIABLES_TIMEOUT_MS,
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

    /**
     * Voxra call policy on an assistant (voxragtm#83):
     *  - recording on/off (telephony_settings.recording_settings.enabled) when
     *    $recording is not null — per tenant, from voxraweb settings;
     *  - the greeting can't be talked over, so callers always hear the
     *    AI/recording disclosure in full;
     *  - a quick check-in when the caller goes quiet (USER_IDLE_REPLY_SECS)
     *    and a dead-line backstop (USER_IDLE_TIMEOUT_SECS): speech that
     *    overlaps the greeting's tail is dropped by Telnyx, so the caller is
     *    asked again rather than left in silence;
     *  - a default for the {{recording_notice}} prompt variable, used when the
     *    dynamic-variables webhook doesn't answer in time.
     * Nested settings objects are replaced wholesale by the API, so each is
     * merged into the assistant's current value.
     *
     * POST /v2/ai/assistants/{assistant_id}
     */
    public function applyVoxraCallPolicy(string $assistantId, ?bool $recording): array
    {
        $current = $this->getAssistant($assistantId);

        $telephony = (array) ($current['telephony_settings'] ?? []);
        if ($recording !== null) {
            $rec = (array) ($telephony['recording_settings'] ?? []);
            $rec['enabled'] = $recording;
            $rec += ['channels' => 'dual', 'format' => 'mp3'];
            $telephony['recording_settings'] = $rec;
        }

        $telephony['user_idle_reply_secs'] = self::USER_IDLE_REPLY_SECS;
        $telephony['user_idle_timeout_secs'] = self::USER_IDLE_TIMEOUT_SECS;

        $interruption = (array) ($current['interruption_settings'] ?? []);
        $interruption['disable_greeting_interruption'] = true;

        $vars = (array) ($current['dynamic_variables'] ?? []);
        // Recording unknown (null) keeps the current notice — a prompt/tools
        // re-sync must not flip a recording-off tenant's answer to "recorded".
        if ($recording !== null || !isset($vars['recording_notice'])) {
            $vars['recording_notice'] = $recording === false
                ? 'This call is not audio-recorded, but a written transcript and summary are kept so the business can follow up.'
                : \App\Services\Voxra\VoxraDisclosure::DEFAULT_RECORDING_NOTICE;
        }
        // Default for the prompt's {{urgent_definition}} (voxragtm#122) when
        // the dynamic-variables webhook is slow; voxraweb sends the tenant's own.
        if (empty($vars['urgent_definition'])) {
            $vars['urgent_definition'] = self::DEFAULT_URGENT_DEFINITION;
        }

        $body = [
            'interruption_settings' => $interruption,
            'dynamic_variables' => $vars,
            'telephony_settings' => $telephony,
        ];

        $response = $this->http()->post("v2/ai/assistants/{$assistantId}", $body);
        if (!$response->successful()) {
            logger('Telnyx call policy error: ' . $response->body());
            throw new RuntimeException('Failed to apply Voxra call policy: ' . $this->errorDetail($response));
        }

        return $response->json();
    }

    /**
     * List AI conversations. $filters are Telnyx's PostgREST-style query
     * params, e.g. ['metadata->assistant_id' => 'eq.assistant-…',
     * 'created_at' => 'lt.2026-06-01T00:00:00Z'].
     * GET /v2/ai/conversations
     */
    public function listConversations(array $filters, int $limit = 100): array
    {
        $response = $this->http()->get('v2/ai/conversations', array_merge($filters, [
            'limit' => $limit,
            'order' => 'created_at.asc',
        ]));
        if (!$response->successful()) {
            throw new RuntimeException('Failed to list Telnyx conversations: ' . $this->errorDetail($response));
        }

        return (array) ($response->json('data') ?? []);
    }

    /** DELETE /v2/ai/conversations/{id}. True when gone (incl. already gone). */
    public function deleteConversation(string $conversationId): bool
    {
        $response = $this->http()->delete("v2/ai/conversations/{$conversationId}");

        return $response->successful() || $response->status() === 404;
    }

    /**
     * List call recordings. $filters use Telnyx's filter[...] params, e.g.
     * ['filter[call_leg_id]' => '…'] or ['filter[created_at][lte]' => '…'].
     * GET /v2/recordings
     */
    public function listRecordings(array $filters, int $page = 1, int $pageSize = 250): array
    {
        $response = $this->http()->get('v2/recordings', array_merge($filters, [
            'page[number]' => $page,
            'page[size]' => $pageSize,
        ]));
        if (!$response->successful()) {
            throw new RuntimeException('Failed to list Telnyx recordings: ' . $this->errorDetail($response));
        }

        return [
            'data' => (array) ($response->json('data') ?? []),
            'total_pages' => (int) ($response->json('meta.total_pages') ?? 1),
        ];
    }

    /** DELETE /v2/recordings/{id}. True when gone (incl. already gone). */
    public function deleteRecording(string $recordingId): bool
    {
        $response = $this->http()->delete("v2/recordings/{$recordingId}");

        return $response->successful() || $response->status() === 404;
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
