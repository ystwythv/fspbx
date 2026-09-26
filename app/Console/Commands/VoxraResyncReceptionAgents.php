<?php

namespace App\Console\Commands;

use App\Http\Controllers\Internal\ProvisionTenantController;
use App\Http\Controllers\ReceptionAgentController;
use App\Models\AiAgent;
use App\Models\Domain;
use App\Services\Convai\ConvaiProviderRegistry;
use App\Services\TelnyxConvaiService;
use Illuminate\Console\Command;

/**
 * Push the current Voxra receptionist prompt + tool surface to every Voxra
 * tenant's Telnyx assistant, without a full re-provision (no routing, number
 * or greeting changes), and rebuild each inbound (9250) dialplan from the
 * current template. Run after a deploy that changes
 * ProvisionTenantController::RECEPTION_SYSTEM_PROMPT or the reception tools
 * (e.g. voxragtm#122 alert_owner gating the owner transfer).
 *
 *   php artisan voxra:resync-reception-agents --dry-run
 *   php artisan voxra:resync-reception-agents
 *   php artisan voxra:resync-reception-agents --tenant=<voxraweb tenant id>
 */
class VoxraResyncReceptionAgents extends Command
{
    protected $signature = 'voxra:resync-reception-agents
        {--tenant= : Only this voxraweb tenant id}
        {--dry-run : List the assistants that would be updated}';

    protected $description = 'Re-sync Voxra reception assistants (prompt + tools) to Telnyx';

    public function handle(ConvaiProviderRegistry $registry): int
    {
        $tenant = $this->option('tenant');
        $domains = Domain::query()
            ->where('domain_description', $tenant ? '=' : 'like', $tenant ? 'voxra-tenant:' . $tenant : 'voxra-tenant:%')
            ->get(['domain_uuid', 'domain_name', 'domain_description']);

        $ok = 0;
        $failed = 0;
        foreach ($domains as $domain) {
            $agent = AiAgent::reception()->forDomain($domain->domain_uuid)->first();
            if (!$agent || $agent->provider !== AiAgent::PROVIDER_TELNYX || !$agent->telnyx_assistant_id) {
                continue;
            }
            $this->line(sprintf('%s  %s  %s', $domain->domain_name, $agent->telnyx_assistant_id, $domain->domain_description));
            if ($this->option('dry-run')) {
                continue;
            }

            try {
                $agent->system_prompt = ProvisionTenantController::RECEPTION_SYSTEM_PROMPT;
                $agent->save();

                $provider = $registry->resolve(AiAgent::PROVIDER_TELNYX);
                $provider->updateAgent($agent, [
                    'agent_name'    => $agent->agent_name,
                    'system_prompt' => $agent->system_prompt,
                    'first_message' => $agent->first_message,
                    'voice_id'      => $agent->voice_id,
                    'model'         => $agent->model,
                    'language'      => $agent->language ?? 'en',
                ]);
                $provider->syncReceptionAgentTools($agent);
                // Prompt-variable defaults (e.g. urgent_definition) for a slow
                // dynamic-variables webhook; recording setting left as is.
                app(TelnyxConvaiService::class)->applyVoxraCallPolicy($agent->telnyx_assistant_id, null);
                // Inbound dialplan from the current template (e.g. answer on
                // the assistant's answer, not before the bridge).
                app(ReceptionAgentController::class)->regenerateInboundReceptionDialPlan($agent);
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $this->error('  failed: ' . $e->getMessage());
            }
        }

        $this->info($this->option('dry-run') ? 'Dry run — nothing changed.' : "Re-synced {$ok}, failed {$failed}.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
