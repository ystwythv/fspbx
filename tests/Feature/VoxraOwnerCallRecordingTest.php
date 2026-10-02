<?php

namespace Tests\Feature;

use App\Http\Controllers\Internal\ProvisionTenantController;
use App\Models\AiAgent;
use App\Models\Dialplans;
use App\Models\Domain;
use App\Models\Extensions;
use App\Services\ProvisionCompleteService;
use App\Services\ProvisionNumberService;
use App\Services\RecordingWebhookConfigService;
use App\Services\Voxra\VoxraOwnerCallRecording as R;
use App\Services\Voxra\VoxraRoutingState as S;
use App\Services\Voxra\VoxraTtsPrompt;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Owner-answered call recording (voxragtm#157): the per-tenant flag, the
 * caller announcement before the owner is bridged, recording only the leg
 * the owner answers (never the AI / voicemail that picks up after a failed
 * ring), and the recording webhook to voxraweb. In-memory sqlite for the
 * DB-backed parts, ProvisionTenantCompleteModeTest style.
 */
class VoxraOwnerCallRecordingTest extends TestCase
{
    private const MOBILE = '+447700900123';
    private const PROMPT = '${cond(${file_exists(/rec/voxra/call-may-be-recorded-abc.wav)} == true ? /rec/voxra/call-may-be-recorded-abc.wav : ivr/ivr-recording_started.wav)}';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('activitylog.enabled', false);
        Bus::fake();
        Event::fake([\App\Events\ExtensionUpdated::class]);

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
        Schema::create('v_domain_settings', function ($t) {
            $t->string('domain_setting_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('app_uuid')->nullable();
            $t->string('domain_setting_category')->nullable();
            $t->string('domain_setting_subcategory')->nullable();
            $t->string('domain_setting_name')->nullable();
            $t->text('domain_setting_value')->nullable();
            $t->string('domain_setting_order')->nullable();
            $t->string('domain_setting_enabled')->nullable();
            $t->string('domain_setting_description')->nullable();
        });
        Schema::create('v_default_settings', function ($t) {
            $t->string('default_setting_uuid')->primary();
            $t->string('default_setting_category')->nullable();
            $t->string('default_setting_subcategory')->nullable();
            $t->string('default_setting_value')->nullable();
            $t->string('default_setting_enabled')->nullable();
        });
        Schema::create('v_extensions', function ($t) {
            $t->string('extension_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('extension')->nullable();
            $t->string('password')->nullable();
            $t->string('user_context')->nullable();
            $t->string('effective_caller_id_name')->nullable();
            $t->string('effective_caller_id_number')->nullable();
            $t->string('outbound_caller_id_name')->nullable();
            $t->string('outbound_caller_id_number')->nullable();
            $t->string('emergency_caller_id_name')->nullable();
            $t->string('emergency_caller_id_number')->nullable();
            $t->string('directory_first_name')->nullable();
            $t->string('directory_last_name')->nullable();
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
            $t->string('forward_no_answer_enabled')->nullable();
            $t->string('forward_no_answer_destination')->nullable();
            $t->string('forward_busy_enabled')->nullable();
            $t->string('forward_busy_destination')->nullable();
            $t->string('forward_user_not_registered_enabled')->nullable();
            $t->string('forward_user_not_registered_destination')->nullable();
            $t->string('insert_date')->nullable();
        });
        Schema::create('v_extension_settings', function ($t) {
            $t->string('extension_setting_uuid')->primary();
            $t->string('extension_uuid')->nullable();
            $t->string('domain_uuid')->nullable();
            $t->string('extension_setting_type')->nullable();
            $t->string('extension_setting_name')->nullable();
            $t->string('extension_setting_value')->nullable();
            $t->boolean('extension_setting_enabled')->default(true);
            $t->string('extension_setting_description')->nullable();
            $t->timestamp('insert_date')->nullable();
            $t->string('insert_user')->nullable();
        });
        Schema::create('extension_advanced_settings', function ($t) {
            $t->string('uuid')->primary();
            $t->string('extension_uuid')->nullable();
            $t->string('suspended')->nullable();
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
            $t->string('insert_date')->nullable();
        });
        Schema::create('v_ai_agents', function ($t) {
            $t->string('ai_agent_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('agent_extension')->nullable();
            $t->string('agent_enabled')->nullable();
            $t->string('mode')->nullable();
        });
    }

    private function domain(): Domain
    {
        $domain = new Domain();
        $domain->setRawAttributes(['domain_uuid' => 'dom-uuid-1', 'domain_name' => 'acme.voxra.uk']);

        return $domain;
    }

    private function agent(): AiAgent
    {
        $agent = new AiAgent();
        $agent->setRawAttributes(['agent_extension' => '9250']);

        return $agent;
    }

    private function route(string $mode, bool $agentEnabled, ?string $mobile, ?string $prompt): array
    {
        return (new ProvisionNumberService())->resolveDidRouting(
            $this->domain(), $this->agent(), $mode, $agentEnabled, $mobile, 20, false, null, $prompt,
        );
    }

    private function data(array $actions): array
    {
        return array_map(fn ($a) => trim($a['destination_app'] . ' ' . $a['destination_data']), $actions);
    }

    private function indexOf(array $data, string $prefix): int
    {
        foreach ($data as $i => $line) {
            if (str_starts_with($line, $prefix)) {
                return $i;
            }
        }
        $this->fail("no action starting with '$prefix' in:\n" . implode("\n", $data));
    }

    // ---- the flag ------------------------------------------------------

    public function test_flag_explicit_value_wins_then_stored_then_off(): void
    {
        $this->assertTrue(ProvisionTenantController::resolveOwnerCallRecording(true, [], S::MODE_PRO));
        $this->assertFalse(ProvisionTenantController::resolveOwnerCallRecording(false, ['owner_call_recording' => true], S::MODE_PRO));
        $this->assertTrue(ProvisionTenantController::resolveOwnerCallRecording(null, ['owner_call_recording' => true], S::MODE_COMPLETE));
        $this->assertFalse(ProvisionTenantController::resolveOwnerCallRecording(null, [], S::MODE_PRO), 'off by default');
        $this->assertTrue(ProvisionTenantController::resolveOwnerCallRecording(true, [], S::MODE_LINE_AI));
    }

    public function test_never_on_line_v1(): void
    {
        $this->assertFalse(ProvisionTenantController::resolveOwnerCallRecording(true, [], S::MODE_LINE));
        $this->assertFalse(ProvisionTenantController::resolveOwnerCallRecording(null, ['owner_call_recording' => true], S::MODE_LINE));
    }

    public function test_flag_round_trips_through_the_routing_state(): void
    {
        S::save('dom-uuid-1', ['mode' => S::MODE_PRO, 'owner_call_recording' => true]);
        $this->assertTrue(S::load('dom-uuid-1')['owner_call_recording']);
        $this->assertTrue(ProvisionTenantController::resolveOwnerCallRecording(null, S::load('dom-uuid-1'), S::MODE_PRO));
    }

    // ---- ring-first bridge -------------------------------------------------

    public function test_off_the_ring_first_actions_are_exactly_todays(): void
    {
        $svc = new ProvisionNumberService();
        $today = $svc->ringFirstActions($this->domain(), $this->agent(), self::MOBILE, 20);
        $off = $this->route(S::MODE_PRO, true, self::MOBILE, null);

        $this->assertSame($today, $off['actions']);
        $json = json_encode($off['actions'], JSON_UNESCAPED_SLASHES);
        foreach (['record_session', 'pre_answer', 'playback', 'execute_on_answer', R::MARKER] as $absent) {
            $this->assertStringNotContainsString($absent, $json);
        }
    }

    public function test_on_the_caller_hears_the_announcement_before_the_owner_is_bridged(): void
    {
        $r = $this->route(S::MODE_PRO, true, self::MOBILE, self::PROMPT);
        $data = $this->data($r['actions']);

        $this->assertSame('ring_first_ai', $r['kind']);
        $this->assertSame('set ' . R::MARKER, $data[0]);
        $preAnswer = $this->indexOf($data, 'pre_answer');
        $playback = $this->indexOf($data, 'playback ');
        $bridge = $this->indexOf($data, 'bridge ');
        $this->assertSame('playback ' . self::PROMPT, $data[$playback]);
        $this->assertLessThan($playback, $preAnswer);
        $this->assertLessThan($bridge, $playback);
        // early media only — never answered before the owner picks up (the
        // recorder is armed on answer)
        $this->assertNotContains('answer', $data);
        $this->assertContains('set ringback=' . R::UK_RING, $data);
    }

    public function test_on_only_the_owner_answered_leg_is_recorded(): void
    {
        $data = $this->data($this->route(S::MODE_PRO, true, self::MOBILE, self::PROMPT)['actions']);

        $arm = $this->indexOf($data, 'set execute_on_answer=record_session ${record_path}/${record_name}');
        $bridge = $this->indexOf($data, 'bridge ');
        $disarm = $this->indexOf($data, 'unset execute_on_answer');
        $ai = $this->indexOf($data, 'transfer 9250 XML acme.voxra.uk');

        $this->assertLessThan($bridge, $arm, 'armed before the owner can answer');
        $this->assertLessThan($disarm, $bridge);
        $this->assertLessThan($ai, $disarm, 'disarmed before the AI answers');
        $this->assertContains('unset record_name', $data);
        $this->assertContains('unset record_path', $data);
        $this->assertContains(
            'set record_path=${recordings_dir}/${domain_name}/archive/${strftime(%Y)}/${strftime(%b)}/${strftime(%d)}',
            $data,
            'stored under the tenant archive dir, so voxra:purge-media removes it after 10 days'
        );
        $this->assertContains('set RECORD_ANSWER_REQ=true', $data);
    }

    public function test_on_with_the_ai_off_disarms_before_voicemail(): void
    {
        $r = $this->route(S::MODE_PRO, false, self::MOBILE, self::PROMPT);
        $data = $this->data($r['actions']);

        $this->assertSame('ring_first_voicemail', $r['kind']);
        $this->assertLessThan(
            $this->indexOf($data, 'transfer *999260 XML acme.voxra.uk'),
            $this->indexOf($data, 'unset execute_on_answer')
        );
    }

    public function test_line_ai_ring_first_is_recorded_too(): void
    {
        $data = $this->data($this->route(S::MODE_LINE_AI, true, self::MOBILE, self::PROMPT)['actions']);
        $this->assertContains('playback ' . self::PROMPT, $data);
    }

    public function test_ai_only_routing_is_never_touched(): void
    {
        $r = $this->route(S::MODE_PRO, true, null, self::PROMPT);
        $this->assertSame(['transfer 9250 XML acme.voxra.uk'], $this->data($r['actions']));
    }

    public function test_recording_ring_first_did_is_still_recognised_as_voxras(): void
    {
        $actions = $this->route(S::MODE_PRO, true, self::MOBILE, self::PROMPT)['actions'];
        $json = json_encode($actions);

        $this->assertTrue(ProvisionNumberService::isVoxraDid('Inbound +441234', $json, 'acme.voxra.uk'));
        $this->assertTrue(ProvisionNumberService::isVoxraDid('something else', $json, 'acme.voxra.uk'));
        $this->assertSame(self::MOBILE, ProvisionNumberService::ringFirstMobileIn($json));
        $this->assertFalse(ProvisionNumberService::isSuspendedRouting($json));
    }

    // ---- the announcement prompt -----------------------------------------------

    public function test_playback_falls_back_to_a_stock_prompt_when_the_file_is_missing_on_the_node(): void
    {
        $this->assertSame(
            '${cond(${file_exists(/rec/voxra/x.wav)} == true ? /rec/voxra/x.wav : ivr/ivr-recording_started.wav)}',
            R::playbackExpressionFor('/rec/voxra/x.wav')
        );
        $this->assertSame(R::FALLBACK_PROMPT, R::playbackExpressionFor(null));
    }

    public function test_prompt_path_is_stable_per_text_and_voice_and_distinct_from_suspended(): void
    {
        $a = VoxraTtsPrompt::relativePath(R::PREFIX, R::DEFAULT_TEXT, 'v1');
        $this->assertSame($a, VoxraTtsPrompt::relativePath(R::PREFIX, R::DEFAULT_TEXT, 'v1'));
        $this->assertNotSame($a, VoxraTtsPrompt::relativePath(R::PREFIX, 'Other.', 'v1'));
        $this->assertStringStartsWith('voxra/call-may-be-recorded-', $a);
        $this->assertNotSame($a, \App\Services\Voxra\VoxraSuspendedAnnouncement::relativePath(R::DEFAULT_TEXT, 'v1'));
    }

    public function test_announcement_text_defaults_and_is_configurable(): void
    {
        config()->set('services.voxra.owner_recording_announcement_text', '');
        $this->assertSame('This call may be recorded.', R::text());
        config()->set('services.voxra.owner_recording_announcement_text', 'Calls are recorded.');
        $this->assertSame('Calls are recorded.', R::text());
    }

    // ---- Complete mobile extension --------------------------------------------

    public function test_mobile_announcement_dialplan_only_plays_when_the_extension_records_inbound(): void
    {
        $xml = R::announcementDialplanXml('dp-1', self::PROMPT);

        $this->assertStringContainsString('continue="true"', $xml);
        $this->assertStringContainsString('<condition field="${call_direction}" expression="^inbound$"/>', $xml);
        $this->assertStringContainsString('<condition field="${user_record}" expression="^(inbound|all)$"/>', $xml);
        $this->assertStringContainsString('expression="^(2\d\d)$"', $xml);
        $this->assertStringContainsString('<action application="pre_answer"/>', $xml);
        $this->assertStringContainsString('<action application="playback" data="' . self::PROMPT . '"/>', $xml);
        $this->assertStringNotContainsString('application="answer"', $xml);
        $this->assertLessThan(strpos($xml, 'playback'), strpos($xml, 'pre_answer'));
        // the mobile block it matches is the provisioning block
        $this->assertSame(200, ProvisionCompleteService::EXTENSION_MIN);
        $this->assertSame(299, ProvisionCompleteService::EXTENSION_MAX);
        // before user_record (50) and push_wake_hook (99, rings the eSIM)
        $this->assertLessThan(50, R::ANNOUNCE_ORDER);
        $this->assertGreaterThan(35, R::ANNOUNCE_ORDER);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_mobile_announcement_dialplan_is_created_idempotently_and_removed_when_off(): void
    {
        $svc = new R();
        $svc->applyMobileAnnouncement($this->domain(), self::PROMPT);
        $svc->applyMobileAnnouncement($this->domain(), self::PROMPT);

        $rows = Dialplans::where('domain_uuid', 'dom-uuid-1')->where('dialplan_description', R::ANNOUNCE_DESCRIPTION)->get();
        $this->assertCount(1, $rows);
        $this->assertSame('acme.voxra.uk', $rows[0]->dialplan_context);
        $this->assertTrue($rows[0]->dialplan_enabled);
        $this->assertSame('true', $rows[0]->dialplan_continue);
        $this->assertSame((string) R::ANNOUNCE_ORDER, (string) $rows[0]->dialplan_order);

        $svc->applyMobileAnnouncement($this->domain(), null);
        $this->assertSame(0, Dialplans::where('dialplan_description', R::ANNOUNCE_DESCRIPTION)->count());
    }

    public function test_mobile_extension_records_inbound_only_when_opted_in(): void
    {
        $svc = app(ProvisionCompleteService::class);

        $svc->ensureMobileExtension($this->domain(), 'Acme', false, true);
        $this->assertSame('inbound', Extensions::where('domain_uuid', 'dom-uuid-1')->first()->user_record);

        $svc->ensureMobileExtension($this->domain(), 'Acme');
        $this->assertNull(Extensions::where('domain_uuid', 'dom-uuid-1')->first()->user_record, 'off re-asserts no recording');
    }

    // ---- recording webhook → voxraweb --------------------------------------

    public function test_webhook_settings_point_at_voxraweb_inbound_only(): void
    {
        $this->assertSame([
            'enabled'    => 'true',
            'url'        => 'https://voxra.uk/api/pbx/owner-recording',
            'secret'     => 'cdr-secret',
            'directions' => 'inbound',
            'events'     => 'recording.available',
            'url_ttl'    => '3600',
        ], R::webhookSettings(true, 'https://voxra.uk/', 'cdr-secret'));

        $this->assertSame('false', R::webhookSettings(false, 'https://voxra.uk', 's')['enabled']);
        $this->assertNull(R::webhookSettings(true, '', 's'));
        $this->assertNull(R::webhookSettings(true, 'https://voxra.uk', ''));
    }

    public function test_webhook_is_enabled_for_the_domain_and_switched_off_again(): void
    {
        config()->set('services.voxra.app_url', 'https://voxra.uk');
        config()->set('services.voxra.cdr_webhook_secret', 'cdr-secret');
        $config = app(RecordingWebhookConfigService::class);

        $this->assertTrue((new R())->applyWebhook($this->domain(), true));
        $this->assertSame([
            'url' => 'https://voxra.uk/api/pbx/owner-recording',
            'secret' => 'cdr-secret',
            'url_ttl' => 3600,
            'directions' => ['inbound'],
            'events' => ['recording.available'],
        ], $config->getSettingsForDomain('dom-uuid-1'));
        $this->assertArrayHasKey('dom-uuid-1', $config->getEnabledDomainConfigs());

        (new R())->applyWebhook($this->domain(), false);
        $this->assertNull($config->getSettingsForDomain('dom-uuid-1'));
        $this->assertSame([], $config->getEnabledDomainConfigs());
        // one row per setting, updated in place
        $this->assertSame(6, \App\Models\DomainSettings::where('domain_uuid', 'dom-uuid-1')->count());
    }

    public function test_webhook_is_not_written_without_voxraweb_config(): void
    {
        config()->set('services.voxra.app_url', '');
        $this->assertFalse((new R())->applyWebhook($this->domain(), true));
        $this->assertSame(0, \App\Models\DomainSettings::count());
    }
}
