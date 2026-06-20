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

        <!-- Pull the peer leg into a fresh conference. ${bridge_uuid} is set by
             FreeSWITCH for the duration of (and persists past) the bridge. Keep the
             peer alive across the bridge break (else hangup_after_bridge drops it
             before it lands in the conference) and silence its MOH too. -->
        <action application="eval" data="${uuid_setvar(${bridge_uuid} hangup_after_bridge false)}"/>
        <action application="eval" data="${uuid_setvar(${bridge_uuid} conference_moh_sound silence_stream://-1)}"/>
        <action application="eval" data="${uuid_setvar(${bridge_uuid} conference_enter_sound silence_stream://1)}"/>
        <action application="eval" data="${uuid_setvar(${bridge_uuid} conference_exit_sound silence_stream://1)}"/>
        <action application="eval" data="${uuid_transfer(${bridge_uuid} 'conference:${voxra_conf_name}@@default' inline)}"/>

        <!-- Spawn the ElevenLabs agent leg into the same conference. The Lua
             call is synchronous so the originate is in flight by the time we
             transfer ourselves. -->
        <action application="lua" data="voxra_summon_reception_agent.lua ${voxra_conf_name} ${voxra_domain_uuid} ${voxra_originator_uuid} ${voxra_peer_uuid} ${voxra_originator_extension}"/>

        <!-- Take the originator (this leg) into the conference too. -->
        <action application="conference" data="${voxra_conf_name}@@default+flags{endconf}"/>
    </condition>
</extension>
