<?php

namespace App\Services;

use App\Models\AiAgent;
use App\Models\Destinations;
use App\Models\Domain;
use Illuminate\Support\Str;

/**
 * Auto-order a Telnyx DID for a tenant on activation and route it to their
 * reception agent (voxragtm#23, completes #42).
 *
 * GATED + SPEND-CAPPED: does nothing unless `services.voxra.provision_order_number`
 * is true AND a `number_connection_id` is set. `createOrder` is the only paid
 * call; it's skipped when disabled. Inbound routing: the DID lands (source-IP
 * gated, no auth) in FreeSWITCH's `public` context via the voxra-pbx-inbound
 * FQDN connection (→ sip-in.voxra.uk SRV → lon1/eu1:5080), and the v_destinations
 * row we create routes destination_number → the agent's extension.
 */
class ProvisionNumberService
{
    /** Order a UK number within the cap and route it to the agent. Returns the
     *  E.164 number, or null when disabled / nothing suitable. */
    public function orderAndRoute(Domain $domain, AiAgent $agent, ?string $requirementGroupId = null): ?string
    {
        // Idempotency: provision() is re-run as a settings sync, so a domain
        // that already has its Voxra DID routed must never order another
        // (paid) number — return the existing one.
        $existing = $this->findReceptionDestination($domain);
        if ($existing) {
            return $existing->destination_number;
        }

        if (! config('services.voxra.provision_order_number')) {
            return null;
        }
        $connectionId = (string) config('services.voxra.number_connection_id', '');
        if ($connectionId === '') {
            logger('Voxra auto-number skipped: services.voxra.number_connection_id not set');
            return null;
        }
        // Regulatory gate: UK numbers require an APPROVED requirement group
        // (proof of address + ID). voxraweb passes it only when approved.
        if (! $requirementGroupId) {
            logger('Voxra auto-number skipped: no approved regulatory requirement group for ' . $domain->domain_name);
            return null;
        }

        $svc = app(TelnyxNumberService::class);
        $cap = (float) config('services.voxra.number_max_monthly_cost', 5.0);

        $candidates = $svc->searchAvailable([
            'country'  => config('services.voxra.number_country', 'GB'),
            'type'     => config('services.voxra.number_type', 'local'),
            'features' => ['voice', 'sms'],
            'limit'    => 10,
        ]);

        $pick = null;
        foreach ($candidates as $c) {
            $cost = $c['monthly_cost'] ?? null;
            if ($cost === null || (float) $cost <= $cap) {
                $pick = $c;
                break;
            }
        }
        if (! $pick) {
            logger('Voxra auto-number skipped: no candidate within monthly cap ' . $cap);
            return null;
        }

        $number = $pick['phone_number'];
        $messagingProfileId = (string) config('services.voxra.number_messaging_profile_id', '') ?: null;

        $order = $svc->createOrder([$number], $connectionId, $messagingProfileId, $requirementGroupId);

        // Orders settle asynchronously — poll briefly (routing works regardless).
        if (! empty($order['id'])) {
            for ($i = 0; $i < 5; $i++) {
                $state = $svc->getOrder($order['id']);
                if (($state['status'] ?? '') === 'success') {
                    break;
                }
                usleep(1_500_000);
            }
        }

        $this->routeDidToAgent($domain, $agent, $number);

        return $number;
    }

    /** Create the inbound v_destinations row routing a DID to the reception
     *  agent's extension, and build its public-context dialplan. */
    public function routeDidToAgent(Domain $domain, AiAgent $agent, string $did): void
    {
        $dest = new Destinations();
        $dest->fill([
            'destination_uuid'        => (string) Str::uuid(),
            'domain_uuid'             => $domain->domain_uuid,
            'dialplan_uuid'           => (string) Str::uuid(),
            'destination_type'        => 'inbound',
            'destination_number'      => $did, // +E.164 (connection dnis_number_format = +e164)
            'destination_actions'     => json_encode($this->agentOnlyActions($domain, $agent)),
            'destination_enabled'     => true,
            'destination_context'     => 'public',
            'destination_description' => 'Voxra reception (auto-provisioned)',
        ]);
        $dest->insert_date = date('Y-m-d H:i:s');
        $dest->save();

        dispatch(new \App\Jobs\BuildDialplanForPhoneNumber($dest->destination_uuid, $domain->domain_name));
    }

    private function agentOnlyActions(Domain $domain, AiAgent $agent): array
    {
        return [buildDestinationAction(
            ['type' => 'ai_agents', 'extension' => $agent->agent_extension],
            $domain->domain_name,
        )];
    }

