<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Shared-secret HMAC verification for FreeSWITCH (Lua) → Laravel internal
 * callbacks. Mirrors the existing /webhook/freeswitch pattern (header name
 * "Signature", HMAC-SHA256 over the raw body) but verifies inline so the
 * caller gets a synchronous response.
 */
class VerifyVoxraInternalSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('services.voxra_internal.secret', '');
        if ($secret === '') {
            return response()->json(['error' => 'voxra internal secret not configured'], 500);
        }

        $provided = (string) $request->header('Signature', '');
        if ($provided === '') {
            return response()->json(['error' => 'missing signature'], 401);
        }

        $body = $request->getContent();
        $expected = hash_hmac('sha256', $body, $secret);

        if (!hash_equals($expected, $provided)) {
            return response()->json(['error' => 'invalid signature'], 401);
        }

        return $next($request);
    }
}
