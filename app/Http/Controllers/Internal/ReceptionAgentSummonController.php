<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Domain;
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
     * Wakeword path: the openWakeWord service POSTs here when it detects the wake
     * phrase on a forked call leg. There's no dialplan involved (unlike *9), so we
     * do the whole merge over ESL: stop the audio fork, originate the AI agent into
     * a fresh conference, then `uuid_transfer -both` the live call into it. Without
     * a bind_meta_app subroutine in the way, -both dissolves the bridge cleanly and
     * routes both parties into the room.
     */
    public function summonByUuid(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'uuid'        => 'required|string|max:64',
            'domain_uuid' => 'required|uuid',
            'ext'         => 'nullable|string|max:32',
        ]);

        $uuid = $payload['uuid'];

        $domain = Domain::where('domain_uuid', $payload['domain_uuid'])->first();
        if (!$domain) {
            return response()->json(['ok' => false, 'error' => 'unknown domain'], 404);
        }
        $domainName = $domain->domain_name;

        // The forked leg is the wakeword-enabled extension; its bridge partner is
        // the other party on the live call.
        $peerUuid = (string) ($this->esl->getVar($uuid, 'bridge_uuid') ?? '');
        $ext = $payload['ext'] ?: (string) ($this->esl->getVar($uuid, 'caller_id_number') ?? '');
        $confName = 'voxra_recept_' . $uuid;

        try {
            // Originate the AI agent leg into the (silent) conference. We leave the
            // audio fork running so the user can re-summon ("hey jarvis" again)
            // later in the same call; the cooldown in the wakeword service prevents
            // double-firing on one utterance, and this endpoint is idempotent on
            // the uuid-derived conference name.
            $result = $this->summonService->summon([
                'conf_name'            => $confName,
                'domain_uuid'          => $payload['domain_uuid'],
                'originator_uuid'      => $uuid,
                'peer_uuid'            => $peerUuid,
                'originator_extension' => $ext,
            ]);

            // First summon: the leg is still bridged to the other party, so move
            // BOTH legs into the conference. On a re-summon the leg is already a
            // conference member (no bridge_uuid) — skip the transfer so we don't
            // blip them out and back in; the agent simply rejoins the room.
            if ($peerUuid !== '') {
                $this->esl->executeCommand(sprintf(
                    'uuid_transfer %s -both %s XML %s',
                    $uuid, $confName, $domainName
                ));
            }

            return response()->json(['ok' => true] + $result);
        } catch (Throwable $e) {
            logger()->error('reception-agent summon-by-uuid failed: ' . $e->getMessage());
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
                $hook = sprintf('lua lua/voxra_unmute_member.lua %s %d', $confName, $peerMemberId);
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
