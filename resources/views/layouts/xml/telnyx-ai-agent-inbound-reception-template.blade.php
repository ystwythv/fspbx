<extension name="{{ $agent->agent_name }} inbound reception" continue="{{ $dialplan_continue ?? 'false' }}" uuid="{{ $dialplan_uuid }}">
    <condition field="destination_number" expression="^{{ $agent->agent_extension }}$">
        <action application="ring_ready" data="" />
        <action application="answer" data="" />
        <action application="sleep" data="1000" />
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
        <action application="bridge" data="{{ $voxraHeaders }}sofia/external/sip:{{ 'agent@' . $agent->telnyx_assistant_id }}.sip.telnyx.com" />
    </condition>
</extension>
