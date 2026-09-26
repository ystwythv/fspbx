<extension name="{{ $agent->agent_name }} inbound reception" continue="{{ $dialplan_continue ?? 'false' }}" uuid="{{ $dialplan_uuid }}">
    <condition field="destination_number" expression="^{{ $agent->agent_extension }}$">
        {{-- No answer/sleep before the bridge: the caller keeps hearing ringing
             until the Telnyx assistant answers (~1.5 s before its greeting),
             instead of a second of dead air plus Telnyx's setup time. If
             Telnyx stalls, the bounded attempts below end the call unanswered
             (NO_ANSWER) — the caller heard ringing, never silence. --}}
        <action application="ring_ready" data="" />
        <action application="set" data="hangup_after_bridge=true" />
        {{-- a failed SIP-attach bridge must fall through to the subdomain bridge --}}
        <action application="set" data="continue_on_fail=true" />
        <action application="set" data="absolute_codec_string=PCMU,PCMA" />
        <action application="set" data="ringback=$${uk-ring}" />
        <action application="set" data="transfer_ringback=$${uk-ring}" />
        <action application="set" data="ignore_early_media=true" />
        <action application="set" data="ai_agent_uuid={{ $agent->ai_agent_uuid }}" />

        {{-- Reception session context. The inbound call's own uuid is the
             conversation id. Telnyx surfaces these X-Voxra-* INVITE headers as
             voxra_* fields on assistant.initialization, where dynamicVariables()
             bootstraps the Redis session so the qualify/book tools can resolve
             the tenant (voxragtm#23/#28/#29). --}}
        <action application="set" data="voxra_domain_uuid={{ $agent->domain_uuid }}" />
        <action application="set" data="voxra_conversation_id=${uuid}" />
        <action application="set" data="voxra_caller_number=${caller_id_number}" />
@php
    $voxraHeaders = '{sip_h_X-Voxra-Conversation-Id=${uuid},sip_h_X-Voxra-Domain-Uuid=' . $agent->domain_uuid . ',sip_h_X-Voxra-Caller-Number=${caller_id_number}}';
@endphp
@if (!empty($agent->telnyx_attach_extension) && !empty($attach_domain))
        {{-- SIP attach: Telnyx registers this extension into the attach domain.
             Falls through to the public assistant subdomain if unregistered. --}}
        <action application="bridge" data="{{ $voxraHeaders }}user/{{ $agent->telnyx_attach_extension . '@' . $attach_domain }}" />
@endif
        {{-- Telnyx normally answers the assistant leg in ~2 s. On 26 Sep
             06:53-07:20 UTC Telnyx's AI platform stalled session starts for
             16-40 s (or for good): 180 Ringing, then nothing, and callers sat
             in ringback until they gave up (voxragtm#153). Bound each attempt;
             a fresh INVITE starts a fresh Telnyx session, so retry once. --}}
        <action application="bridge" data="{{ $voxraHeaders }}[leg_timeout={{ $telnyx_leg_timeout ?? 10 }}]sofia/external/sip:{{ 'agent@' . $agent->telnyx_assistant_id }}.sip.telnyx.com" />
        <action application="log" data="WARNING Voxra: Telnyx assistant {{ $agent->telnyx_assistant_id }} did not answer (${originate_disposition}); retrying once" />
        <action application="bridge" data="{{ $voxraHeaders }}[leg_timeout={{ $telnyx_leg_timeout ?? 10 }}]sofia/external/sip:{{ 'agent@' . $agent->telnyx_assistant_id }}.sip.telnyx.com" />
        {{-- Still no AI: end the call as NO_ANSWER rather than leave the caller
             ringing. The CDR resolves to no_answer, which Voxra treats as a
             missed call (missed-call text-back, no AI minutes). --}}
        <action application="log" data="ERR Voxra: Telnyx assistant {{ $agent->telnyx_assistant_id }} did not answer twice (${originate_disposition}); ending as missed" />
        <action application="set" data="voxra_ai_unavailable=true" />
        <action application="hangup" data="NO_ANSWER" />
    </condition>
</extension>
