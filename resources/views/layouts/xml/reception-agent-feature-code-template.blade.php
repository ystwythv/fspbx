<extension name="{{ $agent->agent_name }} reception *9" continue="{{ $dialplan_continue ?? 'false' }}" uuid="{{ $agent->dialplan_uuid }}">
    <condition field="destination_number" expression="^{{ $feature_code }}$">
        <action application="answer" data=""/>
        <action application="set" data="hangup_after_bridge=false"/>
        <action application="set" data="voxra_conf_name=voxra_recept_${uuid}"/>
        <action application="set" data="voxra_domain_uuid={{ $agent->domain_uuid }}"/>
        <action application="set" data="voxra_originator_extension=${caller_id_number}"/>
        <action application="set" data="voxra_originator_uuid=${uuid}"/>
        <action application="set" data="voxra_peer_uuid=${bridge_uuid}"/>

        <!-- Keep the peer leg alive across the bridge break (otherwise its
             hangup_after_bridge drops it the moment we leave the bridge to join
             the conference). The summon Lua then pulls the peer into the
             conference. -->
        <action application="eval" data="${uuid_setvar(${bridge_uuid} hangup_after_bridge false)}"/>

        <!-- Spawn the agent leg into the conference AND pull the peer in (both via
             ESL inside the summon). The peer is moved with a real XML-dialplan
             transfer to the voxra_recept_join extension — mod_dialplan_inline
             isn't built on voxra so the 'conference:...inline' and '&conference()'
             transfer forms silently no-op. The Lua call is synchronous so both
             are in flight by the time we join ourselves below. -->
        <action application="lua" data="voxra_summon_reception_agent.lua ${voxra_conf_name} ${voxra_domain_uuid} ${voxra_originator_uuid} ${voxra_peer_uuid} ${voxra_originator_extension}"/>

        <!-- Take the originator (this leg) into the conference too. The
             voxra_recept profile is silent (no alone-sound / MOH / enter-exit
             beeps) so the brief moment before the conference fills is quiet, and
             both parties can keep talking. No endconf flag: a warm transfer moves
             the peer out and kills the originator/agent, and endconf would tear
             the whole conference down mid-transfer. FreeSWITCH auto-destroys the
             conference once it empties. -->
        <action application="conference" data="${voxra_conf_name}@@voxra_recept"/>
    </condition>
</extension>
