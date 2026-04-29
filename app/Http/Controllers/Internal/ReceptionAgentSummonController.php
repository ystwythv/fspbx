<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\FreeswitchEslService;
use App\Services\ReceptionAgent\ReceptionAgentSummonService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class ReceptionAgentSummonController extends Controller
{
    public function __construct(
        private ReceptionAgentSummonService $summonService,
        private FreeswitchEslService $esl,
    ) {
    }

    public function summon(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'conf_name'             => 'required|string|max:128',
            'domain_uuid'           => 'required|uuid',
            'originator_uuid'       => 'required|string|max:64',
            'peer_uuid'             => 'nullable|string|max:64',
            'originator_extension'  => 'nullable|string|max:32',
        ]);

        try {
            $result = $this->summonService->summon($payload);
            return response()->json(['ok' => true] + $result);
        } catch (Throwable $e) {
            logger()->error('reception-agent summon failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Fired by voxra_announced_settle.lua via execute_on_answer when the
     * announced-transfer target leg picks up. Mutes the original peer, kills
     * the AI agent leg, and arms an api_hangup_hook on the summoner so when
     * they drop the peer gets unmuted and is left talking to the target.
     */
    public function announcedSettle(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'conversation_id' => 'required|string|max:64',
        ]);

        $session = ReceptionAgentSummonService::loadSession($payload['conversation_id']);
        if (!$session) {
            return response()->json(['ok' => false, 'error' => 'session not found'], 404);
        }

        $confName       = (string) ($session['conf_name'] ?? '');
        $peerUuid       = (string) ($session['peer_uuid'] ?? '');
        $agentUuid      = (string) ($session['agent_uuid'] ?? '');
        $originatorUuid = (string) ($session['originator_uuid'] ?? '');

        if ($confName === '' || $peerUuid === '') {
            return response()->json(['ok' => false, 'error' => 'incomplete session'], 422);
        }

        $peerMemberId = $this->esl->findMemberId($confName, $peerUuid);

        try {
            if ($peerMemberId !== null) {
                $this->esl->confMute($confName, $peerMemberId);
            }

            if ($agentUuid !== '') {
                $this->esl->killChannel($agentUuid);
            }

            if ($originatorUuid !== '' && $peerMemberId !== null) {
                $hook = sprintf('lua voxra_unmute_member.lua %s %d', $confName, $peerMemberId);
                $this->esl->setVar($originatorUuid, 'api_hangup_hook', $hook);
            }

            $session['phase']           = 'announced_settled';
            $session['peer_member_id']  = $peerMemberId;
            ReceptionAgentSummonService::saveSession($payload['conversation_id'], $session);

            return response()->json([
                'ok' => true,
                'peer_member_id' => $peerMemberId,
            ]);
        } catch (Throwable $e) {
            logger()->error('reception-agent announced-settle failed: ' . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
