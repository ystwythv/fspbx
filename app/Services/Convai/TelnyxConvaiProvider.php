<?php

namespace App\Services\Convai;

use App\Models\AiAgent;
use App\Models\Domain;
use App\Models\Extensions;
use App\Models\FusionCache;
use App\Services\TelnyxConvaiService;
use RuntimeException;

/**
 * Telnyx AI assistant provider.
 *
 * Call path: SIP attach. A UAC connection makes Telnyx register into the
 * PBX as an extension in a dedicated attach domain (the domain name must
 * match the proxy host Telnyx is given, because Telnyx derives the AOR
 * domain from the proxy). The tenant dialplan bridges to that registered
 * extension and falls back to the assistant's public SIP subdomain
 * (assistant-<id>.sip.telnyx.com, reachable without registration) when the
 * registration is down.
 *
 * When TELNYX_ATTACH_DOMAIN / TELNYX_ATTACH_PROXY are not configured the
 * provider skips SIP attach and the dialplan uses the subdomain bridge only.
 */
class TelnyxConvaiProvider implements ConvaiProviderInterface
{
    public function __construct(private TelnyxConvaiService $service)
    {
    }

    public function name(): string
    {
        return 'telnyx';
    }

    public function provisionAgent(array $inputs): array
    {
        $assistant = $this->service->createAssistant(
            $inputs['agent_name'],
            $inputs['system_prompt'] ?? null,
            $inputs['first_message'] ?? null,
            $inputs['voice_id'] ?? null,
            $inputs['model'] ?? null,
            $inputs['language'] ?? 'en',
        );

        $assistantId = $assistant['id'] ?? null;
        if (!$assistantId) {
            throw new RuntimeException('Telnyx did not return an assistant id.');
        }

        $attributes = [
            'telnyx_assistant_id' => $assistantId,
            'model' => $inputs['model'] ?? null,
        ];

        // SIP attach is best-effort: the subdomain bridge in the dialplan
        // works without it, so a failure here must not lose the assistant.
        try {
            $attributes = array_merge($attributes, $this->provisionSipAttach($assistantId, $inputs['agent_name']));
        } catch (\Exception $e) {
            logger('Telnyx SIP attach setup warning: ' . $e->getMessage());
        }

        return $attributes;
    }

    public function updateAgent(AiAgent $agent, array $inputs): void
    {
        if (!$agent->telnyx_assistant_id) {
            return;
        }

        try {
            $this->service->updateAssistant(
                $agent->telnyx_assistant_id,
                $inputs['agent_name'],
                $inputs['system_prompt'] ?? null,
                $inputs['first_message'] ?? null,
                $inputs['voice_id'] ?? null,
                $inputs['model'] ?? $agent->model,
                $inputs['language'] ?? 'en',
            );
        } catch (\Exception $e) {
            logger('Telnyx update assistant warning: ' . $e->getMessage());
        }
    }

    public function deleteAgent(AiAgent $agent): void
    {
        if ($agent->telnyx_uac_connection_id) {
            $this->service->deleteUacConnection($agent->telnyx_uac_connection_id);
        }
        if ($agent->telnyx_assistant_id) {
            $this->service->deleteAssistant($agent->telnyx_assistant_id);
        }
        if ($agent->telnyx_attach_extension_uuid) {
            $extension = Extensions::where('extension_uuid', $agent->telnyx_attach_extension_uuid)->first();
            if ($extension) {
                FusionCache::clear('directory:' . $extension->extension . '@' . $extension->user_context);
                $extension->delete();
            }
        }
    }

    public function dialplanView(): string
    {
        return 'layouts.xml.telnyx-ai-agent-dial-plan-template';
    }

    public function dialplanData(AiAgent $agent): array
    {
        return [
            'attach_domain' => (string) config('services.telnyx.attach_domain', ''),
        ];
    }

