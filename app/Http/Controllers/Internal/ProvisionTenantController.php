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
 * line_mode (voxragtm#25) provisions the Voxra Line plan: agent disabled, DID
 * routed to a stock follow-me extension (ProvisionLineService) that rings the
 * owner's mobile then falls to transcribed PBX voicemail. Re-provisioning with
 * line_mode:false + agent_enabled:true is the Line → Start upgrade.
 *
 * Phone-number ordering is intentionally NOT done here — it spends money
 * (TelnyxNumberService::createOrder) and is a gated follow-up (voxragtm#23).
 */
class ProvisionTenantController extends Controller
{
    // Alistair — British male, Telnyx Ultra (voxra voice standard).
    private const UK_VOICE = 'Telnyx.Ultra.c8f7835e-28a3-4f0c-80d7-c1302ac62aae';

    /**
     * Inbound receptionist instructions (voxragtm#31/#84). Without this the
     * agent inherited ReceptionAgentController::DEFAULT_SYSTEM_PROMPT, which is
     * the *9 in-call summon assistant — wrong persona and no guardrails.
     * {{caller_context}} etc. are dynamic variables injected per call by
     * voxraweb's dynamic-variables webhook.
     */
    private const RECEPTION_SYSTEM_PROMPT = <<<'PROMPT'
You are the AI receptionist answering the phone for this business. Be warm,
brief and natural — one or two sentences per turn, UK English.

Grounding: only state facts that come from the business profile, caller
context ({{caller_context}}), your memory tools (recall_business,
search_memory, recall_caller) or other tool results. If you don't know or a
tool returns nothing, say so plainly and offer to take a message — never
guess prices, availability, coverage or policies.

Your job on every call: find out who's calling and what they need
(capture_lead), answer questions from the profile/FAQs, and book appointments
with the booking tools when the caller wants one. Use record_summary before
the call ends.

Abusive, threatening or clearly spam/robocall callers: stay calm, don't
argue. Warn once ("I'll have to end the call if this continues"), then wrap
up politely, and record the summary with outcome "spam". Never repeat or
engage with abusive content.

Transfers: when the caller genuinely needs the owner right now (urgent, or
they insist on a person), offer to put them through and use the transfer
tool. Introduce it first ("let me try to put you through"). If the transfer
fails or there is no transfer target, take a detailed message instead and
say the owner will call back. Record transferred calls with outcome
"transferred".

If the caller asks for something outside your remit (refunds, complaints,
account changes, anything irreversible), take a message for the owner rather
than promising or actioning it yourself.
PROMPT;

    public function provision(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id'            => 'required|string|max:64',
            'business_name'        => 'required|string|max:120',
            'requirement_group_id' => 'nullable|string|max:64',
            // Ring-mobile-first (voxragtm#23): owner's mobile rings ~20s
            // before the agent answers. Re-sent on settings changes.
            'owner_mobile'         => 'nullable|string|max:20',
            'ring_mobile_first'    => 'nullable|boolean',
            // Kill-switch (voxragtm#81): voxraweb re-provisions with false when
            // a tenant toggles Voxra off or hits their minute cap.
            'agent_enabled'        => 'nullable|boolean',
            // Voxra Line (voxragtm#25): £5 no-AI plan — DID rings the owner's
            // mobile via a stock follow-me extension, then PBX voicemail with
            // transcription. The agent exists but stays disabled.
            'line_mode'            => 'nullable|boolean',
        ]);

        $tenantId = $data['tenant_id'];
        $lineMode = $request->boolean('line_mode', false);
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

        // Idempotent upsert of the reception agent on the domain. A disabled
        // agent disables its dialplans, so inbound calls to the DID stop
        // reaching the assistant.
        $agent = app(ReceptionAgentController::class)->upsertReceptionAgent(
            $domain->domain_uuid,
            $this->receptionAgentInputs(
                $businessName,
                self::resolveAgentEnabled($request->boolean('agent_enabled', true), $lineMode)
            )
        );

        // Per-domain PSTN outbound route (voxragtm#110): ring-first and Line
        // follow-me bridge loopback/+44… into the tenant's own domain context,
        // and the stock bootstrap ships no outbound route there — without this
        // the loopback leg dies and the caller hears dead air. Every tenant
        // needs it; idempotent. Best-effort like its siblings, but loudly
        // logged: mobile legs keep failing until the gateway resolves.
        try {
            app(\App\Services\ProvisionOutboundRouteService::class)->ensureOutboundRoute($domain);
        } catch (\Throwable $e) {
            logger()->error('Voxra outbound route provisioning failed for ' . $domain->domain_name . ': ' . $e->getMessage());
        }

        // Voxra Line (voxragtm#25): idempotently provision the follow-me line
        // extension + voicemail box (branded TTS greeting, voxragtm#110).
        // Missing/unusable owner_mobile still gets the extension — it becomes
        // a straight-to-voicemail line.
        $line = null;
        if ($lineMode) {
            $line = app(\App\Services\ProvisionLineService::class)
                ->ensureLineExtension($domain, $data['owner_mobile'] ?? null, $businessName);
        }

        // Subscribe the tenant domain to cdr.finalized → voxraweb, which fires
        // missed-call text-backs and stamps real call durations (voxragtm#76).
        // Idempotent; shared secret so voxraweb verifies one HMAC for all
        // tenants. Best-effort.
        try {
            $cdrSecret = (string) config('services.voxra.cdr_webhook_secret', '');
            $voxraBase = rtrim((string) config('services.voxra.app_url', ''), '/');
            if ($cdrSecret !== '' && $voxraBase !== '') {
                $cdrUrl = $voxraBase . '/api/telnyx/call-ended';
                // updateOrCreate so a rotated VOXRA_CDR_WEBHOOK_SECRET
                // propagates on the next re-provision.
                \App\Models\ApiWebhook::updateOrCreate(
                    ['domain_uuid' => $domain->domain_uuid, 'url' => $cdrUrl],
                    [
                        'secret' => $cdrSecret,
                        'events' => [
                            \App\Models\ApiWebhook::EVENT_CDR_FINALIZED,
                            \App\Models\ApiWebhook::EVENT_VOICEMAIL_FINALIZED,
                        ],
                        'enabled' => true,
                        'description' => 'Voxra events (call-ended + voicemail)',
                    ]
                );
            }
        } catch (\Throwable $e) {
            logger('Voxra cdr webhook subscribe failed for ' . $domain->domain_name . ': ' . $e->getMessage());
        }

        // Auto-order + route a DID (voxragtm#23) — gated + spend-capped; returns
        // null unless VOXRA_PROVISION_ORDER_NUMBER is enabled. Best-effort: a
        // number failure must not fail provisioning (domain + agent are done).
        $number = null;
        try {
            $number = app(\App\Services\ProvisionNumberService::class)
                ->orderAndRoute($domain, $agent, $data['requirement_group_id'] ?? null);
        } catch (\Throwable $e) {
            logger('Voxra auto-number failed for ' . $domain->domain_name . ': ' . $e->getMessage());
        }

        // Ring-mobile-first routing (voxragtm#23) — applies/reverts on every
        // provision call so a settings toggle in voxraweb just re-provisions.
        try {
            if ($request->has('ring_mobile_first')) {
                app(\App\Services\ProvisionNumberService::class)->applyRingFirst(
                    $domain,
                    $agent,
                    (bool) ($data['ring_mobile_first'] ?? false),
                    $data['owner_mobile'] ?? null,
                );
            }
        } catch (\Throwable $e) {
            logger('Voxra ring-first routing failed for ' . $domain->domain_name . ': ' . $e->getMessage());
        }

        // Voxra Line routing (voxragtm#25) — after ring-first so enabling line
        // mode wins, and disabling it restores agent routing only when the DID
        // is still line-routed (a fresh ring-first rewrite is left alone).
        try {
            app(\App\Services\ProvisionNumberService::class)->applyLineMode($domain, $agent, $lineMode);
        } catch (\Throwable $e) {
            logger('Voxra line routing failed for ' . $domain->domain_name . ': ' . $e->getMessage());
        }

        return response()->json([
            'ok'                  => true,
            'domain_uuid'         => $domain->domain_uuid,
            'domain_name'         => $domain->domain_name,
            'agent_extension'     => $agent->agent_extension,
            'feature_code'        => $agent->feature_code,
            'telnyx_assistant_id' => $agent->telnyx_assistant_id,
            'number'              => $number,
            'line_extension'      => $line['extension'] ?? null,
            // true when owner_mobile was missing/unusable: the line answers
            // straight to voicemail until a valid mobile is re-provisioned
            'line_straight_to_voicemail' => $line['straight_to_voicemail'] ?? null,
        ]);
    }

    /** Line mode forces the agent off: it exists for the Line → Start upgrade
     *  but must not answer (voxragtm#25). */
    public static function resolveAgentEnabled(bool $agentEnabled, bool $lineMode): bool
    {
        return $agentEnabled && ! $lineMode;
    }

    /** Upsert inputs for the reception agent (agent_enabled is stored as the
     *  strings 'true'/'false' — FusionPBX toggle convention). */
    public function receptionAgentInputs(string $businessName, bool $agentEnabled): array
    {
        return [
            'agent_name'      => $businessName . ' Reception',
            'provider'        => 'telnyx',
            'model'           => 'moonshotai/Kimi-K2.6',
            'telnyx_voice_id' => self::UK_VOICE,
            'system_prompt'   => self::RECEPTION_SYSTEM_PROMPT,
            'feature_code'    => '*9',
            'agent_enabled'   => $agentEnabled ? 'true' : 'false',
        ];
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
