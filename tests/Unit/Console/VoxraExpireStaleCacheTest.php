<?php

namespace Tests\Unit\Console;

use App\Console\Commands\VoxraExpireStaleCache;
use PHPUnit\Framework\TestCase;

class VoxraExpireStaleCacheTest extends TestCase
{
    public function test_cache_written_before_the_latest_change_is_stale(): void
    {
        $this->assertTrue(VoxraExpireStaleCache::isStale(1_000, 2_000));
    }

    public function test_cache_written_just_after_the_change_is_stale_too(): void
    {
        // A call can rebuild eu1's cache from the old row before the change replicates.
        $this->assertTrue(VoxraExpireStaleCache::isStale(2_060, 2_000));
    }

    public function test_cache_written_well_after_the_change_is_kept(): void
    {
        $this->assertFalse(VoxraExpireStaleCache::isStale(2_000 + VoxraExpireStaleCache::REPLICATION_MARGIN_SECONDS, 2_000));
        $this->assertFalse(VoxraExpireStaleCache::isStale(5_000, 2_000));
    }
}
