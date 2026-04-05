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
     * Create a SIP trunk phone number in ElevenLabs.
     */
    public function createSipTrunkPhoneNumber(string $label, string $phoneNumber): array
    {
        $body = [
            'phone_number' => $phoneNumber,
            'label' => $label,
        ];

        $response = $this->http()->post('v1/convai/phone-numbers', $body);

        if (!$response->successful()) {
            logger('ElevenLabs create SIP phone number error: ' . $response->body());
            throw new RuntimeException('Failed to create ElevenLabs SIP phone number: ' . ($response->json('detail.message') ?? $response->body()));
        }

        return $response->json();
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
