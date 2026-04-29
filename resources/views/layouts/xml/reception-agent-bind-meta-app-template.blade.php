<extension name="reception_agent_bind_meta_app" continue="true" uuid="{{ $agent->bind_dialplan_uuid }}">
    <condition>
        {{-- Bind *9 mid-call: when a user presses *9 during an active bridge,
             FreeSWITCH executes the *9 feature-code dialplan via execute_extension.
             listen=ab (either leg's DTMF triggers), respond=s (action runs on the
             leg that pressed). Falls through (continue=true) so this never breaks
             normal call routing. --}}
        <action application="set" data="bind_meta_app=9 ab s execute_extension::*9 XML ${domain_name}"/>
    </condition>
</extension>
