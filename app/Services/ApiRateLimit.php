<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Cache;

/**
 * Per-minute limits for the v1 API ('throttle:api').
 *
 * Customer tokens get 60 a minute. Superadmin tokens are our own server
 * integrations (iqportal-voxra, iqcrm-api); they can already reach every
 * domain, so a higher limit adds no reach. iqportal carries every reseller's
 * traffic on one token: a number purchase for a partner with 57 domains made
 * 60 duplicate-check calls before the create, so the create was refused with
 * 429 every time (25 Oct 2026).
 */
final class ApiRateLimit
{
    public const DEFAULT_PER_MINUTE = 60;
    public const SUPERADMIN_PER_MINUTE = 1200;

    public static function forUser(?User $user, string $ip): Limit
    {
        if ($user !== null && self::userIsSuperadmin($user)) {
            return Limit::perMinute(self::SUPERADMIN_PER_MINUTE)->by('api-superadmin:' . $user->user_uuid);
        }

        return Limit::perMinute(self::DEFAULT_PER_MINUTE)->by(optional($user)->id ?: $ip);
    }

    /**
     * Same rule as isSuperAdmin() in helpers.php. Cached briefly so each API
     * request doesn't re-read the user's groups.
     */
    private static function userIsSuperadmin(User $user): bool
    {
        return Cache::remember(
            'api-rate-limit:superadmin:' . $user->user_uuid,
            300,
            fn (): bool => self::groupsIncludeSuperadmin($user->groups()),
        );
    }

    /** @param iterable<object|null> $groups */
    public static function groupsIncludeSuperadmin(iterable $groups): bool
    {
        foreach ($groups as $group) {
            if ($group && $group->group_name == 'superadmin' && $group->group_level >= 80) {
                return true;
            }
        }

        return false;
    }
}
