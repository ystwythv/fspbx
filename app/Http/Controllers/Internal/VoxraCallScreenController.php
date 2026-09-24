<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\FreeswitchEslService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Voxra spam/abuse handling at the PBX (voxragtm#84).
 *
 * screen():  FreeSWITCH voxra_screen_call.lua → here → voxraweb /api/pbx/screen.
 *            Runs first in every inbound destination, before the call is routed
 *            to the AI agent, the owner's mobile (Line / ring-first) or
 *            voicemail — so one interception point covers every plan. voxraweb
 *            owns the data (per-tenant blocklist, cross-tenant spam list,
 *            velocity log in Supabase). FAILS OPEN: any error → allow.
 *
 * hangup():  voxraweb → here when the agent records a spam/abuse outcome:
 *            schedule a hard hang-up of the inbound leg (which takes the bridged
 *            Telnyx AI leg with it), so a spam call ends within a bounded time
 *            even if the model keeps talking.
 *
 * Both are HMAC-authed by VerifyVoxraInternalSignature.
 */
class VoxraCallScreenController extends Controller
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function screen(Request $request): JsonResponse
    {
        $allow = ['action' => 'allow'];
        $base = rtrim((string) config('services.voxra.app_url', ''), '/');
        $secret = (string) config('services.voxra.cdr_webhook_secret', '');
        if ($base === '' || $secret === '' || ! config('services.voxra.screen_inbound', true)) {
            return response()->json($allow);
        }

        $body = json_encode([
            'domain_uuid'        => (string) $request->input('domain_uuid', ''),
            'caller_id_number'   => substr((string) $request->input('caller_id_number', ''), 0, 64),
            'destination_number' => substr((string) $request->input('destination_number', ''), 0, 32),
            'call_uuid'          => (string) $request->input('call_uuid', ''),
        ]);
        $t = (string) time();
        $sig = 't=' . $t . ',v0=' . hash_hmac('sha256', $t . '.' . $body, $secret);

        try {
            $res = Http::timeout(2)->connectTimeout(1)
                ->withHeaders(['X-Voxra-Signature' => $sig, 'Content-Type' => 'application/json'])
                ->withBody($body, 'application/json')
                ->post($base . '/api/pbx/screen');
            if (! $res->successful()) {
                return response()->json($allow);
            }
            $action = (string) $res->json('action', 'allow');
            if ($action !== 'reject') {
                return response()->json($allow);
            }
            $prompt = (string) $res->json('prompt', 'rejected');

            return response()->json([
                'action' => 'reject',
                'reason' => preg_replace('/[^a-z_]/', '', (string) $res->json('reason', 'screened')),
                'prompt' => $prompt === 'anonymous' ? 'anonymous' : 'rejected',
            ]);
        } catch (\Throwable $e) {
            logger('Voxra screen failed open: ' . $e->getMessage());

            return response()->json($allow);
        }
    }

    public function hangup(Request $request, FreeswitchEslService $esl): JsonResponse
    {
        $uuid = (string) $request->input('call_uuid', '');
        $domain = (string) $request->input('domain_uuid', '');
        $delay = max(0, min(60, (int) $request->input('delay_seconds', 8)));
        $ts = (int) $request->input('ts', 0);

        if (! preg_match(self::UUID_RE, $uuid) || ! preg_match(self::UUID_RE, $domain)) {
            return response()->json(['found' => false, 'error' => 'bad uuid'], 422);
        }
        if (abs(time() - $ts) > 300) {
            return response()->json(['found' => false, 'error' => 'stale'], 401);
        }

        // Only a live channel of THIS tenant's domain may be hung up.
        $channelDomain = $esl->getVar($uuid, 'domain_uuid');
        if ($channelDomain === null) {
            return response()->json(['found' => false]);
        }
        if (strcasecmp($channelDomain, $domain) !== 0) {
            return response()->json(['found' => false, 'error' => 'domain mismatch'], 403);
        }

        $esl->setVar($uuid, 'voxra_spam_hangup', 'true');
        $out = $esl->executeCommand(sprintf('sched_hangup +%d %s NORMAL_CLEARING', $delay, $uuid));
        logger("Voxra spam hangup scheduled uuid={$uuid} in {$delay}s: " . (is_string($out) ? $out : json_encode($out)));

        return response()->json(['found' => true, 'delay_seconds' => $delay]);
    }
}
