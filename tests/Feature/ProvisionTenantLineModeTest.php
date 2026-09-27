<?php

namespace Tests\Feature;

use App\Http\Controllers\Internal\ProvisionTenantController;
use App\Models\AiAgent;
use App\Models\Destinations;
use App\Models\Domain;
use App\Services\ProvisionLineService;
use App\Services\ProvisionNumberService;
use App\Services\Voxra\VoxraRoutingState;
use Tests\TestCase;

/**
 * Voxra Line mode (voxragtm#25): line_mode forces the reception agent off,
 * points the DID at the stock line extension, and toggling back restores
 * agent routing (the Line → Start upgrade). Pure-logic tests in the
 * ProvisionNumberServiceTest style — no Postgres test database.
 */
class ProvisionTenantLineModeTest extends TestCase
{
    private function domain(): Domain
    {
        $domain = new Domain();
        $domain->setRawAttributes([
            'domain_uuid' => 'dom-uuid-1',
            'domain_name' => 'acme.voxra.uk',
        ]);

        return $domain;
    }

    private function agent(): AiAgent
    {
        $agent = new AiAgent();
        $agent->setRawAttributes(['agent_extension' => '9250']);

        return $agent;
    }

    public function test_line_mode_forces_agent_disabled(): void
    {
        $this->assertFalse(ProvisionTenantController::resolveAgentEnabled(true, true));
        $this->assertFalse(ProvisionTenantController::resolveAgentEnabled(false, true));
    }

    public function test_upgrade_flip_re_enables_agent(): void
    {
        // Line → Start: line_mode:false + agent_enabled:true
        $this->assertTrue(ProvisionTenantController::resolveAgentEnabled(true, false));
        // kill-switch still wins without line mode
        $this->assertFalse(ProvisionTenantController::resolveAgentEnabled(false, false));
    }

    public function test_line_extension_is_stable_and_distinct_from_agent(): void
    {
        $this->assertSame('9260', ProvisionLineService::LINE_EXTENSION);
        $this->assertNotSame($this->agent()->agent_extension, ProvisionLineService::LINE_EXTENSION);
    }

    public function test_line_actions_transfer_did_to_line_extension(): void
    {
        $actions = (new ProvisionNumberService())->lineActions($this->domain());

        $this->assertSame([[
            'destination_app'  => 'transfer',
            'destination_data' => '9260 XML acme.voxra.uk',
        ]], $actions);
    }

    private function route(string $mode, bool $agentEnabled = true, ?string $mobile = null): array
    {
        return (new ProvisionNumberService())->resolveDidRouting(
            $this->domain(), $this->agent(), $mode, $agentEnabled, $mobile, 25, false,
        );
    }

    public function test_line_mode_routes_to_line_extension_whatever_came_before(): void
    {
        // The decision table never looks at the current routing: Line
        // always rewrites to 9260 (the old resolveLineModeActions patching
        // is gone, voxragtm#162).
        $routing = $this->route(VoxraRoutingState::MODE_LINE, false, '+447700900123');

        $this->assertSame('line_voicemail', $routing['kind']);
        $this->assertSame([[
            'destination_app'  => 'transfer',
            'destination_data' => '9260 XML acme.voxra.uk',
        ]], $routing['actions']);
    }

    public function test_leaving_line_mode_restores_agent_routing(): void
    {
        // Line → Start/Pro without ring-first
        $routing = $this->route(VoxraRoutingState::MODE_PRO);

        $this->assertSame([[
            'destination_app'  => 'transfer',
            'destination_data' => '9250 XML acme.voxra.uk',
        ]], $routing['actions']);
    }

    public function test_leaving_line_mode_with_ring_first_keeps_the_mobile_first(): void
    {
        $routing = $this->route(VoxraRoutingState::MODE_PRO, true, '+447700900123');

        $this->assertSame('ring_first_ai', $routing['kind']);
        $this->assertSame('9250 XML acme.voxra.uk', end($routing['actions'])['destination_data']);
    }

    public function test_follow_me_destination_uses_press_one_confirmation(): void
    {
        $attrs = ProvisionLineService::followMeDestinationAttributes('+447700900123');

        $this->assertSame('+447700900123', $attrs['follow_me_destination']);
        // '1' = stock follow-me answer confirmation so a carrier voicemail
        // answering the mobile leg cancels it instead of swallowing the call
        $this->assertSame('1', $attrs['follow_me_prompt']);
        $this->assertSame(25, $attrs['follow_me_timeout']);
        $this->assertSame(0, $attrs['follow_me_delay']);
        $this->assertSame(1, $attrs['follow_me_order']);
    }

    public function test_apply_did_actions_is_a_noop_without_a_routed_did(): void
    {
        $svc = \Mockery::mock(ProvisionNumberService::class)->makePartial();
        $svc->shouldReceive('findVoxraDestinations')->once()->andReturn(collect());

        // must not touch routing or dispatch a dialplan rebuild
        $this->assertSame(0, $svc->applyDidActions($this->domain(), (new ProvisionNumberService())->lineActions($this->domain())));
    }

    public function test_apply_did_actions_skips_unchanged_routing(): void
    {
        $actions = (new ProvisionNumberService())->lineActions($this->domain());
        $dest = new Destinations();
        $dest->setRawAttributes(['destination_actions' => json_encode($actions)]);

        $svc = \Mockery::mock(ProvisionNumberService::class)->makePartial();
        $svc->shouldReceive('findVoxraDestinations')->once()->andReturn(collect([$dest]));

        $this->assertSame(0, $svc->applyDidActions($this->domain(), $actions));
    }
}
