<?php

namespace Tests\Feature\Api\V1\Cdr;

use Tests\TestCase;

/**
 * Route-level protection smoke tests.
 *
 * These assert the middleware chain rejects unauthenticated / unbearered
 * requests before ever reaching the controller. Full domain-scoping behaviour
 * is covered by tests/Unit/Cdr/* and will be tested end-to-end once fixture
 * infrastructure exists.
 */
class CdrRouteProtectionTest extends TestCase
{
    private const DOMAIN = '00000000-0000-0000-0000-000000000001';

    public function test_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/domains/' . self::DOMAIN . '/cdr/calls')
            ->assertStatus(401);
    }

    public function test_summary_requires_authentication(): void
    {
        $this->getJson('/api/v1/domains/' . self::DOMAIN . '/cdr/stats/summary')
            ->assertStatus(401);
    }

    public function test_show_requires_authentication(): void
    {
        $uuid = '00000000-0000-0000-0000-000000000002';
        $this->getJson('/api/v1/domains/' . self::DOMAIN . '/cdr/calls/' . $uuid)
            ->assertStatus(401);
    }

    public function test_admin_token_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/api-tokens')
            ->assertStatus(401);
    }

    public function test_admin_token_create_requires_authentication(): void
    {
        $this->postJson('/api/v1/admin/api-tokens', [
            'name' => 'test',
            'type' => 'global',
        ])->assertStatus(401);
    }

    public function test_by_extension_requires_authentication(): void
    {
        $this->getJson('/api/v1/domains/' . self::DOMAIN . '/cdr/stats/by-extension')
            ->assertStatus(401);
    }

    public function test_timeseries_requires_authentication(): void
    {
        $this->getJson('/api/v1/domains/' . self::DOMAIN . '/cdr/stats/timeseries')
            ->assertStatus(401);
    }

    public function test_quality_requires_authentication(): void
    {
        $this->getJson('/api/v1/domains/' . self::DOMAIN . '/cdr/stats/quality')
            ->assertStatus(401);
    }

    public function test_top_destinations_requires_authentication(): void
    {
        $this->getJson('/api/v1/domains/' . self::DOMAIN . '/cdr/stats/top-destinations')
            ->assertStatus(401);
    }

    public function test_global_calls_list_requires_authentication(): void
    {
        $this->getJson('/api/v1/cdr/calls')
            ->assertStatus(401);
    }

    public function test_global_summary_requires_authentication(): void
    {
        $this->getJson('/api/v1/cdr/stats/summary')
            ->assertStatus(401);
    }
}
