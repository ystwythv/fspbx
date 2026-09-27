<?php

namespace App\Services;

use App\Models\AiAgent;
use App\Models\Destinations;
use App\Models\Domain;
use App\Services\Voxra\VoxraRoutingState;
use App\Services\Voxra\VoxraSuspendedAnnouncement;
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
    /** Pro's ring-first default (voxragtm#23); Line+AI uses 25 s. */
    public const DEFAULT_RING_FIRST_TIMEOUT = 20;
    public const MIN_RING_FIRST_TIMEOUT = 10;
    public const MAX_RING_FIRST_TIMEOUT = 60;

    /** First action of a suspended DID's routing (voxragtm#173). */
    public const SUSPENDED_MARKER = 'voxra_service_suspended=true';

    /** Order a UK number within the cap and route it to the agent. Returns the
     *  E.164 number, or null when disabled / nothing suitable. */
    public function orderAndRoute(Domain $domain, AiAgent $agent, ?string $requirementGroupId = null): ?string
    {
        // Idempotency: provision() is re-run as a settings sync, so a domain
        // that already has a Voxra DID (ours, or iqportal's Magrathea DDI)
        // must never order another (paid) number — return the existing one.
        $existing = $this->findReceptionDestination($domain);
        if ($existing) {
            return $existing->destination_number_e164 ?: $existing->destination_number;
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

    public function agentOnlyActions(Domain $domain, AiAgent $agent): array
    {
        return [buildDestinationAction(
            ['type' => 'ai_agents', 'extension' => $agent->agent_extension],
            $domain->domain_name,
        )];
    }

    /**
     * The DID's routing for a non-Complete Voxra tenant (voxragtm#162) —
     * one decision table for every mode, so Line ↔ Line+AI ↔ Pro switches,
     * the AI kill-switch and suspension each rewrite the destination from
     * scratch instead of patching whatever an earlier call left behind.
     * Pure: no DB, no dialplan rebuild.
     *
     *   suspended                  → announcement, hang up (voxragtm#173)
     *   line (v1)                  → 9260 follow-me, then voicemail
     *   line_ai, AI on, mobile     → ring mobile ($timeout s), then the AI
     *   line_ai, AI on, no mobile  → the AI
     *   line_ai, AI off            → 9260 follow-me, then voicemail
     *   pro, AI on, ring-first     → ring mobile ($timeout s), then the AI
     *   pro, AI on                 → the AI
     *   pro, AI off, ring-first    → ring mobile ($timeout s), then voicemail
     *   pro, AI off                → voicemail  (voxragtm#164: never 9250,
     *                                whose dialplan is disabled → dead air)
     *
     * $mobile is the validated E.164 to ring first (null = don't): for
     * line_ai the owner's mobile, for pro only when ring-first is on.
     *
     * @return array{kind: string, actions: array<int, array{destination_app: string, destination_data: string}>}
     */
    public function resolveDidRouting(
        Domain $domain,
        AiAgent $agent,
        string $mode,
        bool $agentEnabled,
        ?string $mobile,
        int $timeout,
        bool $suspended,
        ?string $announcement = null,
    ): array {
        if ($suspended) {
            return ['kind' => 'suspended', 'actions' => $this->suspendedActions($announcement)];
        }

        if ($mode === VoxraRoutingState::MODE_LINE
            || ($mode === VoxraRoutingState::MODE_LINE_AI && ! $agentEnabled)) {
            return ['kind' => 'line_voicemail', 'actions' => $this->lineActions($domain)];
        }

        if ($agentEnabled) {
            return $mobile !== null
                ? ['kind' => 'ring_first_ai', 'actions' => $this->ringFirstActions($domain, $agent, $mobile, $timeout)]
                : ['kind' => 'ai', 'actions' => $this->agentOnlyActions($domain, $agent)];
        }

        // Pro with the AI off (minutes used up / trial unpaid).
        return $mobile !== null
            ? ['kind' => 'ring_first_voicemail', 'actions' => array_merge(
                $this->ringFirstBridgeActions($domain, $mobile, $timeout),
                $this->voicemailActions($domain),
            )]
            : ['kind' => 'voicemail', 'actions' => $this->voicemailActions($domain)];
    }

    /**
     * Point every Voxra DID of the tenant at the given actions and rebuild
     * their dialplans. Only destination_actions changes: number, prefix,
     * regex, caller-ID prefix, recording flags etc. that iqportal set stay
     * as they are, and the phone-number dialplan template still runs the
     * spam screen before these actions. The actions fully replace whatever
     * iqportal's PATCH /api/voxra/phone-numbers wrote — voxraweb
     * re-provisions after every allocate/re-route, so this is the last
     * writer (the dialplan job reads the row when it runs, so the last row
     * write is what FreeSWITCH gets). Rows already carrying these actions
     * are skipped (no needless rebuild). Returns how many were rewritten.
     */
    public function applyDidActions(Domain $domain, array $actions): int
    {
        $json = json_encode($actions);
        $rewritten = 0;

        foreach ($this->findVoxraDestinations($domain) as $dest) {
            if ($dest->destination_actions === $json) {
                continue;
            }

            $dest->destination_actions = $json;
            $dest->save();

            dispatch(new \App\Jobs\BuildDialplanForPhoneNumber($dest->destination_uuid, $domain->domain_name));
            $rewritten++;
        }

        return $rewritten;
    }

    /** The tenant's first Voxra DID (oldest first), or null. */
    public function findReceptionDestination(Domain $domain): ?Destinations
    {
        return $this->findVoxraDestinations($domain)->first();
    }

    /**
     * Every enabled inbound number of the tenant that Voxra routes, oldest
     * first (a tenant can have more than one). See isVoxraDid for the rules.
     *
     * @return \Illuminate\Support\Collection<int, Destinations>
     */
    public function findVoxraDestinations(Domain $domain): \Illuminate\Support\Collection
    {
        return Destinations::where('domain_uuid', $domain->domain_uuid)
            ->where('destination_type', 'inbound')
            ->orderBy('insert_date')
            ->orderBy('destination_uuid')
            ->get()
            ->filter(fn (Destinations $d) => self::isEnabled($d->destination_enabled)
                && self::isVoxraDid($d->destination_description, $d->destination_actions, $domain->domain_name))
            ->values();
    }

    /**
     * Is this inbound destination a Voxra number whose routing provisioning
     * owns? (fspbx#132 review: live numbers are iqportal Magrathea DDIs,
     * "Inbound +44…", never the "Voxra reception…" rows of the Telnyx
     * auto-order path.)
     *
     *  - never a Voxra Complete number: the SIM's own MSISDN row, or any
     *    number transferring to a mobile extension (200–299) — iqportal
     *    routes those to the eSIM (mode:extension);
     *  - otherwise yes when the description is "Voxra reception…" (Telnyx
     *    auto-order) or "Inbound +…" (iqportal V1 API), or when the actions
     *    are ones Voxra writes: a transfer to the agent range 9250–9299
     *    (incl. the 9260 line extension) or its voicemail (*99925x–*99929x),
     *    the ring-first mobile bridge, or the suspended announcement.
     */
    public static function isVoxraDid(?string $description, $actions, string $domainName): bool
    {
        $description = (string) $description;
        if ($description === ProvisionCompleteService::MSISDN_DESTINATION_DESCRIPTION) {
            return false;
        }

        $decoded = is_array($actions) ? $actions : json_decode((string) $actions, true);
        $decoded = is_array($decoded) ? $decoded : [];

        $voxraActions = false;
        foreach ($decoded as $action) {
            $app = (string) ($action['destination_app'] ?? '');
            $data = trim((string) ($action['destination_data'] ?? ''));

            if ($app === 'transfer' && preg_match('/^(\S+) XML (\S+)$/', $data, $m)) {
                if ($m[2] !== $domainName) {
                    continue;
                }
                if (preg_match('/^2\d\d$/', $m[1])) {
                    return false; // Complete: the eSIM's mobile extension
                }
                if (preg_match('/^(\*99)?92[5-9]\d$/', $m[1])) {
                    $voxraActions = true;
                }
            } elseif ($app === 'set' && $data === self::SUSPENDED_MARKER) {
                $voxraActions = true;
            } elseif ($app === 'bridge' && str_contains($data, '}loopback/') && str_ends_with($data, '/' . $domainName)) {
                $voxraActions = true;
            }
        }

        return $voxraActions
            || str_starts_with($description, 'Voxra reception')
            || str_starts_with($description, 'Inbound +');
    }

    /** destination_enabled as stored ('true' by the UI / V1 API, '1' when a
     *  bool was saved into the text column). */
    public static function isEnabled($value): bool
    {
        return in_array(strtolower(trim((string) $value)), ['true', '1', 't', 'yes', 'on'], true)
            || $value === true;
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
     * Ring-mobile-first (voxragtm#23): ring the owner's mobile for $timeout
     * seconds, then fall through to the agent. Pro defaults to 20 s; Line+AI
     * uses 25 s (voxragtm#163).
     */
    public function ringFirstActions(Domain $domain, AiAgent $agent, string $mobile, int $timeout = self::DEFAULT_RING_FIRST_TIMEOUT): array
    {
        return array_merge(
            $this->ringFirstBridgeActions($domain, $mobile, $timeout),
            $this->agentOnlyActions($domain, $agent),
        );
    }

    /**
     * The mobile leg on its own: bridge via loopback into the domain's
     * outbound routing with press-1-to-accept (group_confirm, as FusionPBX
     * follow-me does) so a carrier voicemail answering the leg cancels it
     * instead of swallowing the call, and continue_on_fail so whatever
     * follows (the agent, or voicemail when the AI is off) runs when the
     * owner doesn't take it. The confirm variables ride the dial string so
     * they scope to this bridge only, not the next leg.
     */
    public function ringFirstBridgeActions(Domain $domain, string $mobile, int $timeout): array
    {
        $confirm = 'group_confirm_key=1'
            . ',group_confirm_file=ivr/ivr-accept_reject_voicemail.wav'
            . ',group_confirm_cancel_timeout=1';

        return [
            ['destination_app' => 'set', 'destination_data' => 'hangup_after_bridge=true'],
            ['destination_app' => 'set', 'destination_data' => 'call_timeout=' . self::clampRingFirstTimeout($timeout)],
            ['destination_app' => 'set', 'destination_data' => 'continue_on_fail=true'],
            ['destination_app' => 'bridge', 'destination_data' => '{' . $confirm . '}loopback/' . $mobile . '/' . $domain->domain_name],
        ];
    }

    /** DID → the tenant's Voxra voicemail box via the stock *99<box>
     *  send-to-voicemail dialplan (FusionPBX voicemail lua: branded greeting,
     *  transcription, voicemail.finalized webhook). */
    public function voicemailActions(Domain $domain): array
    {
        return [buildDestinationAction(
            ['type' => 'voicemails', 'extension' => ProvisionLineService::LINE_EXTENSION],
            $domain->domain_name,
        )];
    }

    /**
     * A suspended number (voxragtm#173): answer, play the announcement (an
     * absolute WAV path or a tone_stream), hang up. No mobile leg, no AI, no
     * voicemail. The first action marks the routing so a later re-provision
     * can tell the DID is suspended.
     */
    public function suspendedActions(?string $announcement): array
    {
        return [
            ['destination_app' => 'set', 'destination_data' => self::SUSPENDED_MARKER],
            ['destination_app' => 'answer', 'destination_data' => ''],
            ['destination_app' => 'sleep', 'destination_data' => '500'],
            ['destination_app' => 'playback', 'destination_data' => $announcement ?: VoxraSuspendedAnnouncement::FALLBACK_TONE],
            ['destination_app' => 'hangup', 'destination_data' => 'NORMAL_CLEARING'],
        ];
    }

    /** DID → line extension; the stock local_extension dialplan then runs
     *  follow-me and the voicemail fallback. */
    public function lineActions(Domain $domain): array
    {
        return [buildDestinationAction(
            ['type' => 'extensions', 'extension' => ProvisionLineService::LINE_EXTENSION],
            $domain->domain_name,
        )];
    }

    /** Ring-first timeout clamped to the accepted 10–60 s. */
    public static function clampRingFirstTimeout(int $timeout): int
    {
        return max(self::MIN_RING_FIRST_TIMEOUT, min(self::MAX_RING_FIRST_TIMEOUT, $timeout));
    }

    /** The mode's ring-first timeout when neither the request nor the
     *  stored state sets one: 25 s for Line (both kinds), 20 s for Pro. */
    public static function defaultRingFirstTimeout(string $mode): int
    {
        return in_array($mode, [VoxraRoutingState::MODE_LINE, VoxraRoutingState::MODE_LINE_AI], true)
            ? ProvisionLineService::FOLLOW_ME_TIMEOUT
            : self::DEFAULT_RING_FIRST_TIMEOUT;
    }

    /** The mobile a ring-first DID currently bridges, or null. Lets DIDs
     *  routed before the routing state was stored keep ringing it. */
    public static function ringFirstMobileIn(?string $actionsJson): ?string
    {
        return preg_match('#loopback\\\\?/(\+\d{8,15})\\\\?/#', (string) $actionsJson, $m) ? $m[1] : null;
    }

    /** True when the DID currently plays the suspended announcement. */
    public static function isSuspendedRouting(?string $actionsJson): bool
    {
        return str_contains((string) $actionsJson, self::SUSPENDED_MARKER);
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
            // iqportal's V1 rows: prefix 44 + national number without the 0
            $candidates[] = substr($digits, 2);
        }

        return Destinations::where('destination_type', 'inbound')
            ->whereIn('destination_number', $candidates)
            ->exists();
    }
}
