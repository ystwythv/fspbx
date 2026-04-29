<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifies HMAC-SHA256 signatures on tool-call webhooks coming FROM ElevenLabs
 * back to Laravel. The shared secret is configured per-installation and
 * registered with the ElevenLabs agent when its tools are synced.
 *
 * ElevenLabs convention: header `ElevenLabs-Signature` carries
 * `t=<unix_ts>,v0=<hex_hmac>` over `<unix_ts>.<raw_body>`. We accept that
 * format primarily, and fall back to a plain hex HMAC of the raw body so the
 * endpoint stays testable from curl with our own secret.
 */
class VerifyElevenLabsSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.elevenlabs.tool_webhook_secret', '');
        if ($secret === '') {
            // Hard-fail rather than silently allow — webhook handlers can mutate live calls.
            return response()->json(['error' => 'tool webhook secret not configured'], 500);
        }

        $signatureHeader = (string) ($request->header('ElevenLabs-Signature')
            ?? $request->header('X-ElevenLabs-Signature')
            ?? $request->header('Signature')
            ?? '');

        if ($signatureHeader === '') {
            return response()->json(['error' => 'missing signature'], 401);
        }

        $body = $request->getContent();

        // ElevenLabs canonical format
        if (preg_match('/t=(\d+),v0=([a-f0-9]+)/i', $signatureHeader, $m)) {
            $ts = $m[1];
            $sig = $m[2];
            // Reject stale messages (>5 min) to limit replay window.
            if (abs(time() - (int) $ts) > 300) {
                return response()->json(['error' => 'signature timestamp out of range'], 401);
            }
            $expected = hash_hmac('sha256', $ts . '.' . $body, $secret);
            if (hash_equals($expected, $sig)) {
                return $next($request);
            }
            return response()->json(['error' => 'invalid signature'], 401);
        }

        // Fallback: plain HMAC over body (useful for testing).
        $expected = hash_hmac('sha256', $body, $secret);
        if (hash_equals($expected, $signatureHeader)) {
            return $next($request);
        }

        return response()->json(['error' => 'invalid signature'], 401);
    }
}
