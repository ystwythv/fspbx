<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ReceptionAgentController;
use App\Models\Domain;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Provision a Voxra tenant's PBX side on activation (voxragtm#42): create a
 * FusionPBX domain (the DomainObserver bootstraps dialplans + FS dirs) and its
 * reception agent, and return the domain_uuid that voxraweb maps the tenant to.
 *
 * Called by voxraweb, authed by VerifyVoxraInternalSignature (HMAC over the raw
 * body). Idempotent per tenant (keyed on domain_description = "voxra-tenant:<id>").
 *
 * Phone-number ordering is intentionally NOT done here — it spends money
 * (TelnyxNumberService::createOrder) and is a gated follow-up (voxragtm#23).
 */
class ProvisionTenantController extends Controller
{
    // Alistair — British male, Telnyx Ultra (voxra voice standard).
    private const UK_VOICE = 'Telnyx.Ultra.c8f7835e-28a3-4f0c-80d7-c1302ac62aae';

    public function provision(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id'     => 'required|string|max:64',
            'business_name' => 'required|string|max:120',
        ]);

        $tenantId = $data['tenant_id'];
        $businessName = trim($data['business_name']) ?: 'Voxra';
        $tag = 'voxra-tenant:' . $tenantId;

        // Idempotency: reuse the domain already provisioned for this tenant.
        $domain = Domain::where('domain_description', $tag)->first();
        if (!$domain) {
            $domain = new Domain();
            $domain->domain_uuid = (string) Str::uuid();
            $domain->domain_name = $this->uniqueDomainName($businessName);
            $domain->domain_enabled = 'true';
            $domain->domain_description = $tag;
            $domain->save(); // DomainObserver bootstraps stock dialplans + FS dirs
        }

        // Idempotent upsert of the reception agent on the domain.
        $agent = app(ReceptionAgentController::class)->upsertReceptionAgent(
            $domain->domain_uuid,
            [
                'agent_name'      => $businessName . ' Reception',
                'provider'        => 'telnyx',
                'model'           => 'moonshotai/Kimi-K2.6',
                'telnyx_voice_id' => self::UK_VOICE,
                'feature_code'    => '*9',
                'agent_enabled'   => 'true',
            ]
        );

        // Auto-order + route a DID (voxragtm#23) — gated + spend-capped; returns
        // null unless VOXRA_PROVISION_ORDER_NUMBER is enabled. Best-effort: a
        // number failure must not fail provisioning (domain + agent are done).
        $number = null;
        try {
            $number = app(\App\Services\ProvisionNumberService::class)->orderAndRoute($domain, $agent);
        } catch (\Throwable $e) {
            logger('Voxra auto-number failed for ' . $domain->domain_name . ': ' . $e->getMessage());
        }

        return response()->json([
            'ok'                  => true,
            'domain_uuid'         => $domain->domain_uuid,
            'domain_name'         => $domain->domain_name,
            'agent_extension'     => $agent->agent_extension,
            'feature_code'        => $agent->feature_code,
            'telnyx_assistant_id' => $agent->telnyx_assistant_id,
            'number'              => $number,
        ]);
    }

    private function uniqueDomainName(string $businessName): string
    {
        $slug = Str::slug($businessName);
        if ($slug === '') {
            $slug = 'voxra';
        }
        $name = $slug . '.voxra.uk';
        $i = 1;
        while (Domain::where('domain_name', $name)->exists()) {
            $name = $slug . '-' . (++$i) . '.voxra.uk';
        }

        return $name;
    }
}
