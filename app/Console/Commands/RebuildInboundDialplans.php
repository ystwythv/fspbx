<?php

namespace App\Console\Commands;

use App\Models\Destinations;
use App\Models\Domain;
use App\Services\DialplanBuilderService;
use Illuminate\Console\Command;

/**
 * Re-render every inbound destination's public-context dialplan from the
 * current phone-number template — e.g. after adding the Voxra pre-answer
 * screening hook (voxragtm#84). Idempotent; safe to run on every deploy.
 */
class RebuildInboundDialplans extends Command
{
    protected $signature = 'voxra:rebuild-inbound-dialplans {--dry-run}';

    protected $description = 'Re-render all inbound destination dialplans from the phone-number template';

    public function handle(DialplanBuilderService $builder): int
    {
        $domains = Domain::pluck('domain_name', 'domain_uuid');
        $n = 0;
        foreach (Destinations::where('destination_type', 'inbound')->get() as $dest) {
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
