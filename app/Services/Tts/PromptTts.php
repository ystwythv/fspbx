<?php

namespace App\Services\Tts;

use RuntimeException;

/**
 * Raw 16 kHz 16-bit mono PCM for the PBX's generated prompts (the Line
 * voicemail greeting, the shared Voxra prompts from `voxra:ensure-prompts`),
 * ready for ProvisionLineService::pcmToWav.
 *
 * ElevenLabs first (the configured voice id). When it fails for any reason
 * (no key, quota_exceeded / auth 401, 5xx after retries, connection error,
 * implausibly short audio) it falls back to Telnyx TTS (TELNYX_API_KEY,
 * voice TELNYX_TTS_VOICE, default Azure en-GB Sonia), so an exhausted
 * ElevenLabs key no longer leaves tenants on the stock greeting and the
 * shared prompts missing.
 *
 * Callers keep their file names/hashes (text + ElevenLabs voice id), so a
 * file made by the fallback is not regenerated once ElevenLabs recovers;
 * delete it to re-voice it.
 */
class PromptTts
{
    /** Anything shorter than this isn't speech (16 kHz 16-bit: ~31 ms). */
    public const MIN_BYTES = 1000;

    /**
     * @param  string  $voice  ElevenLabs voice id (the Telnyx fallback uses
     *                         its own configured voice)
     * @param  string  $label  what is being generated, for the log
     * @return array{pcm: string, provider: string}
     *
     * @throws RuntimeException when every provider fails
     */
    public function pcm16k(string $text, string $voice, string $label = 'prompt'): array
    {
        try {
            $pcm = (new ElevenLabsTtsService())->textToSpeech($text, [
                'voice' => $voice,
                'response_format' => 'pcm',
            ]);
            self::assertPlausible($pcm, 'ElevenLabs');

            return ['pcm' => $pcm, 'provider' => 'elevenlabs'];
        } catch (\Throwable $e) {
            logger()->warning('Voxra TTS: ElevenLabs failed for ' . $label . ', falling back to Telnyx: ' . $e->getMessage());
            $elevenLabsError = $e->getMessage();
        }

        try {
            $pcm = (new TelnyxTtsService())->textToSpeech($text, ['response_format' => 'pcm']);
            self::assertPlausible($pcm, 'Telnyx');

            return ['pcm' => $pcm, 'provider' => 'telnyx'];
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'all TTS providers failed (ElevenLabs: ' . $elevenLabsError . '; Telnyx: ' . $e->getMessage() . ')',
                0,
                $e
            );
        }
    }

    private static function assertPlausible(string $pcm, string $provider): void
    {
        if (strlen($pcm) < self::MIN_BYTES) {
            throw new RuntimeException($provider . ' returned implausibly short audio (' . strlen($pcm) . ' bytes)');
        }
    }
}
