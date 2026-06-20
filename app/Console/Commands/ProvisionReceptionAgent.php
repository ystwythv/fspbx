<?php

namespace App\Console\Commands;

use App\Http\Controllers\ReceptionAgentController;
use App\Models\Domain;
use Illuminate\Console\Command;

/**
 * Headless provision-or-update of the per-domain reception agent.
 *
 * Example (clone the 9251 Telnyx assistant config as a reception agent):
 *   php artisan reception-agent:provision iqmobile.uk --provider=telnyx \
 *     --name="IQ In-Call Assistant" \
 *     --voice="Telnyx.Ultra.62ae83ad-4f6a-430b-af41-a9bede9286ca" \
 *     --model="moonshotai/Kimi-K2.5" \
 *     --prompt="You are an executive assistant who has been pulled into a live conference call..."
 */
class ProvisionReceptionAgent extends Command
{
    protected $signature = 'reception-agent:provision
        {domain : domain_name (e.g. iqmobile.uk) or domain_uuid}
        {--provider=telnyx}
        {--name=Reception Agent}
        {--prompt= : system prompt (defaults to the controller default)}
        {--first-message=}
        {--voice= : provider voice id}
        {--model=}
        {--language=en}
        {--feature-code= : feature code, default *9 (kept out of the signature default because Laravel treats =* as an array option)}
        {--enabled=true}';

    protected $description = 'Provision-or-update the per-domain reception agent (headless: no session/permission gate).';

    public function handle(ReceptionAgentController $controller): int
    {
        $domainArg = (string) $this->argument('domain');
        $domain = \Illuminate\Support\Str::isUuid($domainArg)
            ? Domain::where('domain_uuid', $domainArg)->first()
            : Domain::where('domain_name', $domainArg)->first();

        if (!$domain) {
            $this->error("Domain not found: {$domainArg}");

            return self::FAILURE;
        }

        $provider = (string) $this->option('provider');

        $inputs = [
            'agent_name'    => (string) $this->option('name'),
            'feature_code'  => (string) ($this->option('feature-code') ?: '*9'),
            'provider'      => $provider,
            'system_prompt' => $this->option('prompt') ?: null,
            'first_message' => $this->option('first-message') ?: null,
            'language'      => (string) $this->option('language'),
            'agent_enabled' => (string) $this->option('enabled'),
            'tools_enabled' => [],
        ];
        if ($model = $this->option('model')) {
            $inputs['model'] = $model;
        }
        if ($voice = $this->option('voice')) {
            $inputs['voice_id'] = $voice;
            if ($provider === 'telnyx') {
                $inputs['telnyx_voice_id'] = $voice;
            }
        }

        try {
            $agent = $controller->upsertReceptionAgent($domain->domain_uuid, $inputs);
        } catch (\Throwable $e) {
            $this->error('Provision failed: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Reception agent ready: domain=%s ext=%s provider=%s assistant=%s feature=%s enabled=%s',
            $domain->domain_name,
            $agent->agent_extension,
            $agent->provider,
            $agent->telnyx_assistant_id ?: ($agent->elevenlabs_agent_id ?: '-'),
            $agent->feature_code,
            $agent->agent_enabled,
        ));

        return self::SUCCESS;
    }
}
