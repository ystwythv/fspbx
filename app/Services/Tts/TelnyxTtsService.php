<?php

namespace App\Services\Tts;

use RuntimeException;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use App\Services\Interfaces\TtsProviderInterface;

/**
 * Telnyx REST text-to-speech (POST /v2/text-to-speech/speech), on the same
 * Telnyx account (EU data locality) and TELNYX_API_KEY the PBX already uses.
 * Synchronous: the response body is the audio.
 *
 * Voices are "Provider.Model.VoiceId" strings from Telnyx's catalogue
 * (GET /v2/text-to-speech/voices). The default is Azure's en-GB Sonia, a
 * natural British English neural voice, which Telnyx can return as raw
 * 16 kHz 16-bit mono PCM, the format ProvisionLineService::pcmToWav wraps
 * for FreeSWITCH. Only Azure (azure.*) and AWS Polly (aws.polly.*) voices
 * can return raw PCM; any voice that answers with something other than
 * audio/pcm for a 'pcm' request is rejected instead of being written out as
 * noise.
 */
class TelnyxTtsService implements TtsProviderInterface
{
    public const DEFAULT_VOICE = 'Azure.en-GB-SoniaNeural';

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

    public function textToSpeech(string $input, array $options = []): string
    {
        $voice  = (string) ($options['voice'] ?? '') ?: $this->getDefaultVoice();
        $format = (string) ($options['response_format'] ?? 'pcm');

        $body = [
            'text'  => $input,
            'voice' => $voice,
        ];
        $settings = self::voiceSettings($voice, $format);
        if ($settings) {
            $body['voice_settings'] = $settings;
        }

        $response = $this->http()->post('v2/text-to-speech/speech', $body);

        if (! $response->successful()) {
            logger('Telnyx TTS error: ' . $response->body());
            throw new RuntimeException('Telnyx TTS failed (HTTP ' . $response->status() . '): '
                . ($response->json('errors.0.detail') ?? $response->json('errors.0.title') ?? substr($response->body(), 0, 300)));
        }

        $contentType = strtolower((string) $response->header('Content-Type'));
        if ($format === 'pcm' && ! str_starts_with($contentType, 'audio/pcm')) {
            throw new RuntimeException("Telnyx TTS returned {$contentType} for voice {$voice}, not raw PCM (use an azure.* or aws.polly.* voice)");
        }

        return $response->body();
    }

    /**
     * Provider-specific voice_settings asking for the requested format.
     * 'pcm' = raw 16 kHz signed 16-bit little-endian mono.
     */
    public static function voiceSettings(string $voice, string $format): array
    {
        $provider = strtolower(strtok($voice, '.') ?: '');

        if ($provider === 'azure') {
            return ['output_format' => match ($format) {
                'pcm'   => 'raw-16khz-16bit-mono-pcm',
                'wav'   => 'riff-16khz-16bit-mono-pcm',
                'ulaw'  => 'raw-8khz-8bit-mono-mulaw',
                default => 'audio-24khz-160kbitrate-mono-mp3',
            }];
        }

        if ($provider === 'aws') {
            return match ($format) {
                'pcm'   => ['output_format' => 'pcm', 'sample_rate' => '16000'],
                default => ['output_format' => 'mp3'],
            };
        }

        return [];
    }

    public function getVoices(): array
    {
        return [
            ['value' => 'Azure.en-GB-SoniaNeural', 'label' => 'Sonia (British, female)'],
            ['value' => 'Azure.en-GB-LibbyNeural', 'label' => 'Libby (British, female)'],
            ['value' => 'Azure.en-GB-RyanNeural', 'label' => 'Ryan (British, male)'],
            ['value' => 'AWS.Polly.Amy-Neural', 'label' => 'Amy (British, female, Polly)'],
            ['value' => 'AWS.Polly.Arthur-Neural', 'label' => 'Arthur (British, male, Polly)'],
        ];
    }

    public function getDefaultVoice(): ?string
    {
        return (string) config('services.telnyx.tts_voice', self::DEFAULT_VOICE) ?: self::DEFAULT_VOICE;
    }

    public function getSpeeds(): array
    {
        return [];
    }

    public function getOutputFormats(): array
    {
        return [
            ['value' => 'wav', 'label' => 'WAV 16kHz'],
            ['value' => 'mp3', 'label' => 'MP3'],
            ['value' => 'pcm', 'label' => 'PCM 16kHz'],
        ];
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl($this->baseUrl . '/')
            ->timeout($this->timeout)
            ->withToken($this->apiKey)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->retry(
                3,
                500,
                function ($exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }
                    $response = method_exists($exception, 'response') ? $exception->response() : null;
                    return in_array($response?->status(), [429, 500, 502, 503, 504], true);
                },
                throw: false
            );
    }
}
