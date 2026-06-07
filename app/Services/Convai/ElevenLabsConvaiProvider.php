<?php

namespace App\Services\Convai;

use App\Models\AiAgent;
use App\Services\ElevenLabsConvaiService;

/**
 * Adapter exposing the existing ElevenLabsConvaiService through the
 * provider interface. Call path: the PBX dialplan bridges to the agent's
 * ElevenLabs SIP trunk number at sip.rtc.elevenlabs.io.
 */
class ElevenLabsConvaiProvider implements ConvaiProviderInterface
{
    public function __construct(private ElevenLabsConvaiService $service)
    {
    }

    public function name(): string
    {
        return 'elevenlabs';
    }

    public function provisionAgent(array $inputs): array
    {
        $agentResponse = $this->service->createAgent(
            $inputs['agent_name'],
            $inputs['system_prompt'] ?? null,
            $inputs['first_message'] ?? null,
            $inputs['voice_id'] ?? null,
            $inputs['language'] ?? 'en',
        );

        $elevenlabsAgentId = $agentResponse['agent_id'] ?? null;
        $elevenlabsPhoneNumberId = null;

        // Create SIP trunk phone number and assign agent
        if ($elevenlabsAgentId) {
            try {
                $phoneResponse = $this->service->createSipTrunkPhoneNumber(
                    'Voxra Agent: ' . $inputs['agent_name'],
                    $inputs['agent_extension'],
                    config('services.elevenlabs.sip_allowed_addresses', []),
                );
                $elevenlabsPhoneNumberId = $phoneResponse['phone_number_id'] ?? null;

                if ($elevenlabsPhoneNumberId) {
                    $this->service->assignAgentToPhoneNumber($elevenlabsPhoneNumberId, $elevenlabsAgentId);
                }
            } catch (\Exception $e) {
                logger('ElevenLabs SIP phone number setup warning: ' . $e->getMessage());
            }
        }

        return [
            'elevenlabs_agent_id' => $elevenlabsAgentId,
            'elevenlabs_phone_number_id' => $elevenlabsPhoneNumberId,
        ];
    }

    public function updateAgent(AiAgent $agent, array $inputs): void
    {
        if (!$agent->elevenlabs_agent_id) {
            return;
        }

        try {
            $this->service->updateAgent(
                $agent->elevenlabs_agent_id,
                $inputs['agent_name'],
                $inputs['system_prompt'] ?? null,
                $inputs['first_message'] ?? null,
                $inputs['voice_id'] ?? null,
                $inputs['language'] ?? 'en',
            );
        } catch (\Exception $e) {
            logger('ElevenLabs update agent warning: ' . $e->getMessage());
        }
    }

    public function deleteAgent(AiAgent $agent): void
    {
        if ($agent->elevenlabs_phone_number_id) {
            $this->service->deletePhoneNumber($agent->elevenlabs_phone_number_id);
        }
        if ($agent->elevenlabs_agent_id) {
            $this->service->deleteAgent($agent->elevenlabs_agent_id);
        }
    }

    public function dialplanView(): string
    {
        return 'layouts.xml.ai-agent-dial-plan-template';
    }

    public function dialplanData(AiAgent $agent): array
    {
        return [];
    }
}
