<?php

namespace App\Console\Commands;

use App\Models\Destinations;
use App\Models\Domain;
use App\Services\DialplanBuilderService;
use Illuminate\Console\Command;

/**
 * Re-render the Voxra tenants' inbound destination dialplans from the current
 * phone-number template — e.g. after adding the pre-answer screening hook
 * (voxragtm#84). Other customers' domains on this PBX only with --all.
 * Idempotent.
 */
class RebuildInboundDialplans extends Command
{
    protected $signature = 'voxra:rebuild-inbound-dialplans {--dry-run} {--all : every domain, not just Voxra tenants}';

    protected $description = 'Re-render all inbound destination dialplans from the phone-number template';

    public function handle(DialplanBuilderService $builder): int
    {
        $q = Domain::query();
        if (! $this->option('all')) {
            $q->where('domain_description', 'like', 'voxra-tenant:%');
        }
        $domains = $q->pluck('domain_name', 'domain_uuid');
        $n = 0;
        $dests = Destinations::where('destination_type', 'inbound')
            ->whereIn('domain_uuid', $domains->keys())
            ->get();
        foreach ($dests as $dest) {
            $domainName = $domains[$dest->domain_uuid] ?? null;
            if (! $domainName) {
                continue;
            }
            $this->line("{$dest->destination_number} ({$domainName})");
            if (! $this->option('dry-run')) {
                $builder->buildDialplanForPhoneNumber($dest, $domainName);
            }
            $n++;
        }
        $this->info(($this->option('dry-run') ? 'Would rebuild ' : 'Rebuilt ') . "{$n} inbound dialplan(s).");

        return self::SUCCESS;
    }
}
