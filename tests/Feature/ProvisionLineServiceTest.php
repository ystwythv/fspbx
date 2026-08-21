<?php

namespace Tests\Feature;

use App\Models\Domain;
use App\Models\Extensions;
use App\Models\FollowMe;
use App\Models\FollowMeDestinations;
use App\Models\Voicemails;
use App\Services\ProvisionLineService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * ensureLineExtension (voxragtm#25) against an in-memory sqlite schema:
 * provisioning creates extension + follow-me + voicemail rows, re-running is
 * idempotent (no duplicates), and a missing owner_mobile yields a
 * straight-to-voicemail line that a later re-provision can upgrade.
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
        // …plus the voicemail tail into the line's transcribed box
        $this->assertStringContainsString(
            '<action application="voicemail" data="default ${domain_name} 9260"/>',
            $dp->dialplan_xml
        );
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
}
