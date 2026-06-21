<extension name="{{ $agent->agent_name }} reception *9" continue="{{ $dialplan_continue ?? 'false' }}" uuid="{{ $agent->dialplan_uuid }}">
    <condition field="destination_number" expression="^{{ $feature_code }}$">
        <action application="set" data="voxra_conf_name=voxra_recept_${uuid}"/>
        <action application="set" data="voxra_domain_uuid={{ $agent->domain_uuid }}"/>
        <action application="set" data="voxra_originator_extension=${caller_id_number}"/>
        <action application="set" data="voxra_originator_uuid=${uuid}"/>
        <action application="set" data="voxra_peer_uuid=${bridge_uuid}"/>

        <!-- Originate the AI agent leg into the (silent) voxra_recept conference. -->
        <action application="lua" data="lua/voxra_summon_reception_agent.lua ${voxra_conf_name} ${voxra_domain_uuid} ${voxra_originator_uuid} ${voxra_peer_uuid} ${voxra_originator_extension}"/>

        <!-- Move BOTH legs of the live call into the conference in one shot.
             bind_meta_app runs this *9 extension as a SUBROUTINE while the A/B
             bridge stays live underneath, so we must not just `conference` here
             (that nests a conference under the still-bridged call — the peer then
             loses audio / wedges in CS_ROUTING when moved separately). `transfer
             -both` dissolves the bridge cleanly and routes BOTH parties to the
             voxra_recept_join extension, which drops them into the same room. -->
        <action application="transfer" data="-both ${voxra_conf_name} XML ${domain_name}"/>
    </condition>
</extension>
