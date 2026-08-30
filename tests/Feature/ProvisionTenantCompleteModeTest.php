<?php

namespace Tests\Feature;

use App\Http\Controllers\Internal\ProvisionTenantController;
use App\Jobs\BuildDialplanForPhoneNumber;
use App\Models\AiAgent;
use App\Models\Destinations;
use App\Models\Domain;
use App\Models\Extensions;
use App\Models\Voicemails;
use App\Services\AgentFailoverService;
use App\Services\ProvisionCompleteService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Voxra Complete (voxragtm#45): complete_mode provisions a registerable
 * mobile extension in the 200–299 block whose credentials the FMC platform
 * registers the tenant's eSIM with. Against an in-memory sqlite schema, in
 * the ProvisionLineServiceTest style.
 */
class ProvisionTenantCompleteModeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('activitylog.enabled', false);

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
            $t->string('voicemail_mail_to')->nullable();
            $t->string('greeting_id')->nullable();
            $t->string('insert_date')->nullable();
        });
        Schema::create('v_ai_agents', function ($t) {
            $t->string('ai_agent_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('agent_extension')->nullable();
            $t->string('agent_enabled')->nullable();
            $t->string('mode')->nullable();
        });
        Schema::create('v_destinations', function ($t) {
            $t->string('destination_uuid')->primary();
            $t->string('domain_uuid')->nullable();
            $t->string('dialplan_uuid')->nullable();
            $t->string('destination_type')->nullable();
            $t->string('destination_number')->nullable();
            $t->string('destination_prefix')->nullable();
            $t->string('destination_trunk_prefix')->nullable();
            $t->string('destination_area_code')->nullable();
            $t->string('destination_number_regex')->nullable();
            $t->text('destination_actions')->nullable();
            $t->string('destination_enabled')->nullable();
            $t->string('destination_context')->nullable();
            $t->string('destination_description')->nullable();
            $t->string('insert_date')->nullable();
            $t->string('update_date')->nullable();
            $t->string('update_user')->nullable();
        });
        Schema::create('v_default_settings', function ($t) {
            $t->string('default_setting_uuid')->primary();
            $t->string('default_setting_category')->nullable();
            $t->string('default_setting_subcategory')->nullable();
            $t->string('default_setting_value')->nullable();
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

    private function seedAgent(string $enabled = 'true', string $mode = AiAgent::MODE_RECEPTION): void
    {
        AiAgent::unguarded(fn () => AiAgent::query()->insert([
            'ai_agent_uuid'   => 'agent-uuid-1',
            'domain_uuid'     => 'dom-uuid-1',
            'agent_extension' => '9250',
            'agent_enabled'   => $enabled,
            'mode'            => $mode,
        ]));
    }

    private function mobile(): ?Extensions
    {
        return Extensions::where('domain_uuid', 'dom-uuid-1')
            ->where('description', ProvisionCompleteService::EXTENSION_DESCRIPTION)
            ->first();
    }

    // ---- controller mode resolution ------------------------------------

    public function test_complete_mode_wins_over_line_mode(): void
    {
        $this->assertFalse(ProvisionTenantController::resolveLineMode(true, true));
        $this->assertTrue(ProvisionTenantController::resolveLineMode(true, false));
        $this->assertFalse(ProvisionTenantController::resolveLineMode(false, true));
        // the agent stays enabled in complete mode (line mode is what forces it off)
        $this->assertTrue(ProvisionTenantController::resolveAgentEnabled(true, ProvisionTenantController::resolveLineMode(true, true)));
    }

    // ---- ensureMobileExtension ------------------------------------------

    public function test_creates_registerable_extension_in_200_block(): void
    {
        $this->seedAgent();

        $result = app(ProvisionCompleteService::class)->ensureMobileExtension($this->domain(), 'Acme Plumbing');

        $this->assertSame('200', $result['extension']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{24}$/', $result['password']);
        $this->assertSame('acme.voxra.uk', $result['sip_host']);
        $this->assertSame('reg.voxra.uk', $result['sip_proxy']);
        $this->assertTrue($result['created']);

        $ext = $this->mobile();
        $this->assertNotNull($ext);
        $this->assertSame('200', $ext->extension);
        $this->assertSame($result['password'], $ext->password);
        $this->assertSame('fmc', $ext->ring_target);
        $this->assertSame('acme.voxra.uk', $ext->user_context);
        $this->assertSame('Acme Plumbing', $ext->effective_caller_id_name);
        $this->assertSame('Acme Plumbing', $ext->directory_first_name);
        $this->assertSame('Mobile', $ext->directory_last_name);
        $this->assertSame((string) ProvisionCompleteService::CALL_TIMEOUT, (string) $ext->call_timeout);
        $this->assertSame('false', $ext->directory_visible);
        $this->assertSame('true', $ext->enabled);

        // agent failover on all three forwards
        $this->assertSame('true', $ext->forward_no_answer_enabled);
        $this->assertSame('9250', $ext->forward_no_answer_destination);
        $this->assertSame('true', $ext->forward_busy_enabled);
        $this->assertSame('9250', $ext->forward_busy_destination);
        $this->assertSame('true', $ext->forward_user_not_registered_enabled);
        $this->assertSame('9250', $ext->forward_user_not_registered_destination);

        $vm = Voicemails::where('domain_uuid', 'dom-uuid-1')->where('voicemail_id', '200')->first();
        $this->assertNotNull($vm);
        $this->assertSame('true', $vm->voicemail_enabled);
        $this->assertSame('true', $vm->voicemail_transcription_enabled);
    }

    public function test_registrar_comes_from_config(): void
    {
        config()->set('services.voxra.fmc_registrar', 'reg-staging.voxra.uk');
        $result = app(ProvisionCompleteService::class)->ensureMobileExtension($this->domain(), 'Acme');
        $this->assertSame('reg-staging.voxra.uk', $result['sip_proxy']);
    }

    public function test_reprovision_keeps_extension_and_password(): void
    {
        $svc = app(ProvisionCompleteService::class);
        $first = $svc->ensureMobileExtension($this->domain(), 'Acme');
        $second = $svc->ensureMobileExtension($this->domain(), 'Acme Renamed');

        $this->assertSame($first['extension'], $second['extension']);
        $this->assertSame($first['password'], $second['password']);
        $this->assertFalse($second['created']);
        $this->assertSame(1, Extensions::where('domain_uuid', 'dom-uuid-1')->count());
        $this->assertSame(1, Voicemails::where('voicemail_id', '200')->count());
        // business rename converges
        $this->assertSame('Acme Renamed', $this->mobile()->effective_caller_id_name);
    }

    public function test_rotate_flag_changes_password_only(): void
    {
        $svc = app(ProvisionCompleteService::class);
        $first = $svc->ensureMobileExtension($this->domain(), 'Acme');
        $rotated = $svc->ensureMobileExtension($this->domain(), 'Acme', true);

        $this->assertSame($first['extension'], $rotated['extension']);
        $this->assertNotSame($first['password'], $rotated['password']);
        $this->assertSame($rotated['password'], $this->mobile()->password);
    }

    public function test_allocation_skips_taken_numbers_and_never_uses_agent_block(): void
    {
        Extensions::unguarded(fn () => Extensions::query()->insert([
            ['extension_uuid' => 'x-200', 'domain_uuid' => 'dom-uuid-1', 'extension' => '200', 'description' => 'someone else'],
            ['extension_uuid' => 'x-201', 'domain_uuid' => 'dom-uuid-1', 'extension' => '201', 'description' => 'someone else'],
            // another tenant's 202 must not block ours
            ['extension_uuid' => 'x-other', 'domain_uuid' => 'dom-uuid-2', 'extension' => '202', 'description' => 'other'],
        ]));

        $result = app(ProvisionCompleteService::class)->ensureMobileExtension($this->domain(), 'Acme');

        $this->assertSame('202', $result['extension']);
        $this->assertGreaterThanOrEqual(200, (int) $result['extension']);
        $this->assertLessThanOrEqual(299, (int) $result['extension']);
    }

    public function test_exhausted_block_throws(): void
    {
        $rows = [];
        for ($n = 200; $n <= 299; $n++) {
            $rows[] = ['extension_uuid' => "x-$n", 'domain_uuid' => 'dom-uuid-1', 'extension' => (string) $n, 'description' => 'taken'];
        }
        Extensions::unguarded(fn () => Extensions::query()->insert($rows));

        $this->expectException(\RuntimeException::class);
        app(ProvisionCompleteService::class)->ensureMobileExtension($this->domain(), 'Acme');
    }

    public function test_no_enabled_agent_leaves_forwards_off(): void
    {
        $this->seedAgent('false');

        app(ProvisionCompleteService::class)->ensureMobileExtension($this->domain(), 'Acme');

        $ext = $this->mobile();
        $this->assertSame('false', $ext->forward_no_answer_enabled);
        $this->assertSame('false', $ext->forward_busy_enabled);
        $this->assertSame('false', $ext->forward_user_not_registered_enabled);
    }

    public function test_failover_follows_agent_enable_toggle_on_reprovision(): void
    {
        $svc = app(ProvisionCompleteService::class);
        $svc->ensureMobileExtension($this->domain(), 'Acme');
        $this->assertSame('false', $this->mobile()->forward_no_answer_enabled);

        $this->seedAgent();
        $svc->ensureMobileExtension($this->domain(), 'Acme');
        $this->assertSame('true', $this->mobile()->forward_no_answer_enabled);
        $this->assertSame('9250', $this->mobile()->forward_no_answer_destination);
    }

    // ---- did → caller-ID --------------------------------------------------

    public function test_did_sets_caller_id_digits_without_plus(): void
    {
        $svc = app(ProvisionCompleteService::class);
        $svc->ensureMobileExtension($this->domain(), 'Acme Plumbing');

        $svc->applyCallerId($this->domain(), '+443333051809', 'Acme Plumbing');

        $ext = $this->mobile();
        // OutboundCallerIdFixer / the stock OUTBOUND_CALLER_ID dialplan only
        // fire on ^\d{6,25}$ — a '+' would silently fall back to trunk CLI
        $this->assertSame('443333051809', $ext->getRawOriginal('outbound_caller_id_number'));
        $this->assertSame('Acme Plumbing', $ext->outbound_caller_id_name);
        $this->assertSame('443333051809', $ext->getRawOriginal('emergency_caller_id_number'));
        $this->assertSame('Acme Plumbing', $ext->emergency_caller_id_name);
    }

    public function test_did_must_be_e164(): void
    {
        $svc = app(ProvisionCompleteService::class);
        $svc->ensureMobileExtension($this->domain(), 'Acme');

        $this->expectException(\InvalidArgumentException::class);
        $svc->applyCallerId($this->domain(), '03333051809', 'Acme');
    }

    public function test_caller_id_is_noop_without_mobile_extension(): void
    {
        $this->assertNull(app(ProvisionCompleteService::class)->applyCallerId($this->domain(), '+443333051809', 'Acme'));
    }

    // ---- sim_msisdn → inbound destination --------------------------------

    public function test_sim_msisdn_creates_inbound_destination_to_mobile_extension(): void
    {
        $svc = app(ProvisionCompleteService::class);
        $svc->ensureMobileExtension($this->domain(), 'Acme');

        $dest = $svc->ensureMsisdnDestination($this->domain(), '+447434181294');

        $this->assertNotNull($dest);
        $row = Destinations::where('domain_uuid', 'dom-uuid-1')
            ->where('destination_description', ProvisionCompleteService::MSISDN_DESTINATION_DESCRIPTION)
            ->first();
        $this->assertNotNull($row);
        $this->assertSame('inbound', $row->destination_type);
        $this->assertSame('public', $row->destination_context);
        $this->assertSame('+447434181294', $row->destination_number);
        $this->assertSame([[
            'destination_app'  => 'transfer',
            'destination_data' => '200 XML acme.voxra.uk',
        ]], json_decode($row->destination_actions, true));

        Bus::assertDispatched(BuildDialplanForPhoneNumber::class);
    }

    public function test_sim_msisdn_destination_is_idempotent(): void
    {
        $svc = app(ProvisionCompleteService::class);
        $svc->ensureMobileExtension($this->domain(), 'Acme');
        $svc->ensureMsisdnDestination($this->domain(), '+447434181294');
        $svc->ensureMsisdnDestination($this->domain(), '+447434181294');

        $this->assertSame(1, Destinations::where('destination_number', '+447434181294')->count());
        Bus::assertDispatchedTimes(BuildDialplanForPhoneNumber::class, 1);
    }

    public function test_sim_msisdn_is_never_confused_with_the_reception_did(): void
    {
        $svc = app(ProvisionCompleteService::class);
        $svc->ensureMobileExtension($this->domain(), 'Acme');
        $svc->ensureMsisdnDestination($this->domain(), '+447434181294');

        // ring-first / line-mode look for 'Voxra reception%' rows only
        $this->assertNull((new \App\Services\ProvisionNumberService())->findReceptionDestination($this->domain()));
    }

    // ---- AgentFailoverService (shared with the artisan command) -----------

    public function test_failover_service_apply_and_clear(): void
    {
        $svc = new AgentFailoverService();
        $ext = new Extensions();
        $agent = new AiAgent();
        $agent->setRawAttributes(['agent_extension' => '9251']);

        $svc->applyTo($ext, $agent);
        $this->assertSame('9251', $ext->forward_no_answer_destination);
        $this->assertSame('9251', $ext->forward_busy_destination);
        $this->assertSame('9251', $ext->forward_user_not_registered_destination);
        $this->assertSame('true', $ext->forward_busy_enabled);

        $svc->clearOn($ext);
        $this->assertSame('false', $ext->forward_no_answer_enabled);
        $this->assertSame('false', $ext->forward_busy_enabled);
        $this->assertSame('false', $ext->forward_user_not_registered_enabled);
    }

    public function test_enabled_agent_ignores_disabled_and_non_reception_agents(): void
    {
        $svc = new AgentFailoverService();
        $this->assertNull($svc->enabledAgent($this->domain()));

        $this->seedAgent('false');
        $this->assertNull($svc->enabledAgent($this->domain()));

        AiAgent::where('ai_agent_uuid', 'agent-uuid-1')->delete();
        $this->seedAgent('true', 'summon');
        $this->assertNull($svc->enabledAgent($this->domain()));

        AiAgent::where('ai_agent_uuid', 'agent-uuid-1')->delete();
        $this->seedAgent();
        $this->assertSame('9250', $svc->enabledAgent($this->domain())->agent_extension);
    }

    public function test_e164_digits(): void
    {
        $this->assertSame('447434181294', ProvisionCompleteService::e164Digits('+44 7434 181294'));
        $this->assertNull(ProvisionCompleteService::e164Digits('07434181294'));
        $this->assertNull(ProvisionCompleteService::e164Digits('+44'));
        $this->assertNull(ProvisionCompleteService::e164Digits(null));
    }
}
