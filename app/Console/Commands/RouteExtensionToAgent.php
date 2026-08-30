<?php

namespace App\Console\Commands;

use App\Models\Domain;
use App\Models\Extensions;
use App\Services\AgentFailoverService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Point an FMC extension's no-answer / busy / unregistered failover at the
 * domain's reception agent, so a missed call goes to the AI agent instead of
 * voicemail (voxragtm#23). Idempotent; `--disable` clears the three forwards.
 *
 *   php artisan reception-agent:route-extension acme.voxra.uk 1001
 *   php artisan reception-agent:route-extension acme.voxra.uk 1001 --disable
 */
class RouteExtensionToAgent extends Command
{
    protected $signature = 'reception-agent:route-extension
        {domain : domain_name or domain_uuid}
        {extension : the FMC extension number}
        {--disable : clear the agent failover instead of setting it}';

    protected $description = "Route an extension's no-answer/busy/unregistered failover to the domain reception agent (voxragtm#23).";

    public function handle(): int
    {
        $domainArg = (string) $this->argument('domain');
        $domain = Str::isUuid($domainArg)
            ? Domain::where('domain_uuid', $domainArg)->first()
            : Domain::where('domain_name', $domainArg)->first();

        if (!$domain) {
            $this->error("Domain not found: {$domainArg}");
            return self::FAILURE;
        }

        $extension = Extensions::where('domain_uuid', $domain->domain_uuid)
            ->where('extension', (string) $this->argument('extension'))
            ->first();

        if (!$extension) {
            $this->error("Extension {$this->argument('extension')} not found in {$domain->domain_name}");
            return self::FAILURE;
        }

        $disable = (bool) $this->option('disable');
        $failover = app(AgentFailoverService::class);

        if ($disable) {
            $failover->clearOn($extension);
            $extension->save();
            $failover->clearCache($extension, $domain->domain_name);
            $this->info("Cleared agent failover on extension {$extension->extension}.");
            return self::SUCCESS;
        }

        $agent = $failover->enabledAgent($domain);

        if (!$agent) {
            $this->error("No enabled reception agent for {$domain->domain_name} — provision one first (reception-agent:provision).");
            return self::FAILURE;
        }

        $failover->applyTo($extension, $agent);
        $extension->save();

        $failover->clearCache($extension, $domain->domain_name);

        $this->info("Extension {$extension->extension}: no-answer/busy/unregistered → reception agent {$agent->agent_extension}.");
        return self::SUCCESS;
    }
}
