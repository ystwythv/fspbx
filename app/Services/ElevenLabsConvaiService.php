<?php

namespace App\Services;

use RuntimeException;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

class ElevenLabsConvaiService
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey  = (string) config('services.elevenlabs.api_key', '');
        $this->baseUrl = rtrim((string) config('services.elevenlabs.base_url', 'https://api.elevenlabs.io'), '/');
        $this->timeout = (int) config('services.elevenlabs.timeout', 60);

        if ($this->apiKey === '') {
            throw new RuntimeException('ElevenLabs API key is not configured. Please set ELEVENLABS_API_KEY in your environment file.');
        }
    }

    /**
     * Create a new ElevenLabs Conversational AI agent.
     */
    public function createAgent(string $name, ?string $systemPrompt, ?string $firstMessage, ?string $voiceId, string $language = 'en'): array
    {
        $body = [
            'name' => $name,
            'conversation_config' => [
                'agent' => [
                    'prompt' => [
                        'prompt' => $systemPrompt ?? '',
                    ],
                    'first_message' => $firstMessage ?? '',
                    'language' => $language,
                ],
                'tts' => array_filter([
                    'voice_id' => $voiceId,
                ]),
            ],
        ];

        $response = $this->http()->post('v1/convai/agents/create', $body);

        if (!$response->successful()) {
            logger('ElevenLabs create agent error: ' . $response->body());
            throw new RuntimeException('Failed to create ElevenLabs agent: ' . ($response->json('detail.message') ?? $response->body()));
        }

        return $response->json();
    }

    /**
     * Update an existing ElevenLabs agent.
     */
    public function updateAgent(string $agentId, ?string $name, ?string $systemPrompt, ?string $firstMessage, ?string $voiceId, string $language = 'en'): array
    {
        $body = [
            'name' => $name,
            'conversation_config' => [
                'agent' => [
                    'prompt' => [
                        'prompt' => $systemPrompt ?? '',
                    ],
                    'first_message' => $firstMessage ?? '',
                    'language' => $language,
                ],
                'tts' => array_filter([
                    'voice_id' => $voiceId,
                ]),
            ],
        ];

        $response = $this->http()->patch("v1/convai/agents/{$agentId}", $body);

        if (!$response->successful()) {
            logger('ElevenLabs update agent error: ' . $response->body());
            throw new RuntimeException('Failed to update ElevenLabs agent: ' . ($response->json('detail.message') ?? $response->body()));
        }

        return $response->json();
    }

    /**
     * Delete an ElevenLabs agent.
     */
    public function deleteAgent(string $agentId): void
    {
        $response = $this->http()->delete("v1/convai/agents/{$agentId}");

        if (!$response->successful() && $response->status() !== 404) {
            logger('ElevenLabs delete agent error: ' . $response->body());
            throw new RuntimeException('Failed to delete ElevenLabs agent: ' . $response->body());
        }
    }

    /**
     * Get an ElevenLabs agent's details.
     */
    public function getAgent(string $agentId): array
    {
        $response = $this->http()->get("v1/convai/agents/{$agentId}");

        if (!$response->successful()) {
            throw new RuntimeException('Failed to get ElevenLabs agent: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Create an inbound SIP trunk phone number in ElevenLabs.
     *
     * Without an `inbound_trunk_config.allowed_addresses` allowlist the
     * resulting number will not accept inbound SIP — INVITEs reach
     * sip.rtc.elevenlabs.io, get 100 Processing, then are silently dropped.
     */
    public function createSipTrunkPhoneNumber(
        string $label,
        string $phoneNumber,
        array $allowedAddresses = [],
        string $mediaEncryption = 'allowed'
    ): array {
        $body = [
            'phone_number' => $phoneNumber,
            'label'        => $label,
            'provider'     => 'sip_trunk',
        ];

        if (!empty($allowedAddresses)) {
            $body['inbound_trunk_config'] = [
                'allowed_addresses' => array_values($allowedAddresses),
                'media_encryption'  => $mediaEncryption,
            ];
        }

        $response = $this->http()->post('v1/convai/phone-numbers', $body);

        if (!$response->successful()) {
            logger('ElevenLabs create SIP phone number error: ' . $response->body());
            throw new RuntimeException('Failed to create ElevenLabs SIP phone number: ' . ($response->json('detail.message') ?? $response->body()));
        }

        return $response->json();
    }

    /**
     * Replace an existing ElevenLabs SIP trunk phone number with a freshly
     * configured one (delete + recreate + reassign agent). Used by the
     * ai-agents:resync-elevenlabs artisan command to backfill agents that
     * were created before inbound_trunk_config support existed.
     */
    public function replaceSipTrunkPhoneNumber(
        ?string $existingPhoneNumberId,
        string $label,
        string $phoneNumber,
        array $allowedAddresses,
        string $agentId
    ): array {
        if ($existingPhoneNumberId) {
            $this->deletePhoneNumber($existingPhoneNumberId);
        }

        $created = $this->createSipTrunkPhoneNumber($label, $phoneNumber, $allowedAddresses);

        $newId = $created['phone_number_id'] ?? null;
        if ($newId && $agentId) {
            $this->assignAgentToPhoneNumber($newId, $agentId);
        }

        return $created;
    }

    /**
     * Assign an agent to a phone number.
     */
    public function assignAgentToPhoneNumber(string $phoneNumberId, string $agentId): void
    {
        $response = $this->http()->patch("v1/convai/phone-numbers/{$phoneNumberId}", [
            'agent_id' => $agentId,
        ]);

        if (!$response->successful()) {
            logger('ElevenLabs assign agent error: ' . $response->body());
            throw new RuntimeException('Failed to assign agent to phone number: ' . ($response->json('detail.message') ?? $response->body()));
        }
    }

    /**
     * Delete a phone number from ElevenLabs.
     */
    public function deletePhoneNumber(string $phoneNumberId): void
    {
        $response = $this->http()->delete("v1/convai/phone-numbers/{$phoneNumberId}");

        if (!$response->successful() && $response->status() !== 404) {
            logger('ElevenLabs delete phone number error: ' . $response->body());
        }
    }

    /**
     * Upload a file to the ElevenLabs knowledge base.
     * POST /v1/convai/knowledge-base/file
     */
    public function uploadKbFile(string $filePath, string $name): array
    {
        $response = $this->httpMultipart()
            ->attach('file', file_get_contents($filePath), basename($filePath))
            ->post('v1/convai/knowledge-base/file', [
                ['name' => 'name', 'contents' => $name],
            ]);

        if (!$response->successful()) {
            logger('ElevenLabs KB file upload error: ' . $response->body());
            throw new RuntimeException('Failed to upload KB file: ' . ($response->json('detail.message') ?? $response->body()));
        }

        return $response->json();
    }

    /**
     * Add a URL document to the ElevenLabs knowledge base.
     * POST /v1/convai/knowledge-base/url
     */
    public function addKbUrl(string $url, string $name): array
    {
        $response = $this->http()->post('v1/convai/knowledge-base/url', [
            'url'  => $url,
            'name' => $name,
        ]);

        if (!$response->successful()) {
            logger('ElevenLabs KB url add error: ' . $response->body());
            throw new RuntimeException('Failed to add KB url: ' . ($response->json('detail.message') ?? $response->body()));
        }

        return $response->json();
    }

    /**
     * Add a text snippet to the ElevenLabs knowledge base.
     * POST /v1/convai/knowledge-base/text
     */
    public function addKbText(string $text, string $name): array
    {
        $response = $this->http()->post('v1/convai/knowledge-base/text', [
            'text' => $text,
            'name' => $name,
        ]);

        if (!$response->successful()) {
            logger('ElevenLabs KB text add error: ' . $response->body());
            throw new RuntimeException('Failed to add KB text: ' . ($response->json('detail.message') ?? $response->body()));
        }

        return $response->json();
    }

    /**
     * Delete a knowledge base document.
     * DELETE /v1/convai/knowledge-base/{documentation_id}
     */
    public function deleteKbDocument(string $documentationId): void
    {
        $response = $this->http()->delete("v1/convai/knowledge-base/{$documentationId}");

        if (!$response->successful() && $response->status() !== 404) {
            logger('ElevenLabs KB delete error: ' . $response->body());
            throw new RuntimeException('Failed to delete KB document: ' . $response->body());
        }
    }

    /**
     * Set the agent's knowledge base array.
     * Each entry: ['type' => 'file'|'url'|'text', 'id' => $documentationId, 'name' => $name]
     * PATCH /v1/convai/agents/{agent_id}
     */
    public function setAgentKnowledgeBase(string $agentId, array $documents): array
    {
        $body = [
            'conversation_config' => [
                'agent' => [
                    'prompt' => [
                        'knowledge_base' => array_values(array_map(function ($d) {
                            return [
                                'type' => $d['type'],
                                'id'   => $d['id'],
                                'name' => $d['name'],
                            ];
                        }, $documents)),
                    ],
                ],
            ],
        ];

        $response = $this->http()->patch("v1/convai/agents/{$agentId}", $body);

        if (!$response->successful()) {
            logger('ElevenLabs set agent KB error: ' . $response->body());
            throw new RuntimeException('Failed to set agent knowledge base: ' . ($response->json('detail.message') ?? $response->body()));
        }

        return $response->json();
    }

    /**
     * Register/update the tool surface for a reception-mode AI agent. Tools
     * map to the single dispatch endpoint /webhooks/voxra/reception-agent/tool;
     * which tools are advertised to ElevenLabs is controlled by the agent's
     * `tools_enabled` flag map (set in the admin UI).
     *
     * Idempotent — call on every save.
     */
    public function syncReceptionAgentTools(\App\Models\AiAgent $agent): array
    {
        if (!$agent->elevenlabs_agent_id) {
            throw new RuntimeException('Agent has no ElevenLabs id; create the agent first');
        }

        $base = rtrim((string) config('services.elevenlabs.tool_webhook_base_url', config('app.url') ?? ''), '/');
        if ($base === '') {
            throw new RuntimeException('ELEVENLABS_TOOL_WEBHOOK_BASE_URL or APP_URL must be set');
        }
        $url = $base . '/webhooks/voxra/reception-agent/tool';

        $tools = $this->buildReceptionAgentToolDefinitions($url, (array) ($agent->tools_enabled ?? []));

        $body = [
            'conversation_config' => [
                'agent' => [
                    'prompt' => [
                        'tools' => $tools,
                    ],
                ],
            ],
        ];

        $response = $this->http()->patch("v1/convai/agents/{$agent->elevenlabs_agent_id}", $body);

        if (!$response->successful()) {
            logger('ElevenLabs sync reception tools error: ' . $response->body());
            throw new RuntimeException('Failed to sync reception agent tools: ' . ($response->json('detail.message') ?? $response->body()));
        }

        return $response->json();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildReceptionAgentToolDefinitions(string $webhookUrl, array $enabled): array
    {
        $defs = [];
        $headers = [
            ['name' => 'Content-Type', 'value' => 'application/json'],
        ];

        $make = function (string $name, string $description, array $properties, array $required = []) use ($webhookUrl, $headers) {
            return [
                'type' => 'webhook',
                'name' => $name,
                'description' => $description,
                'api_schema' => [
                    'url' => $webhookUrl,
                    'method' => 'POST',
                    'request_headers' => $headers,
                    'request_body_schema' => [
                        'type' => 'object',
                        'properties' => array_merge([
                            'tool_name' => ['type' => 'string', 'enum' => [$name]],
                            'conversation_id' => ['type' => 'string'],
                        ], $properties),
                        'required' => array_values(array_unique(array_merge(['tool_name', 'conversation_id'], $required))),
                    ],
                ],
            ];
        };

        if ($enabled['lookup_user'] ?? true) {
            $defs[] = $make('lookup_user', 'Find a colleague by name in the directory; returns extension and full name.', [
                'query' => ['type' => 'string', 'description' => 'Person name or extension to search for'],
            ], ['query']);
        }
        if ($enabled['transfer_call'] ?? true) {
            $defs[] = $make('transfer_call', 'Blind-transfer the held call to an extension and exit.', [
                'extension' => ['type' => 'string', 'description' => 'Target extension number'],
            ], ['extension']);
        }
        if ($enabled['announced_transfer'] ?? true) {
            $defs[] = $make('announced_transfer', 'Announced transfer: ring the target, intro the call to them, then drop yourself when the summoner hangs up so the original caller is connected.', [
                'extension' => ['type' => 'string', 'description' => 'Target extension number'],
            ], ['extension']);
        }
        if ($enabled['park_call'] ?? true) {
            $defs[] = $make('park_call', 'Park the held call to a parking slot and read back the slot number.', []);
        }
        if ($enabled['bring_back'] ?? true) {
            $defs[] = $make('bring_back', 'Retrieve a previously parked call by slot number.', [
                'slot' => ['type' => 'string', 'description' => 'Park slot number to retrieve'],
            ], ['slot']);
        }
        if ($enabled['three_way_add'] ?? true) {
            $defs[] = $make('three_way_add', 'Add another extension to the current call as a three-way participant.', [
                'extension' => ['type' => 'string', 'description' => 'Extension to add to the call'],
            ], ['extension']);
        }
        if ($enabled['complete_and_exit'] ?? true) {
            $defs[] = $make('complete_and_exit', 'Call this once you have completed the user\'s request to leave the call cleanly.', [
                'message' => ['type' => 'string', 'description' => 'Optional final spoken message before exiting'],
            ]);
        }
        if ($enabled['get_time_in_city'] ?? true) {
            $defs[] = $make('get_time_in_city', 'Get the current local time in a named city.', [
                'city' => ['type' => 'string', 'description' => 'City name (e.g. New York, Tokyo)'],
            ], ['city']);
        }
        if ($enabled['get_weather'] ?? true) {
            $defs[] = $make('get_weather', 'Get the current weather in a named city.', [
                'city' => ['type' => 'string', 'description' => 'City name'],
            ], ['city']);
        }

        return $defs;
    }

    private function httpMultipart(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl($this->baseUrl . '/')
            ->timeout($this->timeout)
            ->withHeaders([
                'xi-api-key' => $this->apiKey,
                'Accept'     => 'application/json',
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

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl($this->baseUrl . '/')
            ->timeout($this->timeout)
            ->withHeaders([
                'xi-api-key'   => $this->apiKey,
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
