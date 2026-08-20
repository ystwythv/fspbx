<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Authenticates Telnyx AI-assistant → Laravel webhooks (reception-agent tool
 * calls and the dynamic-variables endpoint). Accepts EITHER of:
 *
 * 1. Telnyx's standard Ed25519 webhook signature: headers
 *    `telnyx-signature-ed25519` (base64) + `telnyx-timestamp`, signed message
 *    `{timestamp}|{raw_body}`, verified against TELNYX_PUBLIC_KEY (the same
 *    account public key config/webhook-client.php already uses; found in
 *    Mission Control → Keys & Credentials → Public Key).
 *
 * 2. A shared secret (VOXRA_TELNYX_TOOL_SECRET) in an `X-Voxra-Tool-Secret`
 *    header — set on the assistant's webhook tool definitions by
 *    TelnyxConvaiService::syncReceptionAgentTools — or in an `s` query param,
 *    because dynamic_variables_webhook_url cannot carry custom headers
 *    (mirrors the voxraweb data-tool convention).
 *
 * Assistant webhook tools are operator-defined HTTP calls, not platform
 * webhook events, so Telnyx does not document them as Ed25519-signed: the
 * shared secret is the guaranteed mechanism, and the signature path applies
 * if/when Telnyx signs these requests. Fails closed: with neither mechanism
 * configured every request is rejected, unless the explicit
 * TELNYX_WEBHOOK_ALLOW_UNSIGNED=true escape hatch (dev/staging only) is set.
 */
class VerifyTelnyxSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('telnyx.tool_secret', '');
        $publicKey = (string) config('telnyx.public_key', '');
        $allowUnsigned = (bool) config('telnyx.webhook_allow_unsigned', false);

        // Shared-secret path.
        if ($secret !== '') {
            $provided = (string) $request->header('X-Voxra-Tool-Secret', '');
            if ($provided === '') {
                $provided = (string) $request->query('s', '');
            }
            if ($provided !== '' && hash_equals($secret, $provided)) {
                return $next($request);
            }
        }

        // Ed25519 signature path.
        [$signatureOk, $reason] = $this->verifySignature($request, $publicKey);
        if ($signatureOk) {
            return $next($request);
        }

        if ($allowUnsigned) {
            // Deliberately loud — this hatch must never be active in prod.
            Log::warning('telnyx webhook: TELNYX_WEBHOOK_ALLOW_UNSIGNED active, accepting unauthenticated request', [
                'path' => $request->path(),
                'ip'   => $request->ip(),
            ]);
            return $next($request);
        }

        if ($secret === '' && $publicKey === '') {
            // Hard-fail rather than silently allow — these webhooks mutate live calls.
            Log::error('telnyx webhook rejected: neither TELNYX_PUBLIC_KEY nor VOXRA_TELNYX_TOOL_SECRET configured', [
                'path' => $request->path(),
            ]);
            return response()->json(['error' => 'telnyx webhook auth not configured'], 500);
        }

        Log::warning('telnyx webhook rejected: ' . $reason, [
            'path'          => $request->path(),
            'ip'            => $request->ip(),
            'has_signature' => $request->hasHeader('telnyx-signature-ed25519'),
            'has_timestamp' => $request->hasHeader('telnyx-timestamp'),
            'has_secret'    => $request->hasHeader('X-Voxra-Tool-Secret') || $request->query('s', '') !== '',
        ]);

        return response()->json(['error' => 'invalid signature'], 401);
    }

    /**
     * @return array{0: bool, 1: string} [valid, rejection reason]
     */
    private function verifySignature(Request $request, string $publicKey): array
    {
        $signature = (string) $request->header('telnyx-signature-ed25519', '');
        $timestamp = (string) $request->header('telnyx-timestamp', '');

        if ($signature === '' || $timestamp === '') {
            return [false, 'missing signature headers'];
        }
        if ($publicKey === '') {
            return [false, 'signature present but TELNYX_PUBLIC_KEY not configured'];
        }
        if (!ctype_digit($timestamp)) {
            return [false, 'invalid timestamp format'];
        }

        $tolerance = (int) config('telnyx.webhook_tolerance', 300);
        if (abs(time() - (int) $timestamp) > $tolerance) {
            return [false, 'signature timestamp outside tolerance'];
        }

        try {
            $signatureBytes = base64_decode($signature, true);
            $publicKeyBytes = base64_decode(trim($publicKey), true);

            if ($signatureBytes === false
                || $publicKeyBytes === false
                || strlen($publicKeyBytes) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                return [false, 'failed to decode signature or public key'];
            }

            $valid = sodium_crypto_sign_verify_detached(
                $signatureBytes,
                $timestamp . '|' . $request->getContent(),
                $publicKeyBytes
            );

            return [$valid, $valid ? '' : 'signature verification failed'];
        } catch (Throwable $e) {
            return [false, 'signature verification exception: ' . $e->getMessage()];
        }
    }
}
