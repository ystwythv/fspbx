<?php

namespace Tests\Feature;

use App\Http\Controllers\Internal\ProvisionTenantController;
use Tests\TestCase;

/**
 * Answering kill-switch (voxragtm#81): the provision payload's optional
 * agent_enabled boolean must reach upsertReceptionAgent as the FusionPBX
 * 'true'/'false' string instead of the previous hard-coded 'true'.
 */
class ProvisionTenantAgentEnabledTest extends TestCase
{
    public function test_agent_enabled_true_maps_to_string_true(): void
    {
        $inputs = app(ProvisionTenantController::class)->receptionAgentInputs('Acme Plumbing', true);

        $this->assertSame('true', $inputs['agent_enabled']);
        $this->assertSame('Acme Plumbing Reception', $inputs['agent_name']);
    }

    public function test_agent_enabled_false_maps_to_string_false(): void
    {
        $inputs = app(ProvisionTenantController::class)->receptionAgentInputs('Acme Plumbing', false);

        $this->assertSame('false', $inputs['agent_enabled']);
    }
}
