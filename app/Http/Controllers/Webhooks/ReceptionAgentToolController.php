<?php

namespace App\Http\Controllers\Webhooks;

use App\Http\Controllers\Controller;
use App\Services\ReceptionAgent\ReceptionAgentSummonService;
use App\Services\ReceptionAgent\ReceptionAgentToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Single-endpoint dispatcher for tool calls from the ElevenLabs Conversational
 * AI reception agent. The agent's tool definitions all point here; the
 * `tool_name` field in the body picks which method runs. Conversation context
 * (conf_name, peer_uuid, agent_uuid, etc.) is hydrated from Redis using the
 * `conversation_id` we stash via SIP custom headers at summon time.
 *
 * Tool-call latency budget is ~1-2s end-to-end so we keep this lean: signature
 * verified by middleware, no session/auth, dispatch in-process via ESL.
 */
class ReceptionAgentToolController extends Controller
{
    public function __construct(private ReceptionAgentToolService $tools)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $tool = (string) $request->input('tool_name', $request->input('tool', ''));
        if ($tool === '') {
            return response()->json(['ok' => false, 'error' => 'tool_name required'], 422);
        }

        $convId = (string) $request->input('conversation_id', $request->input('dynamic_variables.conversation_id', ''));
        if ($convId === '') {
            // ElevenLabs nests dynamic variables under a different shape depending on version.
            $convId = (string) data_get($request->all(), 'dynamic_variables.conversation_id', '');
        }

        $args = (array) $request->input('parameters', $request->input('arguments', []));

        $session = null;
        if (!in_array($tool, ['get_time_in_city', 'get_weather'], true)) {
            if ($convId === '') {
                return response()->json(['ok' => false, 'error' => 'conversation_id required'], 422);
            }
            $session = ReceptionAgentSummonService::loadSession($convId);
            if (!$session) {
                return response()->json(['ok' => false, 'error' => 'session not found'], 404);
            }
        }

        try {
            $result = match ($tool) {
                'lookup_user'        => $this->tools->lookupUser($session, (string) ($args['query'] ?? '')),
                'transfer_call'      => $this->tools->transferCall($session, (string) ($args['extension'] ?? '')),
                'announced_transfer' => $this->tools->announcedTransfer($session, (string) ($args['extension'] ?? '')),
                'park_call'          => $this->tools->parkCall($session),
                'bring_back'         => $this->tools->bringBack($session, (string) ($args['slot'] ?? '')),
                'three_way_add'      => $this->tools->threeWayAdd($session, (string) ($args['extension'] ?? '')),
                'complete_and_exit'  => $this->tools->completeAndExit($session, $args['message'] ?? null),
                'get_time_in_city'   => $this->tools->getTimeInCity((string) ($args['city'] ?? '')),
                'get_weather'        => $this->tools->getWeather((string) ($args['city'] ?? '')),
                default              => ['ok' => false, 'error' => "unknown tool: {$tool}"],
            };

            return response()->json($result);
        } catch (Throwable $e) {
            logger()->error("reception-agent tool {$tool} failed: " . $e->getMessage());
            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }
    }
}
