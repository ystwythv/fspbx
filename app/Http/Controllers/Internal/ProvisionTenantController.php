<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ReceptionAgentController;
use App\Models\AiAgent;
use App\Models\Domain;
use App\Services\ProvisionLineService;
use App\Services\ProvisionNumberService;
use App\Services\Voxra\VoxraDisclosure;
use App\Services\Voxra\VoxraOwnerCallRecording;
use App\Services\Voxra\VoxraRoutingState;
use App\Services\Voxra\VoxraSuspendedAnnouncement;
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
 * line_mode (voxragtm#25) provisions Voxra Line v1: agent disabled, DID
 * routed to a stock follow-me extension (ProvisionLineService) that rings the
 * owner's mobile then falls to transcribed PBX voicemail. Re-provisioning with
 * line_mode:false + agent_enabled:true is the Line → Start upgrade.
 *
 * line_ai (voxragtm#163) is the £10 Line: the owner's mobile rings for
 * ring_first_timeout seconds, then the AI answers; with the AI off it falls
 * back to the Line v1 path. It wins over line_mode and ring_mobile_first.
 *
 * Every non-Complete tenant gets the 9260 voicemail box (voxragtm#164): with
 * the AI off the DID goes (ring-first, then) to voicemail, never to the
 * disabled 9250. service_suspended (voxragtm#173) replaces all routing with
 * an announcement + hang-up until it is sent false again.
 *
 * Routing fields left out of a request keep their last value (stored per
 * domain in VoxraRoutingState), so a bare re-provision never drops
 * ring-first or un-suspends a number.
 *
 * owner_call_recording (voxragtm#157) records the inbound calls the OWNER
 * answers — ring-first bridges to their mobile and the Complete eSIM
 * extension — after a "this call may be recorded" announcement to the
 * caller, and posts each recording to voxraweb (VoxraOwnerCallRecording).
 * voxraweb decides who may have it (Pro/Complete opt-in) and always sends
 * it; false re-asserts today's state (nothing recorded, no announcement).
 * Line v1's follow-me can't be recorded, so it stays off there.
 *
 * complete_mode (voxragtm#45) provisions Voxra Complete: agent on, plus a
 * registerable "mobile extension" (200–299, ProvisionCompleteService) whose
 * SIP credentials are returned so voxraweb can hand them to iqportal, where
 * the FMC platform registers the tenant's eSIM as that extension.
 * `sim_msisdn` routes calls to the SIM's own mobile number into the same
 * extension. The extension's own outbound calls present that mobile number
 * by default, or the tenant's number (`did`) when `outbound_cli` is
 * "business" (stored per domain; omitted → last value, else mobile).
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
The business's "NEVER promise or agree to" rules outrank everything else,
tool results included: if a tool shows something the rules forbid (for
example a slot today when same-day visits are never promised), don't offer
or agree to it — offer what the rules allow instead.

Your job on every call: find out who's calling and what they need
(capture_lead), answer questions from the profile/FAQs, and book, cancel or
move appointments with the booking tools when the caller wants to. Use record_summary before
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
account changes, discounts, anything irreversible), take a message for the
owner rather than promising or actioning it yourself.

## Bookings (voxragtm#194)
The caller context says how this business books ("Bookings: …"). Ask which
day suits before you check availability — don't check a day they haven't
asked about. For a job at the caller's address (a visit, a survey, a
call-out or a lesson pick-up), get their name, the house number (or house
name) and street, and the postcode BEFORE you book, and read the whole
address back to check it, e.g. "That's 64 Cedric Road, LE4 6AB — is that
right?". Pass address_line1 and postcode to book_appointment. If a tool says
the postcode is outside the area the business covers (out_of_area), tell the
caller politely with its `say` line: don't book, offer times or promise
anyone will come — their details are passed to the owner, who decides.
When a booking is made, say what its confirmation_instruction tells you —
usually "I'll text you a confirmation". Never tell a caller you can't send
texts, and don't read out booking references unless they ask. If you don't
know who will do the job, say the team will confirm — don't guess. Only use
a name the caller has told you on this call: ask for it, never make one up.

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
            // Voxra Line v1 (voxragtm#25): no-AI plan — DID rings the owner's
            // mobile via a stock follow-me extension, then PBX voicemail with
            // transcription. The agent exists but stays disabled. Superseded
            // by line_ai (voxragtm#162), which wins when both are sent.
            'line_mode'            => 'nullable|boolean',
            // Voxra Complete (voxragtm#45): eSIM registered via FMC as a real
            // extension. Mutually exclusive with line_mode (complete wins).
            'complete_mode'        => 'nullable|boolean',
            // Voxra Line + AI (voxragtm#163): owner's mobile first, then the
            // AI. Wins over line_mode / ring_mobile_first (voxraweb also sends
            // those so an older PBX still rings the mobile first).
            'line_ai'              => 'nullable|boolean',
            // Seconds the owner's mobile rings before the AI (or voicemail
            // when the AI is off). Omitted → last value, else 25 for Line /
            // Line+AI and 20 for Pro.
            'ring_first_timeout'   => 'nullable|integer|min:' . ProvisionNumberService::MIN_RING_FIRST_TIMEOUT
                . '|max:' . ProvisionNumberService::MAX_RING_FIRST_TIMEOUT,
            // Unpaid → dunning suspended the number (voxragtm#173): the DID
            // plays "temporarily unavailable" and hangs up. Omitted → keep.
            'service_suspended'    => 'nullable|boolean',
            'rotate_sip_password'  => 'nullable|boolean',
            'did'                  => ['nullable', 'string', 'max:20', 'regex:/^\+\d{10,15}$/'],
            'sim_msisdn'           => ['nullable', 'string', 'max:20', 'regex:/^\+\d{10,15}$/'],
            // Complete: what the eSIM's own outbound calls show — "mobile"
            // (the SIM's number, default) or "business" (the DDI). Omitted
            // or unknown → the stored choice (resolveOutboundCli); never
            // fails the request.
            'outbound_cli'         => 'nullable|string|max:20',
            // AI + recording disclosure (voxragtm#83): the assistant's opening
            // line (voxraweb builds it from the tenant's wording choice) and
            // whether Telnyx records the call audio. Omitted → keep current.
            'greeting'             => 'nullable|string|max:500',
            'recording_enabled'    => 'nullable|boolean',
            // Record owner-answered calls (voxragtm#157). Omitted → keep.
            'owner_call_recording' => 'nullable|boolean',
            // The voice the tenant picked in voxraweb Settings (voxraweb#110):
            // a Telnyx voice id. Omitted or malformed → keep the agent's
            // current voice (UK_VOICE for a new agent); see resolveVoice().
            'voice_id'             => 'nullable|string|max:80',
        ]);

        $tenantId = $data['tenant_id'];
        $completeMode = $request->boolean('complete_mode', false);
        $lineAi = self::resolveLineAi($request->boolean('line_ai', false), $completeMode);
        $lineMode = self::resolveLineMode($request->boolean('line_mode', false), $completeMode, $lineAi);
        $mode = VoxraRoutingState::modeFor($lineMode, $lineAi, $completeMode);
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

        // Routing inputs (voxragtm#162): explicit request values, else the
        // stored state, else what the DID does today (tenants provisioned
        // before the state existed). Stored before the agent upsert — the
        // 9250 dialplan, *9 bind and Telnyx tool sync read the mode.
        $numberSvc = app(ProvisionNumberService::class);
        $lineSvc = app(ProvisionLineService::class);
        $previous = VoxraRoutingState::load($domain->domain_uuid);
        $currentActions = null;
        $followMeMobile = null;
        if (! $completeMode) {
            try {
                $currentActions = $numberSvc->findReceptionDestination($domain)?->destination_actions;
                if ($lineMode || $lineAi) {
                    $followMeMobile = $lineSvc->currentFollowMeMobile($domain);
                }
            } catch (\Throwable $e) {
                logger('Voxra current routing lookup failed for ' . $domain->domain_name . ': ' . $e->getMessage());
            }
        }
        $routing = self::resolveRoutingInputs(
            self::routingInput($request),
            $mode,
            $previous,
            $currentActions,
            $followMeMobile,
        );
        $suspended = $routing['suspended'];
        $timeout = $routing['timeout'];
        // Validated (+E.164, not a DID on this PBX) — null when unusable.
        $ownerMobile = $completeMode ? null
            : $numberSvc->resolveRingFirstMobile($domain, true, $routing['owner_mobile']);
        $agentEnabled = self::resolveAgentEnabled($request->boolean('agent_enabled', true), $lineMode, $suspended);
        $ownerRecording = self::resolveOwnerCallRecording(
            $request->input('owner_call_recording') !== null ? $request->boolean('owner_call_recording') : null,
            $previous,
            $mode,
        );
        $outboundCli = \App\Services\ProvisionCompleteService::resolveOutboundCli(
            is_string($data['outbound_cli'] ?? null) ? $data['outbound_cli'] : null,
            $previous,
        );

        try {
            VoxraRoutingState::save($domain->domain_uuid, [
                'mode'                 => $mode,
                'ring_first'           => $routing['ring_first'],
                'owner_mobile'         => $completeMode ? ($previous['owner_mobile'] ?? null) : $ownerMobile,
                'ring_first_timeout'   => $timeout,
                'service_suspended'    => $suspended,
                'owner_call_recording' => $ownerRecording,
                'outbound_cli'         => $outboundCli,
            ]);
        } catch (\Throwable $e) {
            logger()->error('Voxra routing state save failed for ' . $domain->domain_name . ': ' . $e->getMessage());
        }

        // Idempotent upsert of the reception agent on the domain. A disabled
        // agent disables its dialplans, so inbound calls to the DID stop
        // reaching the assistant.
        $recording = $request->has('recording_enabled') ? $request->boolean('recording_enabled') : null;
        $existing = AiAgent::reception()->forDomain($domain->domain_uuid)->first();
        $inputs = $this->receptionAgentInputs(
            $businessName,
            $agentEnabled,
            self::resolveVoice($data['voice_id'] ?? null, $existing?->voice_id),
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

        // Voxra Line (voxragtm#25) and Line+AI's AI-off fallback
        // (voxragtm#163): idempotently provision the follow-me line extension
        // + voicemail box (branded TTS greeting, voxragtm#110). Missing/
        // unusable owner_mobile still gets the extension — it becomes a
        // straight-to-voicemail line. Every other tenant gets just the
        // voicemail box — where the DID goes when the AI is off
        // (voxragtm#164), and Complete's eSIM failover target then.
        $line = null;
        if ($lineMode || $lineAi) {
            $line = $lineSvc->ensureLineExtension($domain, $ownerMobile, $businessName, $timeout);
        } else {
            try {
                $lineSvc->ensureVoicemailBox($domain, $businessName);
            } catch (\Throwable $e) {
                logger()->error('Voxra voicemail box provisioning failed for ' . $domain->domain_name . ': ' . $e->getMessage());
            }
        }

        // Owner-call recording (voxragtm#157): the announcement is what makes
        // recording allowed, so it goes in first and a failure leaves
        // recording off. The prompt expression plays the shared TTS file when
        // this node has it, else a stock phrase.
        $recorder = app(VoxraOwnerCallRecording::class);
        $recordPrompt = null;
        if ($ownerRecording) {
            try {
                $recordPrompt = $recorder->playbackExpression();
            } catch (\Throwable $e) {
                logger()->error('Voxra owner-call recording prompt failed for ' . $domain->domain_name . ': ' . $e->getMessage());
            }
        }

        // Voxra Complete (voxragtm#45): the mobile extension is the whole
        // point of the plan — its failure fails the request (voxraweb
        // retries the idempotent call). Caller-ID + MSISDN routing are
        // best-effort follow-ups on the same extension.
        $mobile = null;
        $outboundCallerId = null;
        if ($completeMode) {
            $completeSvc = app(\App\Services\ProvisionCompleteService::class);
            $recordMobile = false;
            try {
                $recorder->applyMobileAnnouncement($domain, $recordPrompt);
                $recordMobile = $recordPrompt !== null;
            } catch (\Throwable $e) {
                logger()->error('Voxra owner-call announcement dialplan failed for ' . $domain->domain_name . ' (recording stays off): ' . $e->getMessage());
            }
            try {
                $mobile = $completeSvc->ensureMobileExtension(
                    $domain,
                    $businessName,
                    $request->boolean('rotate_sip_password', false),
                    $recordMobile,
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

            if (! empty($data['sim_msisdn'])) {
                try {
                    $completeSvc->ensureMsisdnDestination($domain, $data['sim_msisdn']);
                } catch (\Throwable $e) {
                    logger()->error('Voxra Complete MSISDN routing failed for ' . $domain->domain_name . ': ' . $e->getMessage());
                }
            }

            // Outbound caller-ID: the SIM's mobile number by default, the
            // DDI when the tenant chose it or the MSISDN isn't known yet. A
            // request without sim_msisdn uses the MSISDN destination row.
            try {
                $cliExtension = $completeSvc->applyCallerId(
                    $domain,
                    $data['did'] ?? null,
                    $businessName,
                    $data['sim_msisdn'] ?? null,
                    $outboundCli,
                );
                $outboundCallerId = $cliExtension ? '+' . $cliExtension->getRawOriginal('outbound_caller_id_number') : null;
            } catch (\Throwable $e) {
                logger()->error('Voxra Complete caller-ID failed for ' . $domain->domain_name . ': ' . $e->getMessage());
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

        // Owner-call recordings → voxraweb /api/pbx/owner-recording (the
        // stock recording.available webhook, per domain). Switched off, not
        // deleted, when the tenant opts out. Best-effort.
        try {
            $recorder->applyWebhook($domain, $recordPrompt !== null);
        } catch (\Throwable $e) {
            logger()->error('Voxra owner-call recording webhook failed for ' . $domain->domain_name . ': ' . $e->getMessage());
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

        // DID routing (voxragtm#23/#25/#162/#164/#173) — one decision table
        // for every mode, rewritten on every provision call so a settings
        // toggle, plan change, AI pause or suspension in voxraweb just
        // re-provisions. Complete: the DID is iqportal's (routed to the eSIM
        // extension), never touched here.
        $routingKind = null;
        if (! $completeMode) {
            try {
                $announcement = $suspended ? app(VoxraSuspendedAnnouncement::class)->playbackTarget() : null;
                $ringMobile = self::ringFirstMobileFor($mode, $routing['ring_first'], $ownerMobile);
                $did = $numberSvc->resolveDidRouting($domain, $agent, $mode, $agentEnabled, $ringMobile, $timeout, $suspended, $announcement, $recordPrompt);
                $routingKind = $did['kind'];
                $numberSvc->applyDidActions($domain, $did['actions']);
            } catch (\Throwable $e) {
                logger()->error('Voxra DID routing failed for ' . $domain->domain_name . ': ' . $e->getMessage());
            }
        }

        return response()->json([
            'ok'                  => true,
            'domain_uuid'         => $domain->domain_uuid,
            'domain_name'         => $domain->domain_name,
            'agent_extension'     => $agent->agent_extension,
            'feature_code'        => $agent->feature_code,
            'telnyx_assistant_id' => $agent->telnyx_assistant_id,
            'number'              => $number,
            // Plan mode + routing actually applied (voxragtm#162):
            // line | line_ai | pro | complete.
            'mode'                => $mode,
            'agent_enabled'       => $agentEnabled,
            'service_suspended'   => $suspended,
            // Seconds the owner's mobile rings first (null for Complete).
            'ring_first_timeout'  => $completeMode ? null : $timeout,
            // What the Voxra DID does: ring_first_ai | ai | line_voicemail |
            // ring_first_voicemail | voicemail | suspended (null: Complete
            // or routing failed).
            'routing'             => $routingKind,
            // Owner-answered calls recorded (voxragtm#157): the stored
            // opt-in, and whether the announcement is in place to allow it.
            'owner_call_recording' => $ownerRecording && $recordPrompt !== null,
            'voicemail_box'       => ProvisionLineService::LINE_EXTENSION,
            'line_extension'      => $line['extension'] ?? null,
            // true when owner_mobile was missing/unusable: the line answers
            // straight to voicemail until a valid mobile is re-provisioned
            'line_straight_to_voicemail' => $line['straight_to_voicemail'] ?? null,
            // Voxra Complete: the SIM's registration credentials. Internal
            // (HMAC) only — never surfaced on the V1 API.
            // Complete: the eSIM's outbound caller-ID choice and the number
            // it now presents (+E.164; null when not applied).
            'outbound_cli'        => $completeMode ? $outboundCli : null,
            'outbound_caller_id'  => $outboundCallerId,
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

    /** Owner-call recording (voxragtm#157): the request's value when sent,
     *  else the stored one, else off. Never on Line v1 (its follow-me leg
     *  isn't a bridge this PBX can announce/record). */
    public static function resolveOwnerCallRecording(?bool $requested, array $previous, string $mode): bool
    {
        if ($mode === VoxraRoutingState::MODE_LINE) {
            return false;
        }

        return $requested ?? (bool) ($previous['owner_call_recording'] ?? false);
    }

    /** Complete mode wins over line mode: a Complete tenant's handset IS the
     *  extension, so the Line follow-me/loopback machinery must stay off.
     *  Line+AI wins over Line v1 (voxraweb sends both to stay compatible
     *  with an older PBX). */
    public static function resolveLineMode(bool $lineMode, bool $completeMode, bool $lineAi = false): bool
    {
        return $lineMode && ! $completeMode && ! $lineAi;
    }

    /** Line+AI (voxragtm#163) — never with Complete. */
    public static function resolveLineAi(bool $lineAi, bool $completeMode): bool
    {
        return $lineAi && ! $completeMode;
    }

    /** Line v1 forces the agent off: it exists for the Line → Start upgrade
     *  but must not answer (voxragtm#25). Line+AI keeps it (pass its
     *  lineMode as false). A suspended number never reaches the AI. */
    public static function resolveAgentEnabled(bool $agentEnabled, bool $lineMode, bool $suspended = false): bool
    {
        return $agentEnabled && ! $lineMode && ! $suspended;
    }

    /** The mobile the DID rings first, or null. Line+AI always rings the
     *  owner's mobile first (ring_mobile_first is ignored — voxraweb sends it
     *  only for older PBXs); Pro only with ring-first on; Line v1 never
     *  (its follow-me rings the mobile). */
    public static function ringFirstMobileFor(string $mode, bool $ringFirst, ?string $ownerMobile): ?string
    {
        return match ($mode) {
            VoxraRoutingState::MODE_LINE_AI => $ownerMobile,
            VoxraRoutingState::MODE_PRO     => $ringFirst ? $ownerMobile : null,
            default                         => null,
        };
    }

    /** The routing fields present in the request (absent keys are left out
     *  so resolveRoutingInputs can keep their stored values). */
    private static function routingInput(Request $request): array
    {
        $in = [];
        if ($request->has('owner_mobile')) {
            $in['owner_mobile'] = $request->input('owner_mobile');
        }
        foreach (['ring_mobile_first', 'service_suspended'] as $key) {
            if ($request->input($key) !== null) {
                $in[$key] = $request->boolean($key);
            }
        }
        if ($request->input('ring_first_timeout') !== null) {
            $in['ring_first_timeout'] = (int) $request->input('ring_first_timeout');
        }

        return $in;
    }

    /**
     * Resolve the routing inputs (voxragtm#162). Each field: the request's
     * value when sent, else the stored state, else what the DID does today
     * (tenants routed before the state was stored). Pure.
     *
     *  - owner_mobile (raw; validated by the caller): Line modes also fall
     *    back to the 9260 follow-me destination.
     *  - ring_first: the Pro ring-first preference (ring_mobile_first on a
     *    Pro call). Line calls leave it as stored — Line+AI always rings the
     *    mobile first and Line v1 rings it through follow-me.
     *  - timeout: the stored value only while the mode is unchanged (a plan
     *    switch takes the new mode's default: 25 s Line, 20 s Pro).
     *  - suspended: kept until sent false.
     *
     * @return array{owner_mobile: ?string, ring_first: bool, timeout: int, suspended: bool}
     */
    public static function resolveRoutingInputs(array $input, string $mode, array $previous, ?string $currentActions, ?string $followMeMobile): array
    {
        $didMobile = ProvisionNumberService::ringFirstMobileIn($currentActions);
        $lineModes = [VoxraRoutingState::MODE_LINE, VoxraRoutingState::MODE_LINE_AI];

        if (array_key_exists('owner_mobile', $input)) {
            $ownerMobile = $input['owner_mobile'];
        } else {
            $ownerMobile = $previous['owner_mobile']
                ?? $didMobile
                ?? (in_array($mode, $lineModes, true) ? $followMeMobile : null);
        }

        if (in_array($mode, $lineModes, true)) {
            // Line always rings the mobile; ring_mobile_first on a Line call
            // is only there for older PBXs and must not become the Pro
            // preference a later upgrade inherits.
            $ringFirst = (bool) ($previous['ring_first'] ?? false);
        } elseif (array_key_exists('ring_mobile_first', $input)) {
            $ringFirst = (bool) $input['ring_mobile_first'];
        } else {
            $ringFirst = (bool) ($previous['ring_first'] ?? ($didMobile !== null));
        }

        if (isset($input['ring_first_timeout'])) {
            $timeout = (int) $input['ring_first_timeout'];
        } elseif (($previous['mode'] ?? null) === $mode && isset($previous['ring_first_timeout'])) {
            $timeout = (int) $previous['ring_first_timeout'];
        } else {
            $timeout = ProvisionNumberService::defaultRingFirstTimeout($mode);
        }

        $suspended = array_key_exists('service_suspended', $input)
            ? (bool) $input['service_suspended']
            : (bool) ($previous['service_suspended'] ?? ProvisionNumberService::isSuspendedRouting($currentActions));

        return [
            'owner_mobile' => $ownerMobile !== null && $ownerMobile !== '' ? (string) $ownerMobile : null,
            'ring_first'   => $ringFirst,
            'timeout'      => ProvisionNumberService::clampRingFirstTimeout($timeout),
            'suspended'    => $suspended,
        ];
    }

    /**
     * The reception agent's Telnyx voice (voxraweb#110). A well-formed Telnyx
     * Ultra voice id from voxraweb Settings wins; otherwise the agent keeps
     * the voice it has, so a re-provision that omits voice_id (answering
     * sync, billing flips, older voxraweb) never resets it; a new agent gets
     * UK_VOICE. Before this the voice was always UK_VOICE, so the Settings
     * picker had no effect.
     */
    public static function resolveVoice(?string $requested, ?string $current): string
    {
        $pattern = '/^Telnyx\.Ultra\.[0-9a-f-]{36}$/';
        $requested = $requested !== null ? trim($requested) : null;
        if ($requested !== null && preg_match($pattern, $requested) === 1) {
            return $requested;
        }
        $current = $current !== null ? trim($current) : null;
        if ($current !== null && preg_match($pattern, $current) === 1) {
            return $current;
        }
        return self::UK_VOICE;
    }

    /** Upsert inputs for the reception agent (agent_enabled is stored as the
     *  strings 'true'/'false' — FusionPBX toggle convention). */
    public function receptionAgentInputs(string $businessName, bool $agentEnabled, ?string $voiceId = null): array
    {
        return [
            'agent_name'      => $businessName . ' Reception',
            'provider'        => 'telnyx',
            'model'           => 'moonshotai/Kimi-K2.6',
            'telnyx_voice_id' => $voiceId ?: self::UK_VOICE,
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
