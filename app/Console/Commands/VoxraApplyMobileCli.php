<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Services\ProvisionCompleteService;
use App\Services\Voxra\VoxraRoutingState;
use Illuminate\Console\Command;

/**
 * Re-apply the outbound caller-ID of every Voxra eSIM ("mobile") extension
 * from what the PBX already holds, without a full re-provision: the SIM's
 * number from its MSISDN destination row, the tenant's DDI from the
 * extension's emergency caller-ID (provisioning always sets that to the DDI)
 * and the tenant's choice from VoxraRoutingState (default: the mobile).
 *
 * Backfill for the eSIM CLI fix (30 Sept): before it, every eSIM's own
 * outbound calls presented the business number. Idempotent.
 *
 *   php artisan voxra:apply-mobile-cli --dry-run
 *   php artisan voxra:apply-mobile-cli
 *   php artisan voxra:apply-mobile-cli --tenant=<voxraweb tenant id>
 */
class VoxraApplyMobileCli extends Command
{
    protected $signature = 'voxra:apply-mobile-cli
        {--tenant= : Only this voxraweb tenant id}
        {--dry-run : Show what would change}';

    protected $description = 'Re-apply the Voxra eSIM extensions\' outbound caller-ID (mobile number by default)';

    public function handle(ProvisionCompleteService $svc): int
    {
        $tenant = $this->option('tenant');
        $domains = Domain::query()
            ->where('domain_description', $tenant ? '=' : 'like', $tenant ? 'voxra-tenant:' . $tenant : 'voxra-tenant:%')
            ->orderBy('domain_name')
            ->get(['domain_uuid', 'domain_name', 'domain_description']);

        $changed = 0;
        $failed = 0;
        foreach ($domains as $domain) {
            $extension = $svc->findMobileExtension($domain);
            if (! $extension) {
                continue;
            }
            $msisdn = $svc->currentMsisdn($domain);
            if ($msisdn === null) {
                $this->line(sprintf('%-40s skip: no eSIM number on the PBX', $domain->domain_name));
                continue;
            }

            $did = self::plus($extension->getRawOriginal('emergency_caller_id_number'));
            $choice = ProvisionCompleteService::resolveOutboundCli(null, VoxraRoutingState::load($domain->domain_uuid));
            $current = self::plus($extension->getRawOriginal('outbound_caller_id_number'));
            $target = self::plus(ProvisionCompleteService::outboundCliDigits(
                $choice,
                ProvisionCompleteService::e164Digits($did),
                ProvisionCompleteService::e164Digits($msisdn),
            ));

            $this->line(sprintf(
                '%-40s ext %s  %s: %s -> %s%s',
                $domain->domain_name,
                $extension->extension,
                $choice,
                $current ?? '(none)',
                $target ?? '(none)',
                $current === $target ? '  (unchanged)' : '',
            ));
            if ($current === $target || $this->option('dry-run')) {
                continue;
            }

            try {
                $name = $extension->outbound_caller_id_name ?: ($extension->effective_caller_id_name ?: 'Voxra');
                $svc->applyCallerId($domain, $did, $name, $msisdn, $choice);
                $changed++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error('  failed: ' . $e->getMessage());
            }
        }

        $this->info(sprintf('%s: %d changed, %d failed', $this->option('dry-run') ? 'Dry run' : 'Done', $changed, $failed));

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private static function plus(?string $digits): ?string
    {
        $digits = ltrim((string) $digits, '+');

        return $digits === '' ? null : '+' . $digits;
    }
}