    /**
     * Ring-mobile-first (voxragtm#23): re-point the tenant's Voxra DID so the
     * owner's mobile rings for ~20s first, then the reception agent answers.
     * Uses sequential destination actions (the phone-number dialplan template
     * emits them verbatim): bridge via loopback into the domain's outbound
     * routing with a timeout + continue_on_fail, falling through to the agent
     * transfer. Passing ringFirst=false (or no mobile) restores DID → agent.
     * Idempotent: updates the existing Voxra destination row when present.
     */
    public function applyRingFirst(Domain $domain, AiAgent $agent, bool $ringFirst, ?string $ownerMobile): void
    {
        $dest = $this->findReceptionDestination($domain);
        if (! $dest) {
            return; // no DID routed yet — activation/number order handles it later
        }

        $mobile = $this->resolveRingFirstMobile($domain, $ringFirst, $ownerMobile);
        $actions = $mobile !== null
            ? $this->ringFirstActions($domain, $agent, $mobile)
            : $this->agentOnlyActions($domain, $agent);

        $dest->destination_actions = json_encode($actions);
        $dest->save();

        dispatch(new \App\Jobs\BuildDialplanForPhoneNumber($dest->destination_uuid, $domain->domain_name));
    }

    /** The tenant's Voxra reception destination. Deterministic (oldest row
     *  first) so re-provisions always update the same row. */
    public function findReceptionDestination(Domain $domain): ?Destinations
    {
        return Destinations::where('domain_uuid', $domain->domain_uuid)
            ->where('destination_type', 'inbound')
            ->where('destination_description', 'like', 'Voxra reception%')
            ->orderBy('insert_date')
            ->orderBy('destination_uuid')
            ->first();
    }

    /** The validated E.164 mobile to ring first, or null for agent-only routing. */
    public function resolveRingFirstMobile(Domain $domain, bool $ringFirst, ?string $ownerMobile): ?string
    {
        if (! $ringFirst) {
            return null;
        }

        $mobile = self::normaliseOwnerMobile($ownerMobile);
        if ($mobile === null) {
            if (trim((string) $ownerMobile) !== '') {
                logger()->warning('Voxra ring-first disabled for ' . $domain->domain_name
                    . ': owner_mobile is not a usable E.164 number');
            }
            return null;
        }

        // PSTN loop guard: ringing a DID hosted on this PBX would send the
        // call out the gateway and straight back inbound, looping forever
        // (Max-Forwards resets each round trip) at per-leg PSTN cost.
        if ($this->isHostedNumber($mobile)) {
            logger()->warning('Voxra ring-first refused for ' . $domain->domain_name
                . ': owner_mobile ' . $mobile . ' is a DID hosted on this PBX (would loop)');
            return null;
        }

        return $mobile;
    }

    /**
     * Sequential actions: bridge the owner's mobile for 20s with
     * press-1-to-accept (group_confirm, as FusionPBX follow-me does) so a
     * carrier voicemail answering the leg cancels it instead of swallowing
     * the call, then fall through to the agent. The confirm variables ride
     * the dial string so they scope to this bridge only, not the agent leg.
     */
    public function ringFirstActions(Domain $domain, AiAgent $agent, string $mobile): array
    {
        $confirm = 'group_confirm_key=1'
            . ',group_confirm_file=ivr/ivr-accept_reject_voicemail.wav'
            . ',group_confirm_cancel_timeout=1';

        return [
            ['destination_app' => 'set', 'destination_data' => 'hangup_after_bridge=true'],
            ['destination_app' => 'set', 'destination_data' => 'call_timeout=20'],
            ['destination_app' => 'set', 'destination_data' => 'continue_on_fail=true'],
            ['destination_app' => 'bridge', 'destination_data' => '{' . $confirm . '}loopback/' . $mobile . '/' . $domain->domain_name],
            buildDestinationAction(
                ['type' => 'ai_agents', 'extension' => $agent->agent_extension],
                $domain->domain_name,
            ),
        ];
    }

    /**
     * Normalise an owner mobile for the ring-first bridge: strip
     * spaces/punctuation, convert GB national 0… (and international 00…) to
     * +E.164, and reject anything that isn't + followed by 8-15 digits — a
     * malformed number makes the bridge fail fast with nothing logged.
     */
    public static function normaliseOwnerMobile(?string $raw): ?string
    {
        $n = preg_replace('/[\s().-]/', '', (string) $raw);
        if ($n === '') {
            return null;
        }
        if (str_starts_with($n, '00')) {
            $n = '+' . substr($n, 2);
        } elseif (str_starts_with($n, '0')) {
            $n = '+44' . substr($n, 1);
        }

        return preg_match('/^\+\d{8,15}$/', $n) ? $n : null;
    }

    /** True when the number is a DID hosted on this PBX (any domain). */
    public function isHostedNumber(string $e164): bool
    {
        $digits = ltrim($e164, '+');
        $candidates = [$e164, $digits];
        if (str_starts_with($digits, '44')) {
            $candidates[] = '0' . substr($digits, 2); // GB national form
        }

        return Destinations::where('destination_type', 'inbound')
            ->whereIn('destination_number', $candidates)
            ->exists();
    }
}
