<extension name="{{ $agent->agent_name }} reception *9" continue="{{ $dialplan_continue ?? 'false' }}" uuid="{{ $agent->dialplan_uuid }}">
    <condition field="destination_number" expression="^{{ $feature_code }}$">
        <action application="answer" data=""/>
        <action application="set" data="hangup_after_bridge=false"/>
        <action application="set" data="voxra_conf_name=voxra_recept_${uuid}"/>
        <action application="set" data="voxra_domain_uuid={{ $agent->domain_uuid }}"/>
        <action application="set" data="voxra_originator_extension=${caller_id_number}"/>
        <action application="set" data="voxra_originator_uuid=${uuid}"/>
        <action application="set" data="voxra_peer_uuid=${bridge_uuid}"/>

        <!-- Silence MOH + the "you are the only person" announcement + enter/exit
             beeps for this leg, so the brief moment before the conference fills is
             silent rather than music. Both parties stay in and can keep talking. -->
        <action application="set" data="conference_moh_sound=silence_stream://-1"/>
        <action application="set" data="conference_enter_sound=silence_stream://1"/>
        <action application="set" data="conference_exit_sound=silence_stream://1"/>

        <!-- Prep the peer leg: keep it alive across the bridge break (else
             hangup_after_bridge drops it) and silence its MOH. The summon Lua
             pulls the peer into the conference via ESL (&conference) because
             mod_dialplan_inline isn't built on voxra, so a dialplan
             'conference:...inline' transfer just stalls the peer in CS_ROUTING. -->
        <action application="eval" data="${uuid_setvar(${bridge_uuid} hangup_after_bridge false)}"/>
        <action application="eval" data="${uuid_setvar(${bridge_uuid} conference_moh_sound silence_stream://-1)}"/>
        <action application="eval" data="${uuid_setvar(${bridge_uuid} conference_enter_sound silence_stream://1)}"/>
        <action application="eval" data="${uuid_setvar(${bridge_uuid} conference_exit_sound silence_stream://1)}"/>

        <!-- Spawn the agent leg into the conference AND pull the peer in (both via
             ESL inside the summon). The Lua call is synchronous so both are in
             flight by the time we join ourselves below. -->
        <action application="lua" data="voxra_summon_reception_agent.lua ${voxra_conf_name} ${voxra_domain_uuid} ${voxra_originator_uuid} ${voxra_peer_uuid} ${voxra_originator_extension}"/>

        <!-- Take the originator (this leg) into the conference too. -->
        <action application="conference" data="${voxra_conf_name}@@default+flags{endconf}"/>
    </condition>
</extension>
