<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AiAgent;
use App\Models\Domain;
use App\Models\Extensions;
use App\Models\RingGroups;
use Illuminate\Http\Request;

class RoutingTargetController extends Controller
{
    public const MAX_DOMAINS = 200;

    /**
     * List routing targets for many domains
     *
     * Returns the extensions, ring groups and AI agents of each given domain in
     * one call, with only the fields needed to pick a call routing target.
     * iqportal's phone-number pages listed all three for every domain of the
     * reseller: about 171 calls a page for a partner with 57 domains, which ran
     * into the API rate limit.
     *
     * Domains that don't exist are left out. Rows are sorted by UUID.
     *
     * Access rules:
     * - Global token only (a domain-scoped token gets 403).
     * - Caller must have the `extension_all` and `ring_group_all` permissions.
     *
     * Example:
     * - `GET /api/v1/routing-targets?domain_uuid=4018f7a3-8e0a-47bb-9f4f-04b1313e0e1b,7d58342b-2b29-4dcf-92d6-e9a9e002a4e5`
     *
     * @group Routing targets
     * @authenticated
     *
     * @queryParam domain_uuid string required Comma-separated domain UUIDs, up to 200. Example: 4018f7a3-8e0a-47bb-9f4f-04b1313e0e1b
     *
     * @response 200 scenario="Success" {
     *   "object": "list",
     *   "url": "/api/v1/routing-targets",
     *   "data": [
     *     {
     *       "object": "routing_targets",
     *       "domain_uuid": "4018f7a3-8e0a-47bb-9f4f-04b1313e0e1b",
     *       "extensions": [{"extension_uuid": "…", "extension": "201", "directory_first_name": "Jane", "directory_last_name": "Smith"}],
     *       "ring_groups": [{"ring_group_uuid": "…", "ring_group_extension": "600", "ring_group_name": "Sales"}],
     *       "ai_agents": [{"ai_agent_uuid": "…", "agent_extension": "9250", "agent_name": "Receptionist"}]
     *     }
     *   ]
     * }
     *
     * @response 400 scenario="Missing or invalid domain_uuid" {"error":{"type":"invalid_request_error","message":"domain_uuid must be 1 to 200 comma-separated UUIDs.","code":"invalid_request","param":"domain_uuid"}}
     */
    public function index(Request $request)
    {
        $uuids = array_values(array_unique(array_filter(
            array_map(static fn (string $u): string => strtolower(trim($u)), explode(',', (string) $request->input('domain_uuid', ''))),
            static fn (string $u): bool => $u !== '',
        )));
        $valid = $uuids !== [] && \count($uuids) <= self::MAX_DOMAINS;
        foreach ($uuids as $u) {
            if (! preg_match('/^[0-9a-f-]{36}$/', $u)) {
                $valid = false;
            }
        }
        if (! $valid) {
            throw new ApiException(400, 'invalid_request_error', 'domain_uuid must be 1 to ' . self::MAX_DOMAINS . ' comma-separated UUIDs.', 'invalid_request', 'domain_uuid');
        }

        $domains = Domain::query()->whereIn('domain_uuid', $uuids)->orderBy('domain_uuid')->pluck('domain_uuid')->map(fn ($u) => (string) $u);

        $extensions = Extensions::query()->whereIn('domain_uuid', $domains)->orderBy('extension_uuid')
            ->get(['extension_uuid', 'domain_uuid', 'extension', 'directory_first_name', 'directory_last_name'])
            ->groupBy(fn ($e) => (string) $e->domain_uuid);
        $ringGroups = RingGroups::query()->whereIn('domain_uuid', $domains)->orderBy('ring_group_uuid')
            ->get(['ring_group_uuid', 'domain_uuid', 'ring_group_extension', 'ring_group_name'])
            ->groupBy(fn ($r) => (string) $r->domain_uuid);
        $aiAgents = AiAgent::query()->whereIn('domain_uuid', $domains)->orderBy('ai_agent_uuid')
            ->get(['ai_agent_uuid', 'domain_uuid', 'agent_extension', 'agent_name'])
            ->groupBy(fn ($a) => (string) $a->domain_uuid);

        $str = static fn ($v): ?string => $v === null ? null : (string) $v;

        $data = $domains->map(fn (string $d) => [
            'object' => 'routing_targets',
            'domain_uuid' => $d,
            'extensions' => ($extensions[$d] ?? collect())->map(fn ($e) => [
                'extension_uuid' => (string) $e->extension_uuid,
                'extension' => $str($e->extension),
                'directory_first_name' => $str($e->directory_first_name),
                'directory_last_name' => $str($e->directory_last_name),
            ])->values(),
            'ring_groups' => ($ringGroups[$d] ?? collect())->map(fn ($r) => [
                'ring_group_uuid' => (string) $r->ring_group_uuid,
                'ring_group_extension' => $str($r->ring_group_extension),
                'ring_group_name' => $str($r->ring_group_name),
            ])->values(),
            'ai_agents' => ($aiAgents[$d] ?? collect())->map(fn ($a) => [
                'ai_agent_uuid' => (string) $a->ai_agent_uuid,
                'agent_extension' => $str($a->agent_extension),
                'agent_name' => $str($a->agent_name),
            ])->values(),
        ])->values();

        return response()->json([
            'object' => 'list',
            'url' => '/api/v1/routing-targets',
            'data' => $data,
        ], 200);
    }
}
