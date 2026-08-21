<?php

namespace App\Services;

use App\Models\Dialplans;
use App\Models\Domain;
use App\Models\FusionCache;
use App\Models\Gateways;
use Illuminate\Support\Str;

/**
 * Per-tenant PSTN outbound route (voxragtm#25/#110).
 *
 * Voxra tenant domains are bootstrapped with the stock dialplans only, which
 * carry no outbound PSTN route (just the emergency 911/933 gateway entries).
 * But both call paths that ring the owner's mobile — ring-mobile-first
 * (voxragtm#23, PR #96) and Line mode follow-me (voxragtm#25, PR #99) —
 * bridge `loopback/+44…` back into the tenant's own domain context and rely
 * on a route there to carry the leg out to the PSTN. Without one the loopback
 * B-leg falls off the end of the dialplan, the originate fails with
 * NORMAL_CLEARING and the caller gets dead air (live failure: call
 * 106d25ae-d90a-42a9-bf35-f4935ae470af, 2026-08-21).
 *
 * This provisions a "Voxra Outbound" dialplan in the tenant domain, modelled
 * on the hand-built "Magrathea Outbound" route in iqmobile.uk: UK national
 * (0…) and UK E.164 (+44…/44…) only — no international. The destination is
 * passed to the gateway unchanged, exactly as every deployed Magrathea route
 * does: the gateway accepts both national and E.164 ('+' always produces
 * clean E.164 — see OutboundCallerIdFixer). Idempotent on re-provision.
 */
class ProvisionOutboundRouteService
{
    /** Stable marker used to find/update the route on re-provision. */
    public const DIALPLAN_DESCRIPTION = 'Voxra outbound route (auto-provisioned)';

    /** Owning app marker (this provisioning code), not a template app_uuid. */
    public const APP_UUID = 'e7a44c1f-3b6d-4c92-9f0a-5d21c6b7a8e4';

    /** Same slot as the reference Magrathea Outbound routes. */
    public const DIALPLAN_ORDER = 100;

    /** UK national format: 0… (e.g. 07944779309, 01225…). */
    public const UK_NATIONAL_PATTERN = '^(0\d+)$';

    /** UK E.164 / country-prefixed: +44… or 44… (follow-me and ring-first
     *  destinations are stored in E.164, so this is the hot path). */
    public const UK_E164_PATTERN = '^(\+?44\d+)$';

    /**
     * Idempotently provision the tenant's outbound route. No-op (logged) when
     * the configured gateway cannot be resolved — provisioning must not fail,
     * but the tenant's mobile legs will keep failing until it is fixed.
     */
    public function ensureOutboundRoute(Domain $domain): ?Dialplans
    {
        $gateway = $this->resolveGateway();
        if (! $gateway) {
            logger()->warning('Voxra outbound route skipped for ' . $domain->domain_name
                . ': gateway "' . $this->configuredGateway() . '" not found or disabled'
                . ' (set VOXRA_OUTBOUND_GATEWAY to a v_gateways name or uuid)');

            return null;
        }

        $existing = Dialplans::where('domain_uuid', $domain->domain_uuid)
            ->where('dialplan_description', self::DIALPLAN_DESCRIPTION)
            ->first();

        $dialplanUuid = $existing?->dialplan_uuid ?? (string) Str::uuid();

        $dialPlan = $existing ?? new Dialplans();
        if (! $existing) {
            $dialPlan->dialplan_uuid        = $dialplanUuid;
            $dialPlan->app_uuid             = self::APP_UUID;
            $dialPlan->domain_uuid          = $domain->domain_uuid;
            $dialPlan->dialplan_order       = self::DIALPLAN_ORDER;
            $dialPlan->dialplan_description = self::DIALPLAN_DESCRIPTION;
            $dialPlan->insert_date          = date('Y-m-d H:i:s');
        } else {
            $dialPlan->update_date          = date('Y-m-d H:i:s');
        }

        $dialPlan->dialplan_name     = 'Voxra Outbound';
        $dialPlan->dialplan_context  = $domain->domain_name;
        $dialPlan->dialplan_continue = 'false';
        $dialPlan->dialplan_enabled  = 'true';
        $dialPlan->dialplan_xml      = self::buildXml($dialplanUuid, $gateway->gateway_uuid);
        $dialPlan->save();

        FusionCache::clear('dialplan.' . $domain->domain_name);

        return $dialPlan;
    }

    /**
     * The route XML. Multi-condition semantics mirror the deployed
     * Magrathea_Outbound (tekels.voxra.uk): a 0… number passes the first
     * condition (bridge queued, break="never" so evaluation continues, the
     * second condition's failure doesn't discard it); a +44…/44… number fails
     * the first and passes the second. Anything else matches neither, so the
     * extension doesn't match and dialplan parsing continues (continue="false"
     * only applies once matched) — internal extensions, feature codes and
     * international numbers are untouched.
     */
    public static function buildXml(string $dialplanUuid, string $gatewayUuid): string
    {
        $bridge = 'sofia/gateway/' . $gatewayUuid . '/${destination_number}';

        return implode("\n", [
            '<extension name="Voxra Outbound" continue="false" uuid="' . $dialplanUuid . '">',
            '  <condition field="destination_number" expression="' . self::UK_NATIONAL_PATTERN . '" break="never">',
            '    <action application="bridge" data="' . $bridge . '"/>',
            '  </condition>',
            '  <condition field="destination_number" expression="' . self::UK_E164_PATTERN . '">',
            '    <action application="bridge" data="' . $bridge . '"/>',
            '  </condition>',
            '</extension>',
        ]);
    }

    /**
     * Resolve the outbound gateway from `v_gateways` by the configured name or
     * uuid (VOXRA_OUTBOUND_GATEWAY). Defaults to the "magrathea" gateway that
     * every reference route on this box bridges to — looked up, never
     * hardcoded, so a rebuilt gateway row keeps working.
     */
    public function resolveGateway(): ?Gateways
    {
        $key = $this->configuredGateway();
        if ($key === '') {
            return null;
        }

        $query = Gateways::where('enabled', 'true');

        if (Str::isUuid($key)) {
            $query->where('gateway_uuid', $key);
        } else {
            $query->whereRaw('LOWER(gateway) = ?', [strtolower($key)]);
        }

        return $query->orderBy('gateway_uuid')->first();
    }

    private function configuredGateway(): string
    {
        return trim((string) config('services.voxra.outbound_gateway', 'magrathea'));
    }
}
