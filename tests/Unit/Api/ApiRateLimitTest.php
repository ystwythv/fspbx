<?php

namespace Tests\Unit\Api;

use App\Services\ApiRateLimit;
use PHPUnit\Framework\TestCase;

/**
 * Superadmin tokens (iqportal, iqcrm) get the higher v1 API limit; everyone
 * else keeps 60 a minute.
 */
class ApiRateLimitTest extends TestCase
{
    public function test_superadmin_group_at_level_80_or_more_counts(): void
    {
        $this->assertTrue(ApiRateLimit::groupsIncludeSuperadmin([
            (object) ['group_name' => 'admin', 'group_level' => 50],
            (object) ['group_name' => 'superadmin', 'group_level' => 80],
        ]));
    }

    public function test_other_groups_low_level_and_missing_groups_do_not(): void
    {
        $this->assertFalse(ApiRateLimit::groupsIncludeSuperadmin([]));
        $this->assertFalse(ApiRateLimit::groupsIncludeSuperadmin([null]));
        $this->assertFalse(ApiRateLimit::groupsIncludeSuperadmin([(object) ['group_name' => 'admin', 'group_level' => 90]]));
        $this->assertFalse(ApiRateLimit::groupsIncludeSuperadmin([(object) ['group_name' => 'superadmin', 'group_level' => 50]]));
    }

    public function test_guest_requests_keep_the_default_limit_keyed_by_ip(): void
    {
        $limit = ApiRateLimit::forUser(null, '203.0.113.9');
        $this->assertSame(60, $limit->maxAttempts);
        $this->assertSame('203.0.113.9', $limit->key);
    }
}
