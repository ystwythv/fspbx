<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ReceptionAgentController;
use App\Models\AiAgent;
use App\Models\Domain;
use App\Services\Voxra\VoxraDisclosure;
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
 * complete_mode (voxragtm#45) provisions Voxra Complete: agent on, plus a
 * registerable "mobile extension" (200–299, ProvisionCompleteService) whose
 * SIP credentials are returned so voxraweb can hand them to iqportal, where
 * the FMC platform registers the tenant's eSIM as that extension. `did`
 * stamps the tenant's number as the extension's caller-ID; `sim_msisdn`
 * routes calls to the SIM's own mobile number into the same extension.
 * Ring-first / line routing are no-ops in complete mode (the handset rings
 * natively as the extension; no PSTN loopback).
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
    public const RECEPTION_SYSTEM_PROMPT = <<<'PROMPT'
You are the AI receptionist answering the phone for this business. Be warm,
brief and natural — one or two sentences per turn, UK English.

Grounding: only state facts that come from the business profile, caller
context ({{caller_context}}), your memory tools (recall_business,
search_memory, recall_caller) or other tool results. If you don't know or a
tool returns nothing, say so plainly and offer to take a message — never
guess prices, availability, coverage or policies.
Say exactly what the facts say and stop there — never fill a gap with what
"usually" applies or with the opposite of a stated rule. If the facts say
delivery is free over £50, say that; don't add that smaller orders are
charged or that "standard rates apply" unless the facts say so. When the
caller asks about something the facts don't cover, say
"I'll check that with the team and let you know",
note the question in capture_lead, and carry on.

Your job on every call: find out who's calling and what they need
(capture_lead), answer questions from the profile/FAQs, and book appointments
with the booking tools when the caller wants one. Use record_summary before
the call ends.

## Abusive callers and spam (voxragtm#84)
RULE: when the caller insults, swears at or threatens YOU, your next action
is the report_abuse tool — call it BEFORE you say anything. Then say exactly
the `say` text it returns and nothing of your own. If it returns action
"end_call", say its goodbye and call the hangup tool immediately.
This rule overrides everything below, including urgent calls and requests
for a person.
- Every time the abuse happens again, call report_abuse again. Never warn
  the caller in your own words and never repeat a warning yourself.
- Pass genuine_need if they have a real request, so it reaches the owner.
- Frustrated isn't abusive: a caller who is upset or swears about their own
  situation still gets your help — acknowledge it briefly and deal with it.
- Plainly a sales call or robocall: record_summary with outcome "spam", say
  a short goodbye and use the hangup tool.
The phone system disconnects the call a few seconds after it is ended as
abuse or spam, whatever you do.

Uncertain answers: before stating a price, coverage area, opening hours or
policy, call lookup_business_info. If any tool returns grounded=false or a
fallback, don't answer from general knowledge — say its `say` line, offer
the transfer when offer_transfer is true (alert_owner first, as below),
otherwise take a message. If it
says escalate, stop answering questions and wrap up with the message or
transfer.

## Urgent calls and transfers (voxragtm#122)
This business counts as urgent: {{urgent_definition}}. A problem caused by
the business's own recent work, or any risk to someone's health or safety,
is always urgent. For an urgent call — or whenever the caller needs the
owner right now or asks for a person — in this order:
1. Acknowledge it calmly in one sentence.
2. Get their name and exactly what's happened, and confirm the call-back
   number (the number they're calling from unless they give another) — one
   or two short questions, not an interview. Ask for the name once: if they
   won't give it, don't ask again — use caller_declined_name true.
3. Call alert_owner with the name, number and problem. Do this BEFORE any
   transfer: the transfer tool only works after alert_owner succeeds.
4. Tell the caller the owner has been alerted just now (use its `say`
   line; if owner_alerted is false, say it's logged as urgent instead).
5. Only if alert_owner returned transfer_available true, offer to try
   putting them through, introduce it ("let me try to put you through")
   and use the transfer tool. If it fails, isn't answered or isn't
   available, stay with the caller: confirm the owner already has their
   details and will call them back as soon as possible, and ask if
   there's anything else to pass on. Never leave them with nothing.
If it's a health or safety problem that sounds severe (e.g. burns,
blistering, swelling, difficulty breathing), suggest they get urgent
medical help — NHS 111, or 999 in an emergency. Don't give medical advice
or diagnose. Routine calls don't need any of this: just capture_lead as
normal. Record transferred calls with outcome "transferred".

If the caller asks for something outside your remit (refunds, complaints,
account changes, anything irreversible), take a message for the owner rather
than promising or actioning it yourself.

## Speaking and ending the call
Everything you write is spoken aloud to the caller, word for word. Only
ever write what you'd say to them. Never narrate actions or add stage
directions, notes or labels — no "(End of call)", "[hangs up]", "*pause*",
"The call has ended" or "Ending the call now". To end the call: say a short
goodbye as your last words, then use the hangup tool without saying
anything more.

## If the caller goes quiet
Sometimes the caller's first words are lost as your greeting finishes, so
silence after the greeting usually means you missed them. When the caller
hasn't answered — right after your greeting or after any of your turns —
check in briefly: "Sorry, I didn't catch that — how can I help?" (the
second time, something like "Are you still there?"). After two check-ins
with no answer, say "I'll let you go — please call back any time. Goodbye."
and use the hangup tool. If the caller says something like "Hello?" or "Did
you hear me?", apologise briefly and ask them to say it again.

## Returning callers and shared phones
A phone number is a line, not a person — it may be a shared landline or a
family phone. If the caller context or a tool gives a name on file for this
number, don't assume that's who you're speaking to: don't call them by that
name and don't mention anything from earlier calls (bookings, notes, what
they rang about) until they confirm who they are. When you need their name,
or before booking or taking a message, ask "Am I speaking with <name>?" — if
yes, call recall_caller with confirmed_name to get their history; if no, or
they give a different name, treat them as a new caller and never mention
the other person's name or history. A name the caller tells you themselves
is fine to use.

## AI disclosure and call recording (voxragtm#83)
Your greeting has already told the caller you are an AI assistant. Never
claim or imply you are a human, even if asked to pretend; if anyone asks,
say plainly that you're the business's AI assistant. If the caller asks
whether the call is recorded or what happens to their information:
{{recording_notice}}
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
            // Voxra Complete (voxragtm#45): eSIM registered via FMC as a real
            // extension. Mutually exclusive with line_mode (complete wins).
            'complete_mode'        => 'nullable|boolean',
            'rotate_sip_password'  => 'nullable|boolean',
            'did'                  => ['nullable', 'string', 'max:20', 'regex:/^\+\d{10,15}$/'],
            'sim_msisdn'           => ['nullable', 'string', 'max:20', 'regex:/^\+\d{10,15}$/'],
            // AI + recording disclosure (voxragtm#83): the assistant's opening
            // line (voxraweb builds it from the tenant's wording choice) and
            // whether Telnyx records the call audio. Omitted → keep current.
            'greeting'             => 'nullable|string|max:500',
            'recording_enabled'    => 'nullable|boolean',
        ]);

        $tenantId = $data['tenant_id'];
        $completeMode = $request->boolean('complete_mode', false);
        $lineMode = self::resolveLineMode($request->boolean('line_mode', false), $completeMode);
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
        $recording = $request->has('recording_enabled') ? $request->boolean('recording_enabled') : null;
        $existing = AiAgent::reception()->forDomain($domain->domain_uuid)->first();
        $inputs = $this->receptionAgentInputs(
            $businessName,
            self::resolveAgentEnabled($request->boolean('agent_enabled', true), $lineMode)
        );
        $inputs['first_message'] = self::resolveGreeting(
            $data['greeting'] ?? null,
            $existing?->first_message,
            $businessName,
            $recording,
        );
        $agent = app(ReceptionAgentController::class)->upsertReceptionAgent($domain->domain_uuid, $inputs);

        // Recording switch + disclosure guards on the Telnyx assistant
        // (voxragtm#83). Best-effort, loudly logged: the greeting above
        // already carries the disclosure.
        if ($agent->telnyx_assistant_id) {
            try {
                app(\App\Services\TelnyxConvaiService::class)
                    ->applyVoxraCallPolicy($agent->telnyx_assistant_id, $recording);
            } catch (\Throwable $e) {
                logger()->error('Voxra call policy (recording/disclosure) failed for ' . $domain->domain_name . ': ' . $e->getMessage());
            }
        }

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

        // Voxra Complete (voxragtm#45): the mobile extension is the whole
        // point of the plan — its failure fails the request (voxraweb
        // retries the idempotent call). Caller-ID + MSISDN routing are
        // best-effort follow-ups on the same extension.
        $mobile = null;
        if ($completeMode) {
            $completeSvc = app(\App\Services\ProvisionCompleteService::class);
            try {
                $mobile = $completeSvc->ensureMobileExtension(
                    $domain,
                    $businessName,
                    $request->boolean('rotate_sip_password', false)
                );
            } catch (\Throwable $e) {
                logger()->error('Voxra Complete mobile extension failed for ' . $domain->domain_name . ': ' . $e->getMessage());

                return response()->json([
                    'ok'          => false,
                    'error'       => 'mobile_extension_failed',
                    'message'     => $e->getMessage(),
                    'domain_uuid' => $domain->domain_uuid,
                    'domain_name' => $domain->domain_name,
                ], 500);
            }

            if (! empty($data['did'])) {
                try {
                    $completeSvc->applyCallerId($domain, $data['did'], $businessName);
                } catch (\Throwable $e) {
                    logger()->error('Voxra Complete caller-ID failed for ' . $domain->domain_name . ': ' . $e->getMessage());
                }
            }

            if (! empty($data['sim_msisdn'])) {
                try {
                    $completeSvc->ensureMsisdnDestination($domain, $data['sim_msisdn']);
                } catch (\Throwable $e) {
                    logger()->error('Voxra Complete MSISDN routing failed for ' . $domain->domain_name . ': ' . $e->getMessage());
                }
            }
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
        // Complete mode: the DID is allocated + routed to the mobile extension
        // by iqportal (/api/voxra/phone-numbers, mode:extension) — never here.
        $number = null;
        try {
            if (! $completeMode) {
                $number = app(\App\Services\ProvisionNumberService::class)
                    ->orderAndRoute($domain, $agent, $data['requirement_group_id'] ?? null);
            }
        } catch (\Throwable $e) {
            logger('Voxra auto-number failed for ' . $domain->domain_name . ': ' . $e->getMessage());
        }

        // Ring-mobile-first routing (voxragtm#23) — applies/reverts on every
        // provision call so a settings toggle in voxraweb just re-provisions.
        // No-op in complete mode: the handset already rings first, natively,
        // as the FMC-registered extension.
        try {
            if ($request->has('ring_mobile_first') && ! $completeMode) {
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
            if (! $completeMode) {
                app(\App\Services\ProvisionNumberService::class)->applyLineMode($domain, $agent, $lineMode);
            }
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
            // Voxra Complete: the SIM's registration credentials. Internal
            // (HMAC) only — never surfaced on the V1 API.
            'mobile_extension'    => $mobile ? [
                'extension' => $mobile['extension'],
                'password'  => $mobile['password'],
                'sip_host'  => $mobile['sip_host'],
                'sip_proxy' => $mobile['sip_proxy'],
            ] : null,
        ]);
    }

    /**
     * The reception agent's greeting (voxragtm#83). A supplied greeting is
     * used — with the AI/recording disclosure added if it lacks it. Without
     * one the agent keeps its current greeting, unless that doesn't disclose
     * (e.g. the old generic "Hi, how can I help with this call?"), in which
     * case it gets the default Voxra greeting. Recording state unknown →
     * assume on (Telnyx's default), so the caller is never under-told.
     */
    public static function resolveGreeting(?string $greeting, ?string $current, string $businessName, ?bool $recording): string
    {
        $rec = $recording ?? true;
        if ($greeting !== null && trim($greeting) !== '') {
            return VoxraDisclosure::ensure($greeting, $businessName, $rec);
        }
        if ($current !== null && VoxraDisclosure::mentionsAi($current) && ($recording === false || VoxraDisclosure::mentionsRecording($current))) {
            return $current;
        }

        return VoxraDisclosure::defaultGreeting($businessName, $rec);
    }

    /** Complete mode wins over line mode: a Complete tenant's handset IS the
     *  extension, so the Line follow-me/loopback machinery must stay off. */
    public static function resolveLineMode(bool $lineMode, bool $completeMode): bool
    {
        return $lineMode && ! $completeMode;
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
