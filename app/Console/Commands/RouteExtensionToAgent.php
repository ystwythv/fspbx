<?php

namespace App\Console\Commands;

use App\Models\AiAgent;
use App\Models\Domain;
use App\Models\Extensions;
use App\Models\FusionCache;
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

        if ($disable) {
            $extension->forward_no_answer_enabled = 'false';
            $extension->forward_busy_enabled = 'false';
            $extension->forward_user_not_registered_enabled = 'false';
            $extension->save();
            $this->clearCache($extension, $domain->domain_name);
            $this->info("Cleared agent failover on extension {$extension->extension}.");
            return self::SUCCESS;
        }

        $agent = AiAgent::reception()
            ->forDomain($domain->domain_uuid)
            ->where('agent_enabled', 'true')
            ->first();

        if (!$agent || !$agent->agent_extension) {
            $this->error("No enabled reception agent for {$domain->domain_name} — provision one first (reception-agent:provision).");
            return self::FAILURE;
        }

        $target = (string) $agent->agent_extension;

        $extension->forward_no_answer_enabled = 'true';
        $extension->forward_no_answer_destination = $target;
        $extension->forward_busy_enabled = 'true';
        $extension->forward_busy_destination = $target;
        $extension->forward_user_not_registered_enabled = 'true';
        $extension->forward_user_not_registered_destination = $target;
        $extension->save();

        $this->clearCache($extension, $domain->domain_name);

        $this->info("Extension {$extension->extension}: no-answer/busy/unregistered → reception agent {$target}.");
        return self::SUCCESS;
    }

    private function clearCache(Extensions $extension, string $domainName): void
    {
        $context = $extension->user_context ?: $domainName;
        FusionCache::clear("directory:{$extension->extension}@{$context}");
        FusionCache::clear('dialplan.' . $domainName);
    }
}
