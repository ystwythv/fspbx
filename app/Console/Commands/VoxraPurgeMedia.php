<?php

namespace App\Console\Commands;

use App\Services\TelnyxConvaiService;
use App\Services\Voxra\VoxraMediaPurgeService;
use Illuminate\Console\Command;

/**
 * Voxra call-audio retention (voxragtm#83): delete PBX call recordings,
 * voicemail audio, Telnyx AI call recordings and Telnyx conversation copies
 * older than --days (default services.voxra.recording_retention_days,
 * VOXRA_RECORDING_RETENTION_DAYS, 10) for Voxra tenant domains, plus PBX-only
 * media for services.voxra.retention_pbx_domains at retention_pbx_days (90,
 * voxragtm#132). Scheduled
 * daily in the Kernel; safe to run by hand.
 *
 *   php artisan voxra:purge-media --dry-run            # what would go
 *   php artisan voxra:purge-media --limit=50           # small batch
 *   php artisan voxra:purge-media --tenant=<id> --all  # account erasure
 */
class VoxraPurgeMedia extends Command
{
    protected $signature = 'voxra:purge-media
        {--days= : Retention period in days (age sweep; default VOXRA_RECORDING_RETENTION_DAYS, 10)}
        {--tenant= : Only this voxraweb tenant id}
        {--all : Every item for --tenant regardless of age (account erasure)}
        {--limit=500 : Maximum items per store per domain in this run}
        {--no-telnyx : Skip the Telnyx recordings/conversations}
        {--dry-run : Report what would be deleted without deleting}';

    protected $description = 'Delete Voxra call audio older than the retention period (voxragtm#83)';

    public function handle(): int
    {
        $tenant = $this->option('tenant') ?: null;
        if ($this->option('all') && !$tenant) {
            $this->error('--all needs --tenant');

            return self::INVALID;
        }

        $telnyx = null;
        if (!$this->option('no-telnyx') && config('services.telnyx.api_key')) {
            try {
                $telnyx = app(TelnyxConvaiService::class);
            } catch (\Throwable $e) {
                $this->warn('Telnyx unavailable: ' . $e->getMessage());
            }
        }

        $result = (new VoxraMediaPurgeService($telnyx))->purge([
            'scope' => $this->option('all') ? 'all' : 'age',
            'days' => (int) ($this->option('days') ?: config('services.voxra.recording_retention_days', 10)),
            'tenant_id' => $tenant,
            'limit' => (int) $this->option('limit'),
            'dry_run' => (bool) $this->option('dry-run'),
            'telnyx' => $telnyx !== null,
        ]);

        foreach ($result['log'] as $line) {
            $this->line($line);
        }

        return ($result['counts']['errors'] ?? 0) > 0 ? self::FAILURE : self::SUCCESS;
    }
}
