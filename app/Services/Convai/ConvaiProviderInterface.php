<?php

namespace App\Services\Convai;

use App\Models\AiAgent;

/**
 * Common surface for conversational-AI agent providers (ElevenLabs, Telnyx).
 *
 * Providers own the remote-platform lifecycle of an agent; the controller
 * owns the local row, the dialplan, and anything provider-specific beyond
 * this interface (e.g. ElevenLabs knowledge base / reception tools).
 */
interface ConvaiProviderInterface
{
    /**
     * Machine name, matches the `provider` column on v_ai_agents.
     */
    public function name(): string;

    /**
     * Create the agent on the remote platform plus any call-path resources
     * (SIP trunk number, UAC connection, attach extension, ...).
     *
     * @param  array  $inputs  validated request inputs
     * @return array  attributes to persist on the AiAgent row
     */
    public function provisionAgent(array $inputs): array;

    /**
     * Push updated settings to the remote platform. Best-effort; throw on
     * hard failures only.
     */
    public function updateAgent(AiAgent $agent, array $inputs): void;

    /**
     * Tear down all remote resources for the agent.
     */
    public function deleteAgent(AiAgent $agent): void;

    /**
     * Blade view that renders the FreeSWITCH dialplan for this provider.
     */
    public function dialplanView(): string;

    /**
     * Extra data the dialplan view needs beyond ['agent' => ...].
     */
    public function dialplanData(AiAgent $agent): array;

    /**
     * FreeSWITCH dial string for ESL-originating the agent leg straight into a
     * conference (used by the reception-agent summon). May be a `|` failover
     * list. Throw if the agent isn't provisioned for this provider.
     */
    public function summonEndpoint(AiAgent $agent): string;
}
