<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\ProvisionNumberService;
use Illuminate\Console\Command;

/**
 * Re-write the ring-first bridge (Line+AI, and Pro with ring-first) of every
 * Voxra tenant's DIDs to the current ProvisionNumberService dial string and
 * rebuild those dialplans, without a full re-provision. Rolls out
 * voxragtm#141 (the owner presses 1 once) to tenants provisioned before it.
 *
 * Only the bridge's dial string changes: the mobile, the ring timeout,
 * recording and what follows the bridge (the AI or voicemail) stay as
 * provisioned. Tenants without ring-first are skipped. Idempotent.
 *
 * voxra:rebuild-inbound-dialplans re-renders the dialplan XML from the
 * destination's stored actions, so it can't change the bridge itself.
 *
 *   php artisan voxra:rebuild-ring-first --dry-run
 *   php artisan voxra:rebuild-ring-first
 *   php artisan voxra:rebuild-ring-first --domain=acme.voxra.uk
 */
class VoxraRebuildRingFirst extends Command
{
    protected $signature = 'voxra:rebuild-ring-first
        {--domain= : Only this domain name}
        {--tenant= : Only this voxraweb tenant id}
        {--dry-run : Show what would change}';

    protected $description = 'Re-write Voxra ring-first bridges to the current single-press dial string and rebuild their dialplans';

    public function handle(ProvisionNumberService $numbers): int
    {
        $q = Domain::query()->where('domain_description', 'like', 'voxra-tenant:%');
        if ($tenant = $this->option('tenant')) {
            $q->where('domain_description', 'voxra-tenant:' . $tenant);
        }
        if ($name = $this->option('domain')) {
            $q->where('domain_name', $name);
        }

        $dryRun = (bool) $this->option('dry-run');
        $dids = 0;
        $failed = 0;
        foreach ($q->orderBy('domain_name')->get(['domain_uuid', 'domain_name', 'domain_description']) as $domain) {
            try {
                $rewritten = $numbers->refreshRingFirst($domain, $dryRun);
            } catch (\Throwable $e) {
                $failed++;
                $this->error(sprintf('%-40s failed: %s', $domain->domain_name, $e->getMessage()));
                continue;
            }
            if ($rewritten) {
                $this->line(sprintf('%-40s %s', $domain->domain_name, implode(', ', $rewritten)));
                $dids += count($rewritten);
            }
        }

        $this->info(sprintf(
            '%s %d ring-first DID(s)%s.',
            $dryRun ? 'Would rewrite' : 'Rewrote',
            $dids,
            $failed ? ", {$failed} domain(s) failed" : '',
        ));

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
