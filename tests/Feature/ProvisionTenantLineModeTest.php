<?php

namespace Tests\Feature;

use App\Http\Controllers\Internal\ProvisionTenantController;
use App\Models\AiAgent;
use App\Models\Destinations;
use App\Models\Domain;
use App\Services\ProvisionLineService;
use App\Services\ProvisionNumberService;
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

    public function test_enabling_line_mode_routes_to_line_extension(): void
    {
        $svc = new ProvisionNumberService();
        $current = json_encode($svc->ringFirstActions($this->domain(), $this->agent(), '+447700900123'));

        $actions = $svc->resolveLineModeActions($this->domain(), $this->agent(), true, $current);

        $this->assertSame('9260 XML acme.voxra.uk', $actions[0]['destination_data']);
    }

    public function test_disabling_line_mode_restores_agent_routing(): void
    {
        $svc = new ProvisionNumberService();
        $current = json_encode($svc->lineActions($this->domain()));

        $actions = $svc->resolveLineModeActions($this->domain(), $this->agent(), false, $current);

        $this->assertSame([[
            'destination_app'  => 'transfer',
            'destination_data' => '9250 XML acme.voxra.uk',
        ]], $actions);
    }

    public function test_disabling_line_mode_leaves_non_line_routing_alone(): void
    {
        $svc = new ProvisionNumberService();
        $agentOnly = json_encode([['destination_app' => 'transfer', 'destination_data' => '9250 XML acme.voxra.uk']]);
        $ringFirst = json_encode($svc->ringFirstActions($this->domain(), $this->agent(), '+447700900123'));

        $this->assertNull($svc->resolveLineModeActions($this->domain(), $this->agent(), false, $agentOnly));
        $this->assertNull($svc->resolveLineModeActions($this->domain(), $this->agent(), false, $ringFirst));
        $this->assertNull($svc->resolveLineModeActions($this->domain(), $this->agent(), false, null));
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

    public function test_apply_line_mode_is_a_noop_without_a_routed_did(): void
    {
        $svc = \Mockery::mock(ProvisionNumberService::class)->makePartial();
        $svc->shouldReceive('findReceptionDestination')->once()->andReturn(null);

        // must not touch routing or dispatch a dialplan rebuild
        $svc->applyLineMode($this->domain(), $this->agent(), true);
        $this->addToAssertionCount(1);
    }

    public function test_is_line_routed_detection(): void
    {
        $svc = new ProvisionNumberService();
        $line = json_encode($svc->lineActions($this->domain()));

        $this->assertTrue(ProvisionNumberService::isLineRouted($line, 'acme.voxra.uk'));
        $this->assertFalse(ProvisionNumberService::isLineRouted($line, 'other.voxra.uk'));
        $this->assertFalse(ProvisionNumberService::isLineRouted(
            json_encode($svc->ringFirstActions($this->domain(), $this->agent(), '+447700900123')),
            'acme.voxra.uk'
        ));
    }
}
