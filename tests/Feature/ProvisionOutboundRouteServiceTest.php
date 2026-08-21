<?php

namespace Tests\Feature;

use App\Models\Dialplans;
use App\Models\Domain;
use App\Services\ProvisionOutboundRouteService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Per-tenant "Voxra Outbound" route (voxragtm#110) against an in-memory
 * sqlite schema: provisioning creates the dialplan bridging UK national and
 * E.164 numbers to the configured gateway, re-running is idempotent, the
 * gateway resolves by name or uuid (never hardcoded), and an unresolvable
 * gateway skips cleanly instead of failing provisioning.
 */
class ProvisionOutboundRouteServiceTest extends TestCase
{
    private const GW_UUID  = 'bbf73d75-75e8-4c77-8e39-cf131ab16653';
    private const GW2_UUID = '7d3f2a90-1234-4abc-9def-0123456789ab';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('activitylog.enabled', false);

        $this->createSchema();

        DB::table('v_gateways')->insert([
            ['gateway_uuid' => self::GW_UUID, 'gateway' => 'magrathea', 'enabled' => 'true'],
            ['gateway_uuid' => self::GW2_UUID, 'gateway' => 'backup-trunk', 'enabled' => 'true'],
        ]);
    }

    private function createSchema(): void
    {
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
        Schema::create('v_gateways', function ($t) {
            $t->string('gateway_uuid')->primary();
            $t->string('gateway')->nullable();
            $t->string('enabled')->nullable();
        });
        // FusionCache::clear settings lookup
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

    public function test_creates_outbound_route_dialplan(): void
    {
        app(ProvisionOutboundRouteService::class)->ensureOutboundRoute($this->domain());

        $dp = Dialplans::where('domain_uuid', 'dom-uuid-1')
            ->where('dialplan_description', ProvisionOutboundRouteService::DIALPLAN_DESCRIPTION)
            ->first();

        $this->assertNotNull($dp);
        $this->assertSame('acme.voxra.uk', $dp->dialplan_context);
        $this->assertSame('Voxra Outbound', $dp->dialplan_name);
        $this->assertEquals(ProvisionOutboundRouteService::DIALPLAN_ORDER, $dp->dialplan_order);
        $this->assertTrue($dp->dialplan_enabled); // model casts 'true' → bool
        $this->assertSame('false', $dp->dialplan_continue);

        // gateway resolved from v_gateways by name — uuid comes from the row
        $this->assertStringContainsString(
            'sofia/gateway/' . self::GW_UUID . '/${destination_number}',
            $dp->dialplan_xml
        );
        // both UK formats routed, scoped to UK (patterns asserted in detail below)
        $this->assertStringContainsString(ProvisionOutboundRouteService::UK_NATIONAL_PATTERN, $dp->dialplan_xml);
        $this->assertStringContainsString(ProvisionOutboundRouteService::UK_E164_PATTERN, $dp->dialplan_xml);
    }

    public function test_reprovision_is_idempotent_and_tracks_gateway_change(): void
    {
        $svc = app(ProvisionOutboundRouteService::class);
        $first = $svc->ensureOutboundRoute($this->domain());
        $svc->ensureOutboundRoute($this->domain());

        $this->assertSame(1, Dialplans::count());
        $this->assertSame(
            $first->dialplan_uuid,
            Dialplans::first()->dialplan_uuid
        );

        // re-pointing VOXRA_OUTBOUND_GATEWAY updates the same row's bridge target
        config()->set('services.voxra.outbound_gateway', 'backup-trunk');
        $svc->ensureOutboundRoute($this->domain());

        $this->assertSame(1, Dialplans::count());
        $this->assertStringContainsString('sofia/gateway/' . self::GW2_UUID . '/', Dialplans::first()->dialplan_xml);
    }

    public function test_gateway_resolves_by_uuid_config(): void
    {
        config()->set('services.voxra.outbound_gateway', self::GW2_UUID);

        app(ProvisionOutboundRouteService::class)->ensureOutboundRoute($this->domain());

        $this->assertStringContainsString('sofia/gateway/' . self::GW2_UUID . '/', Dialplans::first()->dialplan_xml);
    }

    public function test_unresolvable_or_disabled_gateway_skips_without_failing(): void
    {
        config()->set('services.voxra.outbound_gateway', 'no-such-gateway');
        $this->assertNull(app(ProvisionOutboundRouteService::class)->ensureOutboundRoute($this->domain()));
        $this->assertSame(0, Dialplans::count());

        DB::table('v_gateways')->where('gateway_uuid', self::GW_UUID)->update(['enabled' => 'false']);
        config()->set('services.voxra.outbound_gateway', 'magrathea');
        $this->assertNull(app(ProvisionOutboundRouteService::class)->ensureOutboundRoute($this->domain()));
        $this->assertSame(0, Dialplans::count());
    }

    public function test_route_patterns_cover_uk_national_and_e164_only(): void
    {
        $national = '/' . ProvisionOutboundRouteService::UK_NATIONAL_PATTERN . '/';
        $e164     = '/' . ProvisionOutboundRouteService::UK_E164_PATTERN . '/';
        $matches  = fn (string $n): bool => (bool) (preg_match($national, $n) || preg_match($e164, $n));

        // what follow-me / ring-first actually dial (E.164, normaliseOwnerMobile)
        $this->assertTrue($matches('+447944779309'));
        $this->assertTrue($matches('+441225778899'));
        // country-prefixed without + and UK national both route too
        $this->assertTrue($matches('447944779309'));
        $this->assertTrue($matches('07944779309'));
        $this->assertTrue($matches('01225778899'));

        // never swallows internal extensions, feature codes, emergency or
        // international destinations
        foreach (['9260', '9250', '*9', '*99', '*997944779309', '999', '112', '911', '933', '+33123456789', '+15551234567', '0', ''] as $n) {
            $this->assertFalse($matches($n), "pattern must not match '$n'");
        }
    }
}
