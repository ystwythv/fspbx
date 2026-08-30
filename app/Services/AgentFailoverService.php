<?php

namespace App\Services;

use App\Models\AiAgent;
use App\Models\Domain;
use App\Models\Extensions;
use App\Models\FusionCache;

/**
 * Point an extension's no-answer / busy / unregistered failover at the
 * domain's reception agent, so a missed call on an FMC-registered handset
 * lands on the AI agent instead of voicemail (voxragtm#23). Shared by the
 * `reception-agent:route-extension` artisan command and Voxra Complete
 * provisioning (ProvisionCompleteService).
 */
class AgentFailoverService
{
    /** The domain's enabled reception agent with a dialable extension, or null. */
    public function enabledAgent(Domain $domain): ?AiAgent
    {
        $agent = AiAgent::reception()
            ->forDomain($domain->domain_uuid)
            ->where('agent_enabled', 'true')
            ->first();

        return ($agent && $agent->agent_extension) ? $agent : null;
    }

    /** Set the three forwards on the extension (not saved). */
    public function applyTo(Extensions $extension, AiAgent $agent): void
    {
        $target = (string) $agent->agent_extension;

        $extension->forward_no_answer_enabled = 'true';
        $extension->forward_no_answer_destination = $target;
        $extension->forward_busy_enabled = 'true';
        $extension->forward_busy_destination = $target;
        $extension->forward_user_not_registered_enabled = 'true';
        $extension->forward_user_not_registered_destination = $target;
    }

    /** Clear the three forwards on the extension (not saved). */
    public function clearOn(Extensions $extension): void
    {
        $extension->forward_no_answer_enabled = 'false';
        $extension->forward_busy_enabled = 'false';
        $extension->forward_user_not_registered_enabled = 'false';
    }

    public function clearCache(Extensions $extension, string $domainName): void
    {
        $context = $extension->user_context ?: $domainName;
        FusionCache::clear("directory:{$extension->extension}@{$context}");
        FusionCache::clear('dialplan.' . $domainName);
    }
}
