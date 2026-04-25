<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Best-effort CRM lookup against the iqcrm web app
 * (`GET /api/internal/people/by-phone`). Used by SendIncomingCallPushJob to
 * enrich the VoIP push payload with person/company/notes context so the iOS
 * app can render the caller without a network round-trip on the hot path.
 *
 * Hot-path constraints:
 *   - Hard timeout (configurable, default 1.5s).
 *   - Any error returns null — never throws into the caller, never blocks
 *     the push beyond the timeout.
 *   - No retries: a missed enrichment is recoverable (iOS falls back to
 *     local Contacts + on-demand /api/people/{id} fetch); a delayed push
 *     is not.
 */
class CrmLookupService
{
    /**
     * @return array<string,mixed>|null Enrichment fields ready to merge into
     *     the APNs payload, or null when no match / lookup failed.
     */
    public function lookupByPhone(string $e164, ?string $domain = null): ?array
    {
        $apiKey = (string) config('services.iqcrm.api_key', '');
        if ($apiKey === '' || trim($e164) === '') {
            return null;
        }

        $baseUrl = rtrim((string) config('services.iqcrm.base_url'), '/');
        $timeout = (float) config('services.iqcrm.timeout', 1.5);

        try {
            $response = Http::timeout($timeout)
                ->connectTimeout(min($timeout, 1.0))
                ->withToken($apiKey)
                ->acceptJson()
                ->get("{$baseUrl}/api/internal/people/by-phone", array_filter([
                    'phone'  => $e164,
                    'domain' => $domain,
                ]));
        } catch (\Throwable $e) {
            Log::info('[CrmLookup] request threw', [
                'phone'  => $this->maskPhone($e164),
                'error'  => $e->getMessage(),
            ]);
            return null;
        }

        if ($response->status() === 404) {
            return null; // no match — common case, not an error
        }
        if (!$response->successful()) {
            Log::info('[CrmLookup] non-200 response', [
                'phone'  => $this->maskPhone($e164),
                'status' => $response->status(),
            ]);
            return null;
        }

        $body = $response->json();
        if (!is_array($body) || ($body['matched'] ?? false) !== true) {
            return null;
        }

        // Pass through only the fields the iOS app reads. Any nulls / empty
        // strings are stripped so the APNs payload stays tight (5KB cap).
        return array_filter([
            'person_id'           => $body['person_id']           ?? null,
            'display_name'        => $body['display_name']        ?? null,
            'company_name'        => $body['company_name']        ?? null,
            'is_vip'              => isset($body['is_vip']) ? (bool) $body['is_vip'] : null,
            'last_interaction_at' => $body['last_interaction_at'] ?? null,
            'note_preview'        => $body['note_preview']        ?? null,
        ], fn($v) => $v !== null && $v !== '');
    }

    private function maskPhone(string $e164): string
    {
        if (strlen($e164) <= 5) return $e164;
        return substr($e164, 0, 3) . str_repeat('*', max(0, strlen($e164) - 5)) . substr($e164, -2);
    }
}
