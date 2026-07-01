<?php

namespace Tests\Feature\Api\V1\Reception;

use Tests\TestCase;

/**
 * Route-level middleware smoke tests for the reception read API (voxragtm#50).
 * End-to-end behaviour (a seeded tenant with leads/appointments) is exercised
 * via the Ansible-deployed staging host after merge; these cover the auth chain
 * so an unauthenticated caller can't reach the controller.
 */
class ReceptionRouteProtectionTest extends TestCase
{
    private const DOMAIN = '00000000-0000-0000-0000-000000000001';

    public function test_leads_requires_authentication(): void
    {
        $this->getJson('/api/v1/domains/' . self::DOMAIN . '/reception/leads')
            ->assertStatus(401);
    }

    public function test_appointments_requires_authentication(): void
    {
        $this->getJson('/api/v1/domains/' . self::DOMAIN . '/reception/appointments')
            ->assertStatus(401);
    }
}
