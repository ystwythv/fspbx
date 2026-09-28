<?php

namespace Tests\Feature;

use App\Models\VoicemailGreetings;
use App\Models\Voicemails;
use App\Services\ProvisionLineService;
use App\Services\Voxra\VoxraLineGreetingSync;
use App\Services\Voxra\VoxraOwnerCallRecording;
use App\Services\Voxra\VoxraSuspendedAnnouncement;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The Voxra Line voicemail greeting on every PBX node (voxragtm#157/#162).
 * The greeting row is replicated but the WAV is node-local, so
 * `voxra:ensure-prompts` rewrites a missing file from the text stored in the
 * row: same TTS (ElevenLabs, then Telnyx), path, name, 16 kHz WAV. It must
 * never write to the database, never touch owner-recorded greetings, and
 * make no TTS call when the file is already there. In-memory sqlite, faked
 * disk, faked TTS endpoints.
 */
class VoxraLineGreetingSyncTest extends TestCase
{
    private const VOICE = 'Xb7hH8MSUJpSbSDYk0k2';
    private const TEXT = "You've reached Acme Plumbing. Please leave a message after the tone.";

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('activitylog.enabled', false);
        Bus::fake();

        Schema::create('v_domains', function ($t) {
            $t->string('domain_uuid')->primary();
            $t->string('domain_parent_uuid')->nullable();
            $t->string('domain_name')->nullable();
            $t->string('domain_enabled')->nullable();
            $t->string('domain_description')->nullable();
        });
        Schema::create('v_extensions', function ($t) {
            $t->string('extension_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('extension')->nullable();
        });
        Schema::create('v_voicemails', function ($t) {
            $t->string('voicemail_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('voicemail_id')->nullable();
            $t->string('greeting_id')->nullable();
        });
        Schema::create('v_voicemail_greetings', function ($t) {
            $t->string('voicemail_greeting_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('voicemail_id')->nullable();
            $t->string('greeting_id')->nullable();
            $t->string('greeting_name')->nullable();
            $t->string('greeting_filename')->nullable();
            $t->text('greeting_description')->nullable();
            $t->text('greeting_base64')->nullable();
            $t->string('insert_date')->nullable();
            $t->string('insert_user')->nullable();
            $t->string('update_date')->nullable();
            $t->string('update_user')->nullable();
        });

        Storage::fake('voicemail');
        config()->set('services.voxra.vm_greeting_voice', self::VOICE);
        config()->set('services.elevenlabs.api_key', 'el-test-key');
        config()->set('services.elevenlabs.base_url', 'https://api.elevenlabs.io');
        // Telnyx fallback off unless a test turns it on (PromptTts)
        config()->set('services.telnyx.api_key', '');
        config()->set('services.telnyx.base_url', 'https://api.telnyx.com');
        config()->set('services.telnyx.tts_voice', 'Azure.en-GB-SoniaNeural');
    }

    private static function pcm(int $marker = 1234): string
    {
        return str_repeat(pack('v', $marker), 4000); // 8000 bytes, 0.25s @ 16kHz
    }

    private function fakeElevenLabs(): void
    {
        Http::fake(['api.elevenlabs.io/*' => Http::response(self::pcm(), 200)]);
    }

    /** A Voxra tenant with the Line box and the greeting Voxra provisioned. */
    private function lineTenant(
        string $uuid = 'dom-1',
        string $name = 'acme.voxra.uk',
        string $text = self::TEXT,
        int $greetingId = 1,
    ): void {
        DB::table('v_domains')->insert([
            'domain_uuid' => $uuid,
            'domain_name' => $name,
            'domain_enabled' => 'true',
            'domain_description' => 'voxra-tenant:' . $uuid,
        ]);
        DB::table('v_extensions')->insert([
            'extension_uuid' => 'ext-' . $uuid, 'domain_uuid' => $uuid, 'extension' => '9260',
        ]);
        DB::table('v_voicemails')->insert([
            'voicemail_uuid' => 'vm-' . $uuid, 'domain_uuid' => $uuid, 'voicemail_id' => '9260',
            'greeting_id' => (string) $greetingId,
        ]);
        DB::table('v_voicemail_greetings')->insert([
            'voicemail_greeting_uuid' => 'g-' . $uuid,
            'domain_uuid' => $uuid,
            'voicemail_id' => '9260',
            'greeting_id' => (string) $greetingId,
            'greeting_name' => ProvisionLineService::GREETING_NAME,
            'greeting_filename' => 'greeting_' . $greetingId . '.wav',
            'greeting_description' => ProvisionLineService::GREETING_HASH_PREFIX
                . VoxraLineGreetingSync::hashFor($text, self::VOICE) . ' ' . $text,
            'insert_date' => '2026-09-25 18:55:00',
            'update_date' => '2026-09-25 18:55:00',
        ]);
    }

    private function sync(): array
    {
        return app(VoxraLineGreetingSync::class)->ensureOnThisNode();
    }

    private function snapshot(): array
    {
        return [
            DB::table('v_voicemail_greetings')->orderBy('voicemail_greeting_uuid')->get()->map(fn ($r) => (array) $r)->all(),
            DB::table('v_voicemails')->orderBy('voicemail_uuid')->get()->map(fn ($r) => (array) $r)->all(),
        ];
    }

    public function test_missing_greeting_is_regenerated_from_the_stored_text(): void
    {
        $this->fakeElevenLabs();
        $this->lineTenant();
        $before = $this->snapshot();

        $results = $this->sync();

        $this->assertSame([[
            'domain' => 'acme.voxra.uk',
            'file' => 'greeting_1.wav',
            'status' => VoxraLineGreetingSync::STATUS_RESTORED,
            'detail' => 'via elevenlabs',
        ]], $results);

        // same path + name, 16 kHz 16-bit mono PCM WAV wrapping the TTS audio
        Storage::disk('voicemail')->assertExists('acme.voxra.uk/9260/greeting_1.wav');
        $wav = Storage::disk('voicemail')->get('acme.voxra.uk/9260/greeting_1.wav');
        $this->assertSame(ProvisionLineService::pcmToWav(self::pcm(), 16000), $wav);
        $fmt = unpack('vformat/vchannels/Vrate/Vbyterate/vblock/vbits', substr($wav, 20, 16));
        $this->assertSame([1, 1, 16000, 16], [$fmt['format'], $fmt['channels'], $fmt['rate'], $fmt['bits']]);

        // spoken from the row's text, in the configured voice
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/text-to-speech/' . self::VOICE)
            && str_contains($r->url(), 'output_format=pcm_16000')
            && $r['text'] === self::TEXT);

        // no rows, ids, hashes or selections changed
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(1, VoicemailGreetings::count());
    }

    public function test_file_permissions_match_freeswitch(): void
    {
        $this->fakeElevenLabs();
        $this->lineTenant();

        $this->sync();

        $path = Storage::disk('voicemail')->path('acme.voxra.uk/9260/greeting_1.wav');
        $this->assertSame('0660', substr(sprintf('%o', fileperms($path)), -4));
        $this->assertSame('0770', substr(sprintf('%o', fileperms(dirname($path))), -4));
    }

    public function test_present_greeting_makes_no_tts_call(): void
    {
        Http::fake();
        $this->lineTenant();
        Storage::disk('voicemail')->put('acme.voxra.uk/9260/greeting_1.wav', 'lon1 copy');

        $results = $this->sync();

        Http::assertNothingSent();
        $this->assertSame(VoxraLineGreetingSync::STATUS_PRESENT, $results[0]['status']);
        $this->assertSame('lon1 copy', Storage::disk('voicemail')->get('acme.voxra.uk/9260/greeting_1.wav'));
    }

    public function test_second_run_is_idempotent(): void
    {
        $this->fakeElevenLabs();
        $this->lineTenant();

        $this->sync();
        $again = $this->sync();

        Http::assertSentCount(1);
        $this->assertSame(VoxraLineGreetingSync::STATUS_PRESENT, $again[0]['status']);
    }

    public function test_keeps_the_rows_greeting_id_and_filename(): void
    {
        // Voxra's greeting sits in slot 3 (the owner had used 1 and 2)
        $this->fakeElevenLabs();
        $this->lineTenant(greetingId: 3);

        $this->sync();

        Storage::disk('voicemail')->assertExists('acme.voxra.uk/9260/greeting_3.wav');
        Storage::disk('voicemail')->assertMissing('acme.voxra.uk/9260/greeting_1.wav');
    }

    public function test_owner_recorded_greetings_are_never_touched(): void
    {
        $this->fakeElevenLabs();
        $this->lineTenant();
        Storage::disk('voicemail')->put('acme.voxra.uk/9260/greeting_1.wav', 'voxra');
        // owner recorded greeting_2 and selected it; its file is missing here
        DB::table('v_voicemail_greetings')->insert([
            'voicemail_greeting_uuid' => 'g-owner',
            'domain_uuid' => 'dom-1',
            'voicemail_id' => '9260',
            'greeting_id' => '2',
            'greeting_name' => 'Greeting 2',
            'greeting_filename' => 'greeting_2.wav',
            'greeting_description' => 'my greeting',
        ]);
        Voicemails::where('voicemail_id', '9260')->update(['greeting_id' => 2]);
        $before = $this->snapshot();

        $results = $this->sync();

        Http::assertNothingSent();
        Storage::disk('voicemail')->assertMissing('acme.voxra.uk/9260/greeting_2.wav');
        $this->assertCount(1, $results); // only Voxra's row was looked at
        $this->assertSame('greeting_1.wav', $results[0]['file']);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_greeting_named_like_ours_without_the_hash_is_skipped(): void
    {
        $this->fakeElevenLabs();
        $this->lineTenant();
        VoicemailGreetings::query()->update(['greeting_description' => 'edited by hand']);

        $this->assertSame([], $this->sync());
        Http::assertNothingSent();
    }

    public function test_non_voxra_domains_are_ignored(): void
    {
        $this->fakeElevenLabs();
        $this->lineTenant();
        DB::table('v_domains')->update(['domain_description' => 'iqmobile customer']);

        $this->assertSame([], $this->sync());
        Http::assertNothingSent();
        Storage::disk('voicemail')->assertMissing('acme.voxra.uk/9260/greeting_1.wav');
    }

    public function test_domains_without_the_line_extension_are_ignored(): void
    {
        $this->fakeElevenLabs();
        $this->lineTenant();
        DB::table('v_extensions')->delete();

        $this->assertSame([], $this->sync());
        Http::assertNothingSent();
    }

    public function test_unexpected_filename_is_never_written(): void
    {
        $this->fakeElevenLabs();
        $this->lineTenant();
        VoicemailGreetings::query()->update(['greeting_filename' => '../../etc/greeting_1.wav']);

        $results = $this->sync();

        $this->assertSame(VoxraLineGreetingSync::STATUS_SKIPPED, $results[0]['status']);
        Http::assertNothingSent();
        $this->assertSame([], Storage::disk('voicemail')->allFiles());
    }

    public function test_falls_back_to_telnyx_when_elevenlabs_fails(): void
    {
        config()->set('services.telnyx.api_key', 'telnyx-test-key');
        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => ['status' => 'quota_exceeded']], 401),
            'api.telnyx.com/*' => Http::response(self::pcm(4321), 200, ['Content-Type' => 'audio/pcm']),
        ]);
        $this->lineTenant();

        $results = $this->sync();

        $this->assertSame(VoxraLineGreetingSync::STATUS_RESTORED, $results[0]['status']);
        $this->assertSame('via telnyx', $results[0]['detail']);
        $this->assertSame(
            ProvisionLineService::pcmToWav(self::pcm(4321), 16000),
            Storage::disk('voicemail')->get('acme.voxra.uk/9260/greeting_1.wav')
        );
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.telnyx.com/v2/text-to-speech/speech')
            && $r['text'] === self::TEXT);
    }

    public function test_one_failing_domain_does_not_stop_the_others(): void
    {
        config()->set('services.telnyx.api_key', 'telnyx-test-key');
        Http::fake([
            // first domain: both providers refuse (401s aren't retried);
            // second domain: ElevenLabs is back
            'api.elevenlabs.io/*' => Http::sequence()
                ->push(['detail' => ['status' => 'quota_exceeded']], 401)
                ->whenEmpty(Http::response(self::pcm(), 200)),
            'api.telnyx.com/*' => Http::sequence()
                ->push(['errors' => [['title' => 'unauthorized']]], 401)
                ->whenEmpty(Http::response(self::pcm(), 200, ['Content-Type' => 'audio/pcm'])),
        ]);
        $this->lineTenant('dom-1', 'acme.voxra.uk');
        $this->lineTenant('dom-2', 'bravo.voxra.uk', "You've reached Bravo Dental. Please leave a message.");

        $results = collect($this->sync())->keyBy('domain');

        $this->assertSame(VoxraLineGreetingSync::STATUS_FAILED, $results['acme.voxra.uk']['status']);
        Storage::disk('voicemail')->assertMissing('acme.voxra.uk/9260/greeting_1.wav');
        $this->assertSame(VoxraLineGreetingSync::STATUS_RESTORED, $results['bravo.voxra.uk']['status']);
        $this->assertSame('via elevenlabs', $results['bravo.voxra.uk']['detail']);
        Storage::disk('voicemail')->assertExists('bravo.voxra.uk/9260/greeting_1.wav');
    }

    public function test_changed_voice_still_restores_and_keeps_the_hash(): void
    {
        $this->fakeElevenLabs();
        $this->lineTenant();
        config()->set('services.voxra.vm_greeting_voice', 'NewVoiceId123');
        $before = $this->snapshot();

        $results = $this->sync();

        $this->assertSame(VoxraLineGreetingSync::STATUS_RESTORED, $results[0]['status']);
        $this->assertStringContainsString('hash kept', $results[0]['detail']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/text-to-speech/NewVoiceId123'));
        $this->assertSame($before, $this->snapshot());
    }

    public function test_parse_description(): void
    {
        $this->assertSame(
            ['hash' => '1062b7f42246fe9d', 'text' => "You've reached Josies Dentistry. Leave a message."],
            VoxraLineGreetingSync::parseDescription("voxra-tts:1062b7f42246fe9d You've reached Josies Dentistry. Leave a message.")
        );
        $this->assertNull(VoxraLineGreetingSync::parseDescription('voxra-tts:nothex You there'));
        $this->assertNull(VoxraLineGreetingSync::parseDescription('voxra-tts:1062b7f42246fe9d '));
        $this->assertNull(VoxraLineGreetingSync::parseDescription('Greeting 1'));
    }

    public function test_ensure_prompts_command_restores_and_reports_the_provider(): void
    {
        $this->mock(VoxraOwnerCallRecording::class, fn ($m) => $m->shouldReceive('ensurePrompt')->andReturn('/rec/voxra/a.wav'));
        $this->mock(VoxraSuspendedAnnouncement::class, fn ($m) => $m->shouldReceive('ensure')->andReturn('/rec/voxra/b.wav'));
        $this->fakeElevenLabs();
        $this->lineTenant();

        $this->artisan('voxra:ensure-prompts')
            ->expectsOutputToContain('line greeting acme.voxra.uk/greeting_1.wav: restored (via elevenlabs)')
            ->assertExitCode(0);

        Storage::disk('voicemail')->assertExists('acme.voxra.uk/9260/greeting_1.wav');
    }
}
