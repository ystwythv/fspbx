<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\Domain;
use Illuminate\Http\Request;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\FreeswitchEslService;

class PresenceController extends Controller
{
    /**
     * Live presence for a domain
     *
     * Returns the set of extensions that are currently registered (online) and
     * the set that are currently on a call (in-call), for the given domain. The
     * data is read live from FreeSWITCH via the event socket, so it reflects the
     * real-time state of the switch rather than anything stored in the database.
     *
     * Access rules:
     * - Caller must have access to the target domain (domain scope).
     * - Caller must have the `extension_view` permission.
     *
     * @group Extensions
     * @authenticated
     *
     * @urlParam domain_uuid string required The domain UUID. Example: 4018f7a3-8e0a-47bb-9f4f-04b1313e0e1b
     *
     * @response 200 scenario="Success" {
     *   "object": "presence",
     *   "url": "/api/v1/domains/4018f7a3-8e0a-47bb-9f4f-04b1313e0e1b/presence",
     *   "data": {
     *     "registered": ["1001", "1002"],
     *     "in_call": ["1002"]
     *   }
     * }
     */
    public function index(Request $request, FreeswitchEslService $esl, string $domain_uuid)
    {
        $user = $request->user();
        if (! $user) {
            throw new ApiException(401, 'authentication_error', 'Unauthenticated.', 'unauthenticated');
        }

        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $domain_uuid)) {
            throw new ApiException(
                400,
                'invalid_request_error',
                'Invalid domain UUID.',
                'invalid_request',
                'domain_uuid'
            );
        }

        $domain = Domain::query()
            ->where('domain_uuid', $domain_uuid)
            ->first(['domain_uuid', 'domain_name']);

        if (! $domain) {
            throw new ApiException(
                404,
                'invalid_request_error',
                'Domain not found.',
                'resource_missing',
                'domain_uuid'
            );
        }

        $domainName = (string) $domain->domain_name;

        // Registered (online) extensions: SIP + Verto registrations whose realm
        // matches this domain. We key on sip_auth_user (the extension number).
        $registered = $esl->getAllSipRegistrations()
            ->filter(fn ($r) => strcasecmp((string) ($r['sip_auth_realm'] ?? ''), $domainName) === 0)
            ->map(fn ($r) => (string) ($r['sip_auth_user'] ?? ''))
            ->filter(fn ($ext) => $ext !== '')
            ->unique()
            ->values()
            ->all();

        // In-call extensions: any live channel belonging to this domain. The
        // endpoint leg carries presence_id "extension@domain"; we take the user
        // part. accountcode is the domain name for local legs.
        $inCall = $esl->getAllChannels()
            ->flatMap(function ($c) use ($domainName) {
                $exts = [];
                $presenceId = (string) ($c['presence_id'] ?? '');
                if ($presenceId !== '' && str_contains($presenceId, '@')) {
                    [$exten, $realm] = explode('@', $presenceId, 2);
                    if (strcasecmp($realm, $domainName) === 0 && $exten !== '') {
                        $exts[] = $exten;
                    }
                }
                return $exts;
            })
            ->unique()
            ->values()
            ->all();

        return response()->json([
            'object' => 'presence',
            'url'    => "/api/v1/domains/{$domain_uuid}/presence",
            'data'   => [
                'registered' => $registered,
                'in_call'    => $inCall,
            ],
        ], 200);
    }
}
