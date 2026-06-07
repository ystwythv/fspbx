<extension name="{{ $agent->agent_name }}" continue="{{ $dialplan_continue }}" uuid="{{ $agent->dialplan_uuid }}">
    <condition field="destination_number" expression="^{{ $agent->agent_extension }}$">
        <action application="ring_ready" data="" />
        <action application="answer" data="" />
        <action application="sleep" data="1000" />
        <action application="set" data="hangup_after_bridge=true" />
        {{-- a failed bridge hangs the channel up by default; needed so the
             SIP-attach bridge can fall through to the subdomain bridge --}}
        <action application="set" data="continue_on_fail=true" />
        <action application="set" data="absolute_codec_string=PCMU,PCMA" />
        <action application="set" data="ringback=$${uk-ring}" />
        <action application="set" data="transfer_ringback=$${uk-ring}" />
        <action application="set" data="ignore_early_media=true" />
        <action application="set" data="ai_agent_uuid={{ $agent->ai_agent_uuid }}" />
@if (!empty($agent->telnyx_attach_extension) && !empty($attach_domain))
        {{-- SIP attach: Telnyx registers this extension into the attach domain.
             Falls through to the public assistant subdomain if unregistered.
             NB: "@" must stay inside the echo — a literal "@{{" is Blade's
             escape syntax and renders the braces verbatim. --}}
        <action application="bridge" data="user/{{ $agent->telnyx_attach_extension . '@' . $attach_domain }}" />
@endif
        <action application="bridge" data="sofia/external/sip:{{ 'agent@' . $agent->telnyx_assistant_id }}.sip.telnyx.com" />
    </condition>
</extension>
