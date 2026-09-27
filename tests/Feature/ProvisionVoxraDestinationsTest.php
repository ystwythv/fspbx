<?php

namespace Tests\Feature;

use App\Jobs\BuildDialplanForPhoneNumber;
use App\Models\AiAgent;
use App\Models\Destinations;
use App\Models\Domain;
use App\Services\ProvisionCompleteService;
use App\Services\ProvisionNumberService;
use App\Services\Voxra\VoxraRoutingState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Which inbound numbers Voxra provisioning routes (fspbx#132 review). Live
 * Voxra numbers are iqportal Magrathea DDIs created through the V1 API —
 * description "Inbound +44…", prefix 44, national destination_number — not
 * the "Voxra reception…" rows of the Telnyx auto-order path, so matching on
 * that description alone meant routing never reached a real DID. Every
 * matching number of the tenant is rewritten; Complete eSIM numbers never.
 * In-memory sqlite.
 */
class ProvisionVoxraDestinationsTest extends TestCase
{
    private const DOMAIN = 'dom-uuid-1';
    private const NAME = 'acme.voxra.uk';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('activitylog.enabled', false);
        Bus::fake();

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

    private function domain(): Domain
    {
        $domain = new Domain();
        $domain->setRawAttributes(['domain_uuid' => self::DOMAIN, 'domain_name' => self::NAME]);

        return $domain;
    }

    private function agent(): AiAgent
    {
        $agent = new AiAgent();
        $agent->setRawAttributes(['agent_extension' => '9250']);

        return $agent;
    }

    private static function transfer(string $target, string $domain = self::NAME): string
    {
        return json_encode([['destination_app' => 'transfer', 'destination_data' => $target . ' XML ' . $domain]]);
    }

    /** A row as iqportal's VoxraInboundPhoneNumberPayloadBuilder creates it. */
    private function seedDid(string $uuid, string $number, ?string $description, ?string $actions, array $extra = []): void
    {
        DB::table('v_destinations')->insert(array_merge([
            'destination_uuid'        => $uuid,
            'domain_uuid'             => self::DOMAIN,
            'dialplan_uuid'           => 'dp-' . $uuid,
            'destination_type'        => 'inbound',
            'destination_number'      => $number,
            'destination_prefix'      => '44',
            'destination_actions'     => $actions,
            'destination_enabled'     => 'true',
            'destination_context'     => 'public',
            'destination_description' => $description,
            'insert_date'             => '2026-09-0' . (count(DB::table('v_destinations')->get()) + 1) . ' 10:00:00',
        ], $extra));
    }

    private function uuids(): array
    {
        return (new ProvisionNumberService())->findVoxraDestinations($this->domain())
            ->pluck('destination_uuid')->all();
    }

    // ---- matching rules (pure) --------------------------------------------

    public function test_description_variants(): void
    {
        $this->assertTrue(ProvisionNumberService::isVoxraDid('Inbound +441225685853', self::transfer('9250'), self::NAME));
        $this->assertTrue(ProvisionNumberService::isVoxraDid('Voxra reception (auto-provisioned)', self::transfer('9250'), self::NAME));
        // iqportal description with actions some other tool wrote
        $this->assertTrue(ProvisionNumberService::isVoxraDid('Inbound +443333050611', null, self::NAME));
        // unlabelled (e.g. created without the description) but routed by Voxra
        $this->assertTrue(ProvisionNumberService::isVoxraDid(null, self::transfer('9250'), self::NAME));
        $this->assertTrue(ProvisionNumberService::isVoxraDid('Main line', self::transfer('9260'), self::NAME));
        $this->assertTrue(ProvisionNumberService::isVoxraDid('', self::transfer('*999260'), self::NAME));
        // unrelated number in the domain
        $this->assertFalse(ProvisionNumberService::isVoxraDid('Main line', self::transfer('100'), self::NAME));
        $this->assertFalse(ProvisionNumberService::isVoxraDid(null, null, self::NAME));
        // agent-range transfer into another domain doesn't count
        $this->assertFalse(ProvisionNumberService::isVoxraDid('Main line', self::transfer('9250', 'other.voxra.uk'), self::NAME));
    }

    public function test_voxras_own_rewrites_are_still_recognised(): void
    {
        $svc = new ProvisionNumberService();
        foreach ([
            $svc->ringFirstActions($this->domain(), $this->agent(), '+447700900123', 25),
            array_merge($svc->ringFirstBridgeActions($this->domain(), '+447700900123', 20), $svc->voicemailActions($this->domain())),
            $svc->voicemailActions($this->domain()),
            $svc->lineActions($this->domain()),
            $svc->suspendedActions(null),
        ] as $actions) {
            $this->assertTrue(ProvisionNumberService::isVoxraDid('Some label', json_encode($actions), self::NAME), json_encode($actions));
        }
    }

    public function test_complete_numbers_are_excluded(): void
    {
        // iqportal mode:extension → the eSIM's mobile extension; iqportal owns it
        $this->assertFalse(ProvisionNumberService::isVoxraDid('Inbound +443333051809', self::transfer('200'), self::NAME));
        $this->assertFalse(ProvisionNumberService::isVoxraDid('Inbound +443333051809', self::transfer('299'), self::NAME));
        // the SIM's own MSISDN row
        $this->assertFalse(ProvisionNumberService::isVoxraDid(
            ProvisionCompleteService::MSISDN_DESTINATION_DESCRIPTION, self::transfer('200'), self::NAME,
        ));
    }

    public function test_enabled_values(): void
    {
        foreach (['true', '1', 'TRUE', true] as $v) {
            $this->assertTrue(ProvisionNumberService::isEnabled($v));
        }
        foreach (['false', '0', '', null, false] as $v) {
            $this->assertFalse(ProvisionNumberService::isEnabled($v));
        }
    }

    // ---- lookup + apply (sqlite) ------------------------------------------

    public function test_finds_every_voxra_number_oldest_first_and_skips_the_rest(): void
    {
        $this->seedDid('a-iqportal', '1225685853', 'Inbound +441225685853', self::transfer('9250'));
        $this->seedDid('b-extra', '3333050611', 'Inbound +443333050611', self::transfer('9260'));
        $this->seedDid('c-complete', '3333051809', 'Inbound +443333051809', self::transfer('200'));
        $this->seedDid('d-msisdn', '+447434181294', ProvisionCompleteService::MSISDN_DESTINATION_DESCRIPTION, self::transfer('200'), ['destination_prefix' => null]);
        $this->seedDid('e-disabled', '1225000000', 'Inbound +441225000000', self::transfer('9250'), ['destination_enabled' => 'false']);
        $this->seedDid('f-other', '1225111111', 'Main line', self::transfer('100'));
        DB::table('v_destinations')->insert([
            'destination_uuid' => 'g-other-domain', 'domain_uuid' => 'dom-uuid-2', 'destination_type' => 'inbound',
            'destination_number' => '1225222222', 'destination_enabled' => 'true',
            'destination_description' => 'Inbound +441225222222', 'destination_actions' => self::transfer('9250', 'other.voxra.uk'),
        ]);

        $this->assertSame(['a-iqportal', 'b-extra'], $this->uuids());
        $this->assertSame('a-iqportal', (new ProvisionNumberService())->findReceptionDestination($this->domain())->destination_uuid);
    }

    public function test_routing_is_applied_to_every_voxra_number_and_nothing_else(): void
    {
        $this->seedDid('a-iqportal', '1225685853', 'Inbound +441225685853', self::transfer('9250'), ['destination_cid_name_prefix' => 'VX']);
        $this->seedDid('b-extra', '3333050611', 'Inbound +443333050611', self::transfer('9250'));
        $this->seedDid('c-complete', '3333051809', 'Inbound +443333051809', self::transfer('200'));

        $svc = new ProvisionNumberService();
        $routing = $svc->resolveDidRouting($this->domain(), $this->agent(), VoxraRoutingState::MODE_PRO, false, null, 20, false);

        $this->assertSame(2, $svc->applyDidActions($this->domain(), $routing['actions']));

        $rows = Destinations::orderBy('destination_uuid')->get()->keyBy('destination_uuid');
        foreach (['a-iqportal', 'b-extra'] as $uuid) {
            // fully replaces what iqportal wrote
            $this->assertSame(json_encode($routing['actions']), $rows[$uuid]->destination_actions);
        }
        // iqportal's fields untouched
        $this->assertSame('1225685853', $rows['a-iqportal']->destination_number);
        $this->assertSame('44', $rows['a-iqportal']->destination_prefix);
        $this->assertSame('Inbound +441225685853', $rows['a-iqportal']->destination_description);
        $this->assertSame('VX', $rows['a-iqportal']->destination_cid_name_prefix);
        $this->assertSame('true', $rows['a-iqportal']->destination_enabled);
        // the Complete number is iqportal's
        $this->assertSame(self::transfer('200'), $rows['c-complete']->destination_actions);

        Bus::assertDispatchedTimes(BuildDialplanForPhoneNumber::class, 2);

        // idempotent: nothing to rewrite the second time
        $this->assertSame(0, $svc->applyDidActions($this->domain(), $routing['actions']));
        Bus::assertDispatchedTimes(BuildDialplanForPhoneNumber::class, 2);
    }

    public function test_iqportal_reroute_after_provision_is_overwritten_on_the_next_provision(): void
    {
        $svc = new ProvisionNumberService();
        $this->seedDid('a-iqportal', '1225685853', 'Inbound +441225685853', self::transfer('9250'));

        $suspended = $svc->resolveDidRouting($this->domain(), $this->agent(), VoxraRoutingState::MODE_PRO, false, null, 20, true)['actions'];
        $this->assertSame(1, $svc->applyDidActions($this->domain(), $suspended));

        // iqportal PATCH (routing_options ai_agent) rewrites the actions…
        DB::table('v_destinations')->update(['destination_actions' => self::transfer('9250')]);
        // …and voxraweb's follow-up re-provision puts the resolved routing back
        $this->assertSame(1, $svc->applyDidActions($this->domain(), $suspended));
        $this->assertSame(json_encode($suspended), Destinations::first()->destination_actions);
    }

    public function test_existing_iqportal_number_is_returned_as_e164_and_never_reordered(): void
    {
        config()->set('services.voxra.provision_order_number', true);
        $this->app->bind(\App\Services\TelnyxNumberService::class, function () {
            throw new \RuntimeException('Telnyx must not be called when the tenant already has a number');
        });
        $this->seedDid('a-iqportal', '1225685853', 'Inbound +441225685853', self::transfer('9250'));

        $this->assertSame('+441225685853', (new ProvisionNumberService())->orderAndRoute($this->domain(), $this->agent(), 'rg'));
    }

    public function test_loop_guard_knows_iqportal_national_rows(): void
    {
        $this->seedDid('a-iqportal', '7700900123', 'Inbound +447700900123', self::transfer('9250'));

        $this->assertTrue((new ProvisionNumberService())->isHostedNumber('+447700900123'));
        $this->assertFalse((new ProvisionNumberService())->isHostedNumber('+447700900999'));
    }
}
