<?php

namespace App\Services\Voxra;

use App\Models\AiAgent;
use App\Models\AiAgentKbDocument;
use App\Models\Destinations;
use App\Models\Dialplans;
use App\Models\Domain;
use App\Models\Extensions;
use App\Services\Convai\ConvaiProviderRegistry;
use App\Services\ElevenLabsConvaiService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Delete a closed Voxra customer's PBX domain (voxragtm#173 follow-up, Peter
 * 10 Nov 2026). voxraweb calls it once the account's numbers have been
 * released (7 days after a staff close, 90 after other closes), so nothing
 * is left behind for ops:
 *   1. the tenant's PBX + Telnyx call media (VoxraMediaPurgeService scope=all,
 *      which finds the Telnyx media through the domain's assistants, so it
 *      runs first);
 *   2. each AI agent as the admin's delete does it (AiAgentController::bulkDelete):
 *      knowledge-base docs, the Telnyx assistant / UAC connection / attach
 *      extension (or ElevenLabs agent), its dialplan, the row;
 *   3. the domain (DomainObserver cascades extensions, voicemail, dialplans,
 *      users..., removes its switch files and reloads the XML).
 *
 * Only domains described "voxra-tenant:<tenant_id>" are touched. A domain that
 * still holds a phone number (a parked ported-in number, an eSIM's inbound
 * MSISDN) is refused: the number would lose its routing. A tenant with no
 * domain answers ok with nothing deleted, so a retry is harmless.
 */
class VoxraTenantDeleteService
{
    public function __construct(
        private ?VoxraMediaPurgeService $purger = null,
        private ?ConvaiProviderRegistry $registry = null,
    ) {
    }

    /**
     * @return array{ok: bool, status: int, error?: string, numbers?: array, domains: array, media?: array}
     */
    public function delete(string $tenantId, bool $dryRun = false): array
    {
        $domains = Domain::where('domain_description', 'voxra-tenant:' . $tenantId)->get();
        if ($domains->isEmpty()) {
            return ['ok' => true, 'status' => 200, 'domains' => []];
        }

        $numbers = Destinations::whereIn('domain_uuid', $domains->pluck('domain_uuid'))
            ->pluck('destination_number')->map(fn ($n) => (string) $n)->values()->all();
        $summary = $domains->map(fn (Domain $d) => [
            'domain_uuid' => $d->domain_uuid,
            'domain_name' => $d->domain_name,
            'extensions' => Extensions::where('domain_uuid', $d->domain_uuid)->count(),
            'ai_agents' => AiAgent::where('domain_uuid', $d->domain_uuid)->count(),
        ])->values()->all();
        if ($numbers !== []) {
            return ['ok' => false, 'status' => 409, 'error' => 'numbers_remain', 'numbers' => $numbers, 'domains' => $summary];
        }

        $media = null;
        if ($this->purger) {
            $media = $this->purger->purge([
                'scope' => 'all',
                'tenant_id' => $tenantId,
                'dry_run' => $dryRun,
                'limit' => 5000,
                'telnyx' => true,
            ])['counts'] ?? [];
            if (($media['errors'] ?? 0) > 0) {
                // Keep the domain: the purge finds Telnyx media through it. Retried next run.
                return ['ok' => false, 'status' => 502, 'error' => 'media_purge_failed', 'media' => $media, 'domains' => $summary];
            }
        }
        if ($dryRun) {
            return ['ok' => true, 'status' => 200, 'dry_run' => true, 'media' => $media, 'domains' => $summary];
        }

        $registry = $this->registry ?? app(ConvaiProviderRegistry::class);
        foreach ($domains as $domain) {
            foreach (AiAgent::where('domain_uuid', $domain->domain_uuid)->get() as $agent) {
                $this->deleteAgent($agent, $registry);
            }
            DB::transaction(fn () => $domain->delete());
            logger()->info("voxra delete-tenant: tenant={$tenantId} domain={$domain->domain_name} ({$domain->domain_uuid}) deleted");
        }

        return ['ok' => true, 'status' => 200, 'media' => $media, 'domains' => $summary];
    }

    private function deleteAgent(AiAgent $agent, ConvaiProviderRegistry $registry): void
    {
        foreach (AiAgentKbDocument::where('ai_agent_uuid', $agent->ai_agent_uuid)->get() as $doc) {
            if ($doc->elevenlabs_documentation_id) {
                try {
                    app(ElevenLabsConvaiService::class)->deleteKbDocument($doc->elevenlabs_documentation_id);
                } catch (\Throwable $e) {
                    logger()->warning('voxra delete-tenant: ElevenLabs KB cleanup: ' . $e->getMessage());
                }
            }
            if ($doc->file_path && Storage::disk('local')->exists($doc->file_path)) {
                Storage::disk('local')->delete($doc->file_path);
            }
            $doc->delete();
        }
        try {
            $registry->resolve($agent->provider)->deleteAgent($agent);
        } catch (\Throwable $e) {
            logger()->warning("voxra delete-tenant: provider cleanup for agent {$agent->ai_agent_uuid}: " . $e->getMessage());
        }
        if ($agent->dialplan_uuid) {
            Dialplans::where('dialplan_uuid', $agent->dialplan_uuid)->delete();
        }
        $agent->delete();
    }
}
