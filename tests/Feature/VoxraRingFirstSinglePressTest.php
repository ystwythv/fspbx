<?php

namespace Tests\Feature;

use App\Jobs\BuildDialplanForPhoneNumber;
use App\Models\AiAgent;
use App\Models\Domain;
use App\Services\ProvisionNumberService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Ring-first asks the owner to press 1 ONCE (voxragtm#141) and still times
 * out to the AI (voxragtm#220).
 *
 * The confirm variables ride the loopback leg as per-leg [...] variables:
 * mod_loopback copies them to loopback-b (the phone leg confirms there) and
 * deletes group_confirm_* from loopback-a, so the outer bridge never asks a
 * second time. ignore_early_media=true keeps the outer originate waiting for
 * a real answer, so call_timeout still ends the ring. Plus the
 * voxra:rebuild-ring-first rollout. In-memory sqlite.
 */
class VoxraRingFirstSinglePressTest extends TestCase
{
    private const NAME = 'acme.voxra.uk';
    private const MOBILE = '+447700900123';
    private const OLD_BRIDGE = '{group_confirm_key=1,group_confirm_file=ivr/ivr-accept_reject_voicemail.wav,group_confirm_cancel_timeout=1}loopback/+447700900123/acme.voxra.uk';
    private const EXEC_BRIDGE = '{group_confirm_key=exec,group_confirm_file=lua lua/voxra_owner_confirm.lua,group_confirm_cancel_timeout=1}loopback/+447700900123/acme.voxra.uk';
    private const NEW_BRIDGE = '{ignore_early_media=true}[group_confirm_key=1,group_confirm_file=ivr/ivr-accept_reject_voicemail.wav,group_confirm_cancel_timeout=1]loopback/+447700900123/acme.voxra.uk';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('activitylog.enabled', false);
        Bus::fake();

        Schema::create('v_domains', function ($t) {
            $t->string('domain_uuid')->primary();
            $t->string('domain_name')->nullable();
            $t->string('domain_enabled')->nullable();
            $t->string('domain_description')->nullable();
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
            $t->string('destination_cid_name_prefix')->nullable();
            $t->text('destination_actions')->nullable();
            $t->string('destination_enabled')->nullable();
            $t->string('destination_context')->nullable();
            $t->string('destination_description')->nullable();
            $t->string('insert_date')->nullable();
            $t->string('update_date')->nullable();
            $t->string('update_user')->nullable();
        });
    }

    private function domain(string $uuid = 'dom-uuid-1', string $name = self::NAME): Domain
    {
        $domain = new Domain();
        $domain->setRawAttributes(['domain_uuid' => $uuid, 'domain_name' => $name]);

        return $domain;
    }

    private function agent(): AiAgent
    {
        $agent = new AiAgent();
        $agent->setRawAttributes(['agent_extension' => '9250']);

        return $agent;
    }

    /** Ring-first actions as provisioned before voxragtm#141, with $bridge. */
    private static function ringFirstJson(string $bridge, string $domain = self::NAME, string $after = '9250'): string
    {
        return json_encode([
            ['destination_app' => 'set', 'destination_data' => 'hangup_after_bridge=true'],
            ['destination_app' => 'set', 'destination_data' => 'call_timeout=25'],
            ['destination_app' => 'set', 'destination_data' => 'continue_on_fail=true'],
            ['destination_app' => 'bridge', 'destination_data' => $bridge],
            ['destination_app' => 'transfer', 'destination_data' => $after . ' XML ' . $domain],
        ]);
    }

    private function seedDomain(string $uuid, string $name, string $description): void
    {
        DB::table('v_domains')->insert([
            'domain_uuid' => $uuid, 'domain_name' => $name, 'domain_enabled' => 'true', 'domain_description' => $description,
        ]);
    }

    private function seedDid(string $uuid, string $domainUuid, string $number, ?string $actions): void
    {
        DB::table('v_destinations')->insert([
            'destination_uuid'        => $uuid,
            'domain_uuid'             => $domainUuid,
            'dialplan_uuid'           => 'dp-' . $uuid,
            'destination_type'        => 'inbound',
            'destination_number'      => $number,
            'destination_prefix'      => '44',
            'destination_actions'     => $actions,
            'destination_enabled'     => 'true',
            'destination_context'     => 'public',
            'destination_description' => 'Inbound +44' . $number,
            'insert_date'             => '2026-09-01 10:00:00',
        ]);
    }

    private function actionsOf(string $uuid): string
    {
        return (string) DB::table('v_destinations')->where('destination_uuid', $uuid)->value('destination_actions');
    }

    // ---- the dial string ------------------------------------------------------

    public function test_confirm_is_per_leg_and_the_outer_bridge_waits_for_a_real_answer(): void
    {
        $dial = ProvisionNumberService::ringFirstDialString(self::MOBILE, self::NAME);

        $this->assertSame(self::NEW_BRIDGE, $dial);
        // Global {…} vars: only ignore_early_media — no group_confirm there,
        // or the outer originate confirms on loopback-a a second time.
        $this->assertMatchesRegularExpression('/^\{ignore_early_media=true\}\[/', $dial);
        $this->assertDoesNotMatchRegularExpression('/\{[^}]*group_confirm/', $dial);
        // Per-leg […] vars carry the plain press-1 confirm (not exec/lua,
        // which accepted loopback-a on early media: voxragtm#220).
        $this->assertMatchesRegularExpression('/\[[^\]]*group_confirm_key=1,[^\]]*\]loopback\//', $dial);
        $this->assertStringNotContainsString('exec', $dial);
        $this->assertStringContainsString('group_confirm_cancel_timeout=1]', $dial);
    }

    public function test_ring_first_actions_use_it_with_the_timeout_and_fallthrough(): void
    {
        $svc = new ProvisionNumberService();
        $data = array_map(
            fn ($a) => $a['destination_app'] . ' ' . $a['destination_data'],
            $svc->ringFirstActions($this->domain(), $this->agent(), self::MOBILE, 25),
        );

        $this->assertSame([
            'set hangup_after_bridge=true',
            'set call_timeout=25',
            'set continue_on_fail=true',
            'bridge ' . self::NEW_BRIDGE,
            'transfer 9250 XML ' . self::NAME,
        ], $data);
    }

    public function test_owner_recording_wraps_the_same_bridge(): void
    {
        $actions = (new ProvisionNumberService())->ringFirstBridgeActions($this->domain(), self::MOBILE, 20, '/tmp/prompt.wav');
        $bridges = array_values(array_filter($actions, fn ($a) => $a['destination_app'] === 'bridge'));

        $this->assertCount(1, $bridges);
        $this->assertSame(self::NEW_BRIDGE, $bridges[0]['destination_data']);
    }

    public function test_the_new_bridge_is_still_recognised_as_voxra_routing(): void
    {
        $json = json_encode([['destination_app' => 'bridge', 'destination_data' => self::NEW_BRIDGE]]);

        $this->assertTrue(ProvisionNumberService::isVoxraDid('Main line', $json, self::NAME));
        $this->assertSame(self::MOBILE, ProvisionNumberService::ringFirstMobileIn(self::ringFirstJson(self::NEW_BRIDGE)));
    }

    // ---- rollout: refreshRingFirstActions -------------------------------------

    public function test_old_and_exec_bridges_are_rewritten_everything_else_kept(): void
    {
        foreach ([self::OLD_BRIDGE, self::EXEC_BRIDGE] as $old) {
            $json = ProvisionNumberService::refreshRingFirstActions(self::ringFirstJson($old), self::NAME);

            $this->assertSame(self::ringFirstJson(self::NEW_BRIDGE), $json, $old);
        }

        // Pro with the AI off: ring-first, then voicemail — the voicemail stays.
        $this->assertSame(
            self::ringFirstJson(self::NEW_BRIDGE, self::NAME, '*999260'),
            ProvisionNumberService::refreshRingFirstActions(self::ringFirstJson(self::OLD_BRIDGE, self::NAME, '*999260'), self::NAME),
        );
    }

    public function test_nothing_to_do_returns_null(): void
    {
        // already current
        $this->assertNull(ProvisionNumberService::refreshRingFirstActions(self::ringFirstJson(self::NEW_BRIDGE), self::NAME));
        // no ring-first: straight to the AI, or Line voicemail
        $this->assertNull(ProvisionNumberService::refreshRingFirstActions(
            json_encode([['destination_app' => 'transfer', 'destination_data' => '9250 XML ' . self::NAME]]), self::NAME,
        ));
        // a loopback bridge without a confirm, or into another domain, isn't ours
        $this->assertNull(ProvisionNumberService::refreshRingFirstActions(self::ringFirstJson('loopback/+447700900123/' . self::NAME), self::NAME));
        $this->assertNull(ProvisionNumberService::refreshRingFirstActions(
            self::ringFirstJson(str_replace(self::NAME, 'other.voxra.uk', self::OLD_BRIDGE), 'other.voxra.uk'), self::NAME,
        ));
        // junk
        $this->assertNull(ProvisionNumberService::refreshRingFirstActions(null, self::NAME));
        $this->assertNull(ProvisionNumberService::refreshRingFirstActions('not json', self::NAME));
    }

    // ---- rollout: voxra:rebuild-ring-first ------------------------------------

    public function test_rebuild_command_rewrites_ring_first_dids_only_and_rebuilds_them(): void
    {
        $this->seedDomain('dom-a', self::NAME, 'voxra-tenant:t-a');
        $this->seedDomain('dom-b', 'bravo.voxra.uk', 'voxra-tenant:t-b');
        $this->seedDomain('dom-c', 'pbx-customer.example', 'Some other PBX customer');

        $this->seedDid('d-a', 'dom-a', '1225000001', self::ringFirstJson(self::OLD_BRIDGE));
        $this->seedDid('d-b', 'dom-b', '1225000002', json_encode([['destination_app' => 'transfer', 'destination_data' => '9250 XML bravo.voxra.uk']]));
        $otherOld = str_replace(self::NAME, 'pbx-customer.example', self::OLD_BRIDGE);
        $this->seedDid('d-c', 'dom-c', '1225000003', self::ringFirstJson($otherOld, 'pbx-customer.example'));

        // Dry run: reports, changes nothing.
        $this->artisan('voxra:rebuild-ring-first', ['--dry-run' => true])
            ->expectsOutputToContain('Would rewrite 1 ring-first DID(s).')
            ->assertExitCode(0);
        $this->assertSame(self::ringFirstJson(self::OLD_BRIDGE), $this->actionsOf('d-a'));
        Bus::assertNotDispatched(BuildDialplanForPhoneNumber::class);

        $this->artisan('voxra:rebuild-ring-first')
            ->expectsOutputToContain('Rewrote 1 ring-first DID(s).')
            ->assertExitCode(0);
        $this->assertSame(self::ringFirstJson(self::NEW_BRIDGE), $this->actionsOf('d-a'));
        // AI-only tenant and non-Voxra domains untouched.
        $this->assertStringNotContainsString('loopback', $this->actionsOf('d-b'));
        $this->assertSame(self::ringFirstJson($otherOld, 'pbx-customer.example'), $this->actionsOf('d-c'));
        Bus::assertDispatchedTimes(BuildDialplanForPhoneNumber::class, 1);

        // Idempotent.
        $this->artisan('voxra:rebuild-ring-first')
            ->expectsOutputToContain('Rewrote 0 ring-first DID(s).')
            ->assertExitCode(0);
        Bus::assertDispatchedTimes(BuildDialplanForPhoneNumber::class, 1);
    }

    public function test_rebuild_command_can_target_one_domain(): void
    {
        $this->seedDomain('dom-a', self::NAME, 'voxra-tenant:t-a');
        $this->seedDomain('dom-b', 'bravo.voxra.uk', 'voxra-tenant:t-b');
        $this->seedDid('d-a', 'dom-a', '1225000001', self::ringFirstJson(self::OLD_BRIDGE));
        $bravoOld = str_replace(self::NAME, 'bravo.voxra.uk', self::OLD_BRIDGE);
        $this->seedDid('d-b', 'dom-b', '1225000002', self::ringFirstJson($bravoOld, 'bravo.voxra.uk'));

        $this->artisan('voxra:rebuild-ring-first', ['--domain' => 'bravo.voxra.uk'])
            ->expectsOutputToContain('Rewrote 1 ring-first DID(s).')
            ->assertExitCode(0);

        $this->assertSame(self::ringFirstJson(self::OLD_BRIDGE), $this->actionsOf('d-a'));
        $this->assertStringContainsString('[group_confirm_key=1', $this->actionsOf('d-b'));
    }
}
