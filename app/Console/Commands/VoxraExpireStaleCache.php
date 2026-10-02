<?php

namespace App\Console\Commands;

use App\Models\DefaultSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Drop FusionPBX file-cache entries written before the latest directory /
 * dialplan change (voxragtm#194). Provisioning runs on lon1 and clears only
 * lon1's /var/cache/fusionpbx; eu1 gets the rows by DB replication but kept
 * serving its cached copy (Spark Lane's press-1 setting, 2 Oct). Scheduled
 * every minute on every PBX: any directory.* / dialplan.* file older than the
 * newest update_date on v_extensions, v_extension_settings, v_dialplans or
 * v_destinations (plus a margin for replication lag) is deleted, and
 * FusionPBX rebuilds it from the database on the next call.
 *
 *   php artisan voxra:expire-stale-cache --dry-run
 */
class VoxraExpireStaleCache extends Command
{
    /** Files written up to this long after the latest change are dropped too:
     *  a call can rebuild the cache on eu1 just before the change replicates. */
    public const REPLICATION_MARGIN_SECONDS = 120;

    protected $signature = 'voxra:expire-stale-cache {--dry-run : List what would be deleted}';

    protected $description = 'Delete FusionPBX directory/dialplan cache files older than the latest DB change (voxragtm#194)';

    public function handle(): int
    {
        $method = DefaultSettings::where('default_setting_category', 'cache')
            ->where('default_setting_subcategory', 'method')->value('default_setting_value');
        $location = DefaultSettings::where('default_setting_category', 'cache')
            ->where('default_setting_subcategory', 'location')->value('default_setting_value');
        if ($method !== 'file' || ! $location || ! is_dir($location)) {
            return self::SUCCESS;
        }

        $latest = self::latestChange();
        if ($latest === null) {
            return self::SUCCESS;
        }

        $deleted = 0;
        foreach (array_merge(glob($location . '/directory.*') ?: [], glob($location . '/dialplan.*') ?: []) as $file) {
            $mtime = @filemtime($file);
            if ($mtime === false || ! self::isStale($mtime, $latest)) {
                continue;
            }
            if ($this->option('dry-run')) {
                $this->line(basename($file));
            } else {
                @unlink($file);
            }
            $deleted++;
        }
        if ($deleted && $this->option('dry-run')) {
            $this->info("{$deleted} stale cache file(s)");
        }

        return self::SUCCESS;
    }

    /** Pure: a file written before the latest change (or within the margin after it) is stale. */
    public static function isStale(int $fileMtime, int $latestChange): bool
    {
        return $fileMtime < $latestChange + self::REPLICATION_MARGIN_SECONDS;
    }

    /** Unix time of the newest change to anything the directory/dialplan cache holds. */
    public static function latestChange(): ?int
    {
        $row = DB::selectOne(
            'SELECT GREATEST(
                (SELECT max(GREATEST(update_date, insert_date)) FROM v_extensions),
                (SELECT max(GREATEST(update_date, insert_date)) FROM v_extension_settings),
                (SELECT max(GREATEST(update_date, insert_date)) FROM v_dialplans),
                (SELECT max(GREATEST(update_date, insert_date)) FROM v_destinations)
            ) AS latest'
        );
        $latest = $row->latest ?? null;

        return $latest ? strtotime((string) $latest) : null;
    }
}