    public function summonEndpoint(AiAgent $agent): string
    {
        if (!$agent->telnyx_assistant_id) {
            throw new \RuntimeException('Telnyx agent not provisioned (missing assistant id).');
        }

        // Public assistant subdomain — always reachable, no registration needed.
        $subdomain = sprintf('sofia/external/sip:agent@%s.sip.telnyx.com', $agent->telnyx_assistant_id);

        // Prefer the registered SIP-attach extension (mirrors the dialplan
        // template) and fail over to the subdomain if it's not registered.
        $attachDomain = (string) config('services.telnyx.attach_domain', '');
        if ($agent->telnyx_attach_extension && $attachDomain !== '') {
            return sprintf('user/%s@%s|%s', $agent->telnyx_attach_extension, $attachDomain, $subdomain);
        }

        return $subdomain;
    }

    public function provisionReceptionAgent(array $inputs): array
    {
        // A reception agent uses the same call path as a direct Telnyx agent:
        // create the assistant + SIP attach (no inbound phone number).
        return $this->provisionAgent($inputs);
    }

    public function syncReceptionAgentTools(AiAgent $agent): void
    {
        $this->service->syncReceptionAgentTools($agent);
    }

    /**
     * Create the attach extension + UAC connection so Telnyx registers in.
     *
     * @return array attributes to persist on the AiAgent row
     */
    private function provisionSipAttach(string $assistantId, string $agentName): array
    {
        $attachDomainName = (string) config('services.telnyx.attach_domain', '');
        $attachProxy = (string) config('services.telnyx.attach_proxy', '');

        if ($attachDomainName === '' || $attachProxy === '') {
            logger('Telnyx SIP attach skipped: TELNYX_ATTACH_DOMAIN / TELNYX_ATTACH_PROXY not configured.');
            return [];
        }

        $attachDomain = $this->findOrCreateAttachDomain($attachDomainName);
        $attachExtension = $this->generateAttachExtension($attachDomain->domain_uuid);
        $password = bin2hex(random_bytes(16));

        $extension = new Extensions();
        $extension->fill([
            'domain_uuid' => $attachDomain->domain_uuid,
            'extension' => $attachExtension,
            'password' => $password,
            'user_context' => $attachDomainName,
            'enabled' => 'true',
            'effective_caller_id_name' => 'Telnyx Agent: ' . $agentName,
            'effective_caller_id_number' => $attachExtension,
            'call_timeout' => 30,
            'description' => 'Telnyx SIP attach for assistant ' . $assistantId,
        ]);
        $extension->save();

        FusionCache::clear('directory:' . $attachExtension . '@' . $attachDomainName);

        try {
            $connection = $this->service->createUacConnection(
                'voxra-agent-' . $attachExtension,
                $attachExtension,
                $password,
                $attachProxy,
                // assistant ids already carry the "assistant-" prefix
                'agent@' . $assistantId . '.sip.telnyx.com',
            );
        } catch (\Exception $e) {
            // Don't leave an orphaned credentialed extension behind.
            $extension->delete();
            FusionCache::clear('directory:' . $attachExtension . '@' . $attachDomainName);
            throw $e;
        }

        return [
            'telnyx_uac_connection_id' => $connection['data']['id'] ?? null,
            'telnyx_attach_extension_uuid' => $extension->extension_uuid,
            'telnyx_attach_extension' => $attachExtension,
        ];
    }

    private function findOrCreateAttachDomain(string $domainName): Domain
    {
        $domain = Domain::where('domain_name', $domainName)->first();

        if (!$domain) {
            $domain = new Domain();
            $domain->domain_name = $domainName;
            $domain->domain_enabled = 'true';
            $domain->domain_description = 'Telnyx SIP attach registrations (system)';
            $domain->save();
        }

        return $domain;
    }

    /**
     * Unique SIP username in the shared attach domain. Telnyx rejects
     * digit-only usernames ("Please use a non-digit value in the first 5
     * characters of your user_name") — and its registration prober silently
     * never registers them — so the username is alpha-prefixed.
     */
    private function generateAttachExtension(string $attachDomainUuid): string
    {
        for ($i = 0; $i < 25; $i++) {
            $candidate = 'agt' . random_int(80000000, 89999999);
            $exists = Extensions::where('domain_uuid', $attachDomainUuid)
                ->where('extension', $candidate)
                ->exists();
            if (!$exists) {
                return $candidate;
            }
        }

        throw new RuntimeException('Unable to allocate a unique Telnyx attach extension.');
    }
}
