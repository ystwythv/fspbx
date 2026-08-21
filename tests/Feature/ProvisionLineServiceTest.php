<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Extensions;
use App\Models\FollowMe;
use App\Models\FollowMeDestinations;
use App\Models\VoicemailGreetings;
use App\Models\Voicemails;
use App\Services\ProvisionLineService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * ensureLineExtension (voxragtm#25) against an in-memory sqlite schema:
 * provisioning creates extension + follow-me + voicemail rows, re-running is
 * idempotent (no duplicates), and a missing owner_mobile yields a
 * straight-to-voicemail line that a later re-provision can upgrade.
 *
 * Branded TTS greeting (voxragtm#110): a faked ElevenLabs endpoint returns
 * raw PCM; the service must write greeting_1.wav (valid RIFF/WAVE), create
 * the marked v_voicemail_greetings row, select it on the box, skip when the
 * text+voice hash is unchanged, never touch owner-recorded greetings, and
 * degrade gracefully when the key is missing or TTS fails.
 */
class ProvisionLineServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('activitylog.enabled', false);

        // model events fan out to presence jobs / user-sync listeners that
        // need infrastructure this schema doesn't model
        Bus::fake();
        Event::fake([\App\Events\ExtensionUpdated::class]);

        $this->createSchema();

        // greeting generation: fake disk + key present — each greeting test
        // fakes the ElevenLabs endpoint itself; tests never hit the real API
        Storage::fake('voicemail');
        config()->set('services.elevenlabs.api_key', 'test-key');
    }

    /** Fake the ElevenLabs TTS endpoint with a raw-PCM success response. */
    private function fakeTts(): void
    {
        Http::fake([
            'api.elevenlabs.io/*' => Http::response($this->fakePcm(), 200),
        ]);
    }

    /** Raw 16-bit mono PCM as ElevenLabs pcm_16000 would return it. */
    private function fakePcm(): string
    {
        return str_repeat(pack('v', 1234), 4000); // 8000 bytes, 0.25s @ 16kHz
    }

    private function createSchema(): void
    {
        Schema::create('v_extensions', function ($t) {
            $t->string('extension_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('extension')->nullable();
            $t->string('password')->nullable();
            $t->string('user_context')->nullable();
            $t->string('effective_caller_id_name')->nullable();
            $t->string('effective_caller_id_number')->nullable();
            $t->string('call_timeout')->nullable();
            $t->string('directory_visible')->nullable();
            $t->string('directory_exten_visible')->nullable();
            $t->string('enabled')->nullable();
            $t->string('description')->nullable();
            $t->string('follow_me_uuid')->nullable();
            $t->string('follow_me_enabled')->nullable();
            $t->string('do_not_disturb')->nullable();
            $t->string('ring_target')->nullable();
            $t->string('call_screen_enabled')->nullable();
            $t->string('limit_max')->nullable();
            $t->string('limit_destination')->nullable();
            $t->string('force_ping')->nullable();
            $t->string('user_record')->nullable();
            $t->string('insert_date')->nullable();
        });
        Schema::create('extension_advanced_settings', function ($t) {
            $t->string('uuid')->primary();
            $t->string('extension_uuid')->nullable();
            $t->string('suspended')->nullable();
        });
        Schema::create('v_follow_me', function ($t) {
            $t->string('follow_me_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('follow_me_enabled')->nullable();
            $t->string('insert_date')->nullable();
            $t->string('insert_user')->nullable();
        });
        Schema::create('v_follow_me_destinations', function ($t) {
            $t->string('follow_me_destination_uuid')->primary();
            $t->string('follow_me_uuid')->nullable();
            $t->string('domain_uuid')->nullable();
            $t->string('follow_me_destination')->nullable();
            $t->string('follow_me_delay')->nullable();
            $t->string('follow_me_timeout')->nullable();
            $t->string('follow_me_prompt')->nullable();
            $t->string('follow_me_order')->nullable();
            $t->string('insert_date')->nullable();
            $t->string('insert_user')->nullable();
        });
        Schema::create('v_voicemails', function ($t) {
            $t->string('voicemail_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('voicemail_id')->nullable();
            $t->string('voicemail_password')->nullable();
            $t->string('voicemail_tutorial')->nullable();
            $t->string('voicemail_description')->nullable();
            $t->string('voicemail_enabled')->nullable();
            $t->string('voicemail_transcription_enabled')->nullable();
            $t->string('voicemail_mail_to')->nullable();
            $t->string('greeting_id')->nullable();
            $t->string('insert_date')->nullable();
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
        // isHostedNumber loop guard + FusionCache settings lookup
        Schema::create('v_destinations', function ($t) {
            $t->string('destination_uuid')->primary();
            $t->string('destination_type')->nullable();
            $t->string('destination_number')->nullable();
        });
        Schema::create('v_default_settings', function ($t) {
            $t->string('default_setting_uuid')->primary();
            $t->string('default_setting_category')->nullable();
            $t->string('default_setting_subcategory')->nullable();
            $t->string('default_setting_value')->nullable();
        });
        // voicemail fallback dialplan (voxragtm#110)
        Schema::create('v_dialplans', function ($t) {
            $t->string('dialplan_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('app_uuid')->nullable();
            $t->string('dialplan_name')->nullable();
            $t->string('dialplan_number')->nullable();
            $t->string('dialplan_destination')->nullable();
            $t->string('dialplan_context')->nullable();
            $t->string('dialplan_continue')->nullable();
            $t->text('dialplan_xml')->nullable();
            $t->string('dialplan_order')->nullable();
            $t->string('dialplan_enabled')->nullable();
            $t->string('dialplan_description')->nullable();
            $t->string('insert_date')->nullable();
            $t->string('insert_user')->nullable();
            $t->string('update_date')->nullable();
            $t->string('update_user')->nullable();
        });
    }

    private function domain(): Domain
    {
        $domain = new Domain();
        $domain->setRawAttributes([
            'domain_uuid' => 'dom-uuid-1',
            'domain_name' => 'acme.voxra.uk',
        ]);

        return $domain;
    }

    public function test_creates_extension_follow_me_and_voicemail_rows(): void
    {
        $result = app(ProvisionLineService::class)->ensureLineExtension($this->domain(), '07700 900123');

        $this->assertSame('9260', $result['extension']);
        $this->assertFalse($result['straight_to_voicemail']);

        $ext = Extensions::where('domain_uuid', 'dom-uuid-1')->where('extension', '9260')->first();
        $this->assertNotNull($ext);
        $this->assertSame('true', $ext->follow_me_enabled);
        $this->assertSame('acme.voxra.uk', $ext->user_context);

        $followMe = FollowMe::find($ext->follow_me_uuid);
        $this->assertSame('true', $followMe->follow_me_enabled);

        $dests = FollowMeDestinations::where('follow_me_uuid', $ext->follow_me_uuid)->get();
        $this->assertCount(1, $dests);
        $this->assertSame('+447700900123', $dests->first()->follow_me_destination);
        $this->assertSame('1', $dests->first()->follow_me_prompt);

        $vm = Voicemails::where('domain_uuid', 'dom-uuid-1')->where('voicemail_id', '9260')->first();
        $this->assertSame('true', $vm->voicemail_enabled);
        $this->assertSame('true', $vm->voicemail_transcription_enabled);
    }

    public function test_reprovision_is_idempotent(): void
    {
        $svc = app(ProvisionLineService::class);
        $svc->ensureLineExtension($this->domain(), '07700 900123');
        $svc->ensureLineExtension($this->domain(), '07700 900123');

        $this->assertSame(1, Extensions::where('extension', '9260')->count());
        $this->assertSame(1, FollowMe::count());
        $this->assertSame(1, FollowMeDestinations::count());
        $this->assertSame(1, Voicemails::where('voicemail_id', '9260')->count());
        $this->assertSame(1, \App\Models\Dialplans::count());
    }

    public function test_provisions_voicemail_fallback_dialplan(): void
    {
        app(ProvisionLineService::class)->ensureLineExtension($this->domain(), '07700 900123');

        $dp = \App\Models\Dialplans::where('domain_uuid', 'dom-uuid-1')
            ->where('dialplan_description', ProvisionLineService::FALLBACK_DIALPLAN_DESCRIPTION)
            ->first();

        $this->assertNotNull($dp);
        $this->assertSame('acme.voxra.uk', $dp->dialplan_context);
        $this->assertSame('9260', $dp->dialplan_number);
        $this->assertTrue($dp->dialplan_enabled); // model casts 'true' → bool
        $this->assertSame('false', $dp->dialplan_continue);

        // must pre-empt the stock global follow-me-destinations entry (order
        // 520), whose lua-only body strands the caller on unlisted originate
        // dispositions (the live NORMAL_CLEARING failure, voxragtm#110)
        $this->assertLessThan(520, (int) $dp->dialplan_order);

        // same follow-me run as stock…
        $this->assertStringContainsString('data="app.lua follow_me"', $dp->dialplan_xml);
        $this->assertStringContainsString('field="${follow_me_enabled}" expression="^true$"', $dp->dialplan_xml);
        // …plus the voicemail tail into the line's transcribed box, via the
        // FusionPBX voicemail lua (mirrors stock send_to_voicemail): only the
        // lua plays v_voicemail_greetings and writes v_voicemail_messages —
        // mod_voicemail ("voicemail" application) would do neither
        $this->assertStringContainsString('data="voicemail_id=9260"', $dp->dialplan_xml);
        $this->assertStringContainsString('data="voicemail_action=save"', $dp->dialplan_xml);
        $this->assertStringContainsString('data="send_to_voicemail=true"', $dp->dialplan_xml);
        $this->assertStringContainsString('<action application="lua" data="app.lua voicemail"/>', $dp->dialplan_xml);
        $this->assertStringNotContainsString('<action application="voicemail"', $dp->dialplan_xml);
        $this->assertStringContainsString('data="hangup_after_bridge=false"', $dp->dialplan_xml);
    }

    public function test_fallback_dialplan_provisioned_even_without_mobile(): void
    {
        // straight-to-voicemail line: entry doesn't match (follow_me_enabled
        // false) but must already exist for a later mobile re-provision
        app(ProvisionLineService::class)->ensureLineExtension($this->domain(), null);

        $this->assertSame(1, \App\Models\Dialplans::where(
            'dialplan_description',
            ProvisionLineService::FALLBACK_DIALPLAN_DESCRIPTION
        )->count());
    }

    public function test_missing_mobile_creates_straight_to_voicemail_line(): void
    {
        $result = app(ProvisionLineService::class)->ensureLineExtension($this->domain(), null);

        $this->assertTrue($result['straight_to_voicemail']);

        $ext = Extensions::where('extension', '9260')->first();
        $this->assertSame('false', $ext->follow_me_enabled);
        $this->assertSame(0, FollowMeDestinations::count());
        $this->assertSame(1, Voicemails::where('voicemail_id', '9260')->count());

        // later re-provision with a mobile upgrades the same rows in place
        $result = app(ProvisionLineService::class)->ensureLineExtension($this->domain(), '07700 900123');
        $this->assertFalse($result['straight_to_voicemail']);
        $this->assertSame('true', Extensions::where('extension', '9260')->value('follow_me_enabled'));
        $this->assertSame(1, FollowMeDestinations::count());
        $this->assertSame(1, Extensions::where('extension', '9260')->count());
    }

    public function test_mobile_hosted_on_this_pbx_is_refused(): void
    {
        // ringing a DID hosted here would PSTN-loop — same guard as ring-first
        \Illuminate\Support\Facades\DB::table('v_destinations')->insert([
            'destination_uuid' => 'dest-1',
            'destination_type' => 'inbound',
            'destination_number' => '+447700900123',
        ]);

        $result = app(ProvisionLineService::class)->ensureLineExtension($this->domain(), '07700 900123');

        $this->assertTrue($result['straight_to_voicemail']);
        $this->assertSame(0, FollowMeDestinations::count());
    }

    public function test_generates_branded_tts_greeting(): void
    {
        $this->fakeTts();
        app(ProvisionLineService::class)->ensureLineExtension($this->domain(), '07700 900123', 'Acme Plumbing');

        // one marked greeting row, selected on the box
        $greeting = VoicemailGreetings::where('domain_uuid', 'dom-uuid-1')
            ->where('voicemail_id', '9260')->first();
        $this->assertNotNull($greeting);
        $this->assertSame(ProvisionLineService::GREETING_NAME, $greeting->greeting_name);
        $this->assertSame('greeting_1.wav', $greeting->greeting_filename);
        $this->assertStringStartsWith(ProvisionLineService::GREETING_HASH_PREFIX, $greeting->greeting_description);
        $this->assertStringContainsString('Thanks for calling Acme Plumbing.', $greeting->greeting_description);

        $vm = Voicemails::where('voicemail_id', '9260')->first();
        $this->assertSame(1, (int) $vm->greeting_id);

        // the file is a valid 16-bit mono 16kHz RIFF/WAVE wrapping the PCM
        Storage::disk('voicemail')->assertExists('acme.voxra.uk/9260/greeting_1.wav');
        $wav = Storage::disk('voicemail')->get('acme.voxra.uk/9260/greeting_1.wav');
        $this->assertSame('RIFF', substr($wav, 0, 4));
        $this->assertSame('WAVEfmt ', substr($wav, 8, 8));
        $fmt = unpack('vformat/vchannels/Vrate', substr($wav, 20, 8));
        $this->assertSame(1, $fmt['format']);   // PCM
        $this->assertSame(1, $fmt['channels']); // mono
        $this->assertSame(16000, $fmt['rate']);
        $this->assertSame($this->fakePcm(), substr($wav, 44));

        // TTS asked ElevenLabs for raw PCM with the configured voice + text
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v1/text-to-speech/Xb7hH8MSUJpSbSDYk0k2')
                && str_contains($request->url(), 'output_format=pcm_16000')
                && str_contains($request->body(), 'Thanks for calling Acme Plumbing.');
        });
    }

    public function test_greeting_generation_is_idempotent(): void
    {
        $this->fakeTts();
        $svc = app(ProvisionLineService::class);
        $svc->ensureLineExtension($this->domain(), '07700 900123', 'Acme Plumbing');
        $svc->ensureLineExtension($this->domain(), '07700 900123', 'Acme Plumbing');

        // unchanged text+voice hash → the second run makes no TTS call
        Http::assertSentCount(1);
        $this->assertSame(1, VoicemailGreetings::count());
    }

    public function test_greeting_regenerates_when_text_changes(): void
    {
        $this->fakeTts();
        $svc = app(ProvisionLineService::class);
        $svc->ensureLineExtension($this->domain(), '07700 900123', 'Acme Plumbing');

        config()->set('services.voxra.vm_greeting_text', '{business} here — leave a message.');
        $svc->ensureLineExtension($this->domain(), '07700 900123', 'Acme Plumbing');

        Http::assertSentCount(2);

        // same row + slot, refreshed content
        $this->assertSame(1, VoicemailGreetings::count());
        $greeting = VoicemailGreetings::first();
        $this->assertSame(1, (int) $greeting->greeting_id);
        $this->assertStringContainsString('Acme Plumbing here — leave a message.', $greeting->greeting_description);
        $this->assertSame(1, (int) Voicemails::where('voicemail_id', '9260')->value('greeting_id'));
    }

    public function test_owner_recorded_greeting_is_never_overwritten(): void
    {
        $this->fakeTts();
        // owner recorded a greeting (via *98 / UI upload — no Voxra marker)
        // before line provisioning ran with a business name
        $svc = app(ProvisionLineService::class);
        $svc->ensureLineExtension($this->domain(), '07700 900123'); // creates the box, no greeting

        VoicemailGreetings::create([
            'domain_uuid' => 'dom-uuid-1',
            'voicemail_id' => '9260',
            'greeting_id' => 1,
            'greeting_name' => 'Greeting 1',
            'greeting_filename' => 'greeting_1.wav',
        ]);
        Voicemails::where('voicemail_id', '9260')->update(['greeting_id' => 1]);

        $svc->ensureLineExtension($this->domain(), '07700 900123', 'Acme Plumbing');

        // no TTS call, no marker row, owner's selection untouched
        Http::assertNothingSent();
        $this->assertSame(0, VoicemailGreetings::where('greeting_name', ProvisionLineService::GREETING_NAME)->count());
        $this->assertSame(1, (int) Voicemails::where('voicemail_id', '9260')->value('greeting_id'));
    }

    public function test_owner_selection_beats_existing_voxra_greeting(): void
    {
        $this->fakeTts();
        // Voxra generated greeting_1, then the owner recorded greeting_2 and
        // selected it — a later re-provision must not steal the selection back
        $svc = app(ProvisionLineService::class);
        $svc->ensureLineExtension($this->domain(), '07700 900123', 'Acme Plumbing');

        VoicemailGreetings::create([
            'domain_uuid' => 'dom-uuid-1',
            'voicemail_id' => '9260',
            'greeting_id' => 2,
            'greeting_name' => 'Greeting 2',
            'greeting_filename' => 'greeting_2.wav',
        ]);
        Voicemails::where('voicemail_id', '9260')->update(['greeting_id' => 2]);

        $svc->ensureLineExtension($this->domain(), '07700 900123', 'Acme Plumbing');

        Http::assertSentCount(1); // only the original generation
        $this->assertSame(2, (int) Voicemails::where('voicemail_id', '9260')->value('greeting_id'));
    }

    public function test_missing_api_key_skips_greeting_but_provisioning_succeeds(): void
    {
        config()->set('services.elevenlabs.api_key', '');

        $result = app(ProvisionLineService::class)
            ->ensureLineExtension($this->domain(), '07700 900123', 'Acme Plumbing');

        $this->assertSame('9260', $result['extension']); // provisioning completed
        Http::assertNothingSent();
        $this->assertSame(0, VoicemailGreetings::count());
        // model default -1 = no greeting selected → stock phrase plays
        $this->assertLessThanOrEqual(0, (int) Voicemails::where('voicemail_id', '9260')->value('greeting_id'));
    }

    public function test_tts_failure_leaves_stock_greeting(): void
    {
        Http::fake(['api.elevenlabs.io/*' => Http::response('nope', 500)]);

        $result = app(ProvisionLineService::class)
            ->ensureLineExtension($this->domain(), '07700 900123', 'Acme Plumbing');

        $this->assertSame('9260', $result['extension']); // provisioning completed
        $this->assertSame(0, VoicemailGreetings::count());
        $this->assertLessThanOrEqual(0, (int) Voicemails::where('voicemail_id', '9260')->value('greeting_id'));
        Storage::disk('voicemail')->assertMissing('acme.voxra.uk/9260/greeting_1.wav');
    }
}
