<?php

namespace Tests\Unit\Voxra;

use App\Services\Tts\PromptTts;
use App\Services\Tts\TelnyxTtsService;
use App\Services\Voxra\VoxraTtsPrompt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * TTS fallback for the PBX's generated prompts (28 Sept: the capped
 * ElevenLabs key ran out of credits, so Line greetings fell back to the stock
 * phrase and `voxra:ensure-prompts` couldn't make the shared prompts).
 * ElevenLabs first; on any failure Telnyx TTS (Azure en-GB voice, raw 16 kHz
 * PCM) produces the same file at the same path. No real API is ever hit.
 */
class PromptTtsFallbackTest extends TestCase
{
    private const EL_VOICE = 'Xb7hH8MSUJpSbSDYk0k2';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.elevenlabs.api_key', 'el-test-key');
        config()->set('services.elevenlabs.base_url', 'https://api.elevenlabs.io');
        config()->set('services.telnyx.api_key', 'telnyx-test-key');
        config()->set('services.telnyx.base_url', 'https://api.telnyx.com');
        config()->set('services.telnyx.tts_voice', 'Azure.en-GB-SoniaNeural');
    }

    private static function pcm(int $marker = 1234): string
    {
        return str_repeat(pack('v', $marker), 4000); // 8000 bytes, 0.25s @ 16kHz
    }

    private static function quotaExceeded(): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(['detail' => [
            'status' => 'quota_exceeded',
            'message' => 'This request exceeds your quota of 1000. You have 2 credits remaining.',
        ]], 401);
    }

    private static function telnyxPcm(int $marker = 777): \GuzzleHttp\Promise\PromiseInterface
    {
        return Http::response(self::pcm($marker), 200, ['Content-Type' => 'audio/pcm']);
    }

    public function test_elevenlabs_success_uses_elevenlabs_only(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(self::pcm(), 200),
            'api.telnyx.com/*' => self::telnyxPcm(),
        ]);

        $out = (new PromptTts())->pcm16k('This call may be recorded.', self::EL_VOICE);

        $this->assertSame('elevenlabs', $out['provider']);
        $this->assertSame(self::pcm(), $out['pcm']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'api.telnyx.com'));
    }

    public function test_quota_exceeded_falls_back_to_telnyx(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => self::quotaExceeded(),
            'api.telnyx.com/*' => self::telnyxPcm(),
        ]);

        $out = (new PromptTts())->pcm16k('This call may be recorded.', self::EL_VOICE);

        $this->assertSame('telnyx', $out['provider']);
        $this->assertSame(self::pcm(777), $out['pcm']);
        Http::assertSent(fn ($r) => $r->url() === 'https://api.telnyx.com/v2/text-to-speech/speech'
            && $r->method() === 'POST'
            && $r->hasHeader('Authorization', 'Bearer telnyx-test-key')
            && $r['text'] === 'This call may be recorded.'
            && $r['voice'] === 'Azure.en-GB-SoniaNeural'
            && $r['voice_settings'] === ['output_format' => 'raw-16khz-16bit-mono-pcm']);
    }

    public function test_server_error_falls_back_to_telnyx(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => Http::response('upstream error', 503),
            'api.telnyx.com/*' => self::telnyxPcm(),
        ]);

        $this->assertSame('telnyx', (new PromptTts())->pcm16k('Hello.', self::EL_VOICE)['provider']);
    }

    public function test_missing_elevenlabs_key_falls_back_to_telnyx(): void
    {
        config()->set('services.elevenlabs.api_key', '');
        Http::fake(['api.telnyx.com/*' => self::telnyxPcm()]);

        $this->assertSame('telnyx', (new PromptTts())->pcm16k('Hello.', self::EL_VOICE)['provider']);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'elevenlabs'));
    }

    public function test_implausibly_short_elevenlabs_audio_falls_back(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => Http::response('xx', 200),
            'api.telnyx.com/*' => self::telnyxPcm(),
        ]);

        $this->assertSame('telnyx', (new PromptTts())->pcm16k('Hello.', self::EL_VOICE)['provider']);
    }

    public function test_both_failing_throws_with_both_reasons(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => self::quotaExceeded(),
            'api.telnyx.com/*' => Http::response(['errors' => [['detail' => 'Invalid voice']]], 400),
        ]);

        try {
            (new PromptTts())->pcm16k('Hello.', self::EL_VOICE);
            $this->fail('expected a RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ElevenLabs', $e->getMessage());
            $this->assertStringContainsString('2 credits remaining', $e->getMessage());
            $this->assertStringContainsString('Invalid voice', $e->getMessage());
        }
    }

    public function test_non_pcm_telnyx_response_is_rejected(): void
    {
        // e.g. a Telnyx.Ultra voice, which only returns MP3: never write MP3
        // bytes into a PCM WAV
        config()->set('services.telnyx.tts_voice', 'Telnyx.Ultra.2f251ac3-89a9-4a77-a452-704b474ccd01');
        Http::fake([
            'api.elevenlabs.io/*' => self::quotaExceeded(),
            'api.telnyx.com/*' => Http::response(self::pcm(), 200, ['Content-Type' => 'audio/mpeg']),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not raw PCM');
        (new PromptTts())->pcm16k('Hello.', self::EL_VOICE);
    }

    public function test_voice_settings_per_provider(): void
    {
        $this->assertSame(
            ['output_format' => 'raw-16khz-16bit-mono-pcm'],
            TelnyxTtsService::voiceSettings('Azure.en-GB-SoniaNeural', 'pcm')
        );
        $this->assertSame(
            ['output_format' => 'pcm', 'sample_rate' => '16000'],
            TelnyxTtsService::voiceSettings('AWS.Polly.Amy-Neural', 'pcm')
        );
        $this->assertSame([], TelnyxTtsService::voiceSettings('Telnyx.KokoroTTS.bf_emma', 'pcm'));
    }

    public function test_shared_prompt_is_written_at_the_same_path_via_the_fallback(): void
    {
        Storage::fake('recordings');
        Log::spy();
        Http::fake([
            'api.elevenlabs.io/*' => self::quotaExceeded(),
            'api.telnyx.com/*' => self::telnyxPcm(),
        ]);

        $text = 'This number is temporarily unavailable. Please try again later.';
        $path = VoxraTtsPrompt::ensure('suspended', $text, self::EL_VOICE, 'suspended-number announcement');

        // same text+voice-hashed name as an ElevenLabs-made prompt, so
        // ensure-prompts finds it next time instead of regenerating
        $relative = VoxraTtsPrompt::relativePath('suspended', $text, self::EL_VOICE);
        $this->assertSame(Storage::disk('recordings')->path($relative), $path);
        $wav = Storage::disk('recordings')->get($relative);
        $this->assertSame('RIFF', substr($wav, 0, 4));
        $fmt = unpack('vformat/vchannels/Vrate/Vbyterate/vblock/vbits', substr($wav, 20, 16));
        $this->assertSame([1, 1, 16000, 32000, 2, 16], array_values($fmt));
        $this->assertSame(self::pcm(777), substr($wav, 44));

        // the log says which provider made the file
        Log::shouldHaveReceived('debug')->withArgs(
            fn ($msg) => str_contains($msg, 'suspended-number announcement generated') && str_ends_with($msg, 'via telnyx')
        )->once();

        // present → no further TTS calls
        $sent = count(Http::recorded());
        $this->assertSame($path, VoxraTtsPrompt::ensure('suspended', $text, self::EL_VOICE, 'suspended-number announcement'));
        $this->assertCount($sent, Http::recorded());
    }

    public function test_shared_prompt_returns_null_when_every_provider_fails(): void
    {
        Storage::fake('recordings');
        config()->set('services.elevenlabs.api_key', '');
        config()->set('services.telnyx.api_key', '');
        Http::fake();

        $this->assertNull(VoxraTtsPrompt::ensure('suspended', 'Unavailable.', self::EL_VOICE, 'suspended-number announcement'));
        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('recordings')->allFiles());
    }
}
