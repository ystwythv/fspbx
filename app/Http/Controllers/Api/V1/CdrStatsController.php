<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\V1\Cdr\CdrFilterData;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Services\Cdr\CdrApiFilterParser;
use App\Services\Cdr\CdrStatsService;
use Illuminate\Http\Request;

class CdrStatsController extends Controller
{
    public function __construct(
        protected CdrStatsService $stats,
        protected CdrApiFilterParser $filterParser,
    ) {}

    /**
     * CDR summary statistics
     *
     * Aggregate counts, durations, ASR and ACD for the given domain + window.
     *
     * @group CDR
     * @authenticated
     */
    public function summary(Request $request, string $domain_uuid)
    {
        $this->assertUuid($domain_uuid);
        $filters = $this->filterParser->fromRequest($request);

        $summary = $this->stats->summary($domain_uuid, $filters);

        return response()->json([
            'object' => 'cdr_stats_summary',
            'domain_uuid' => $domain_uuid,
            'window' => $this->windowBlock($filters),
            ...$summary,
        ], 200);
    }

    /**
     * CDR stats grouped by direction.
     *
     * @group CDR
     * @authenticated
     */
    public function byDirection(Request $request, string $domain_uuid)
    {
        $this->assertUuid($domain_uuid);
        $filters = $this->filterParser->fromRequest($request);

        return response()->json([
            'object' => 'cdr_stats_by_direction',
            'domain_uuid' => $domain_uuid,
            'window' => $this->windowBlock($filters),
            'data' => $this->stats->byDirection($domain_uuid, $filters),
        ], 200);
    }

    /**
     * CDR stats grouped by hangup cause.
     *
     * @group CDR
     * @authenticated
     */
    public function byHangupCause(Request $request, string $domain_uuid)
    {
        $this->assertUuid($domain_uuid);
        $filters = $this->filterParser->fromRequest($request);

        return response()->json([
            'object' => 'cdr_stats_by_hangup_cause',
            'domain_uuid' => $domain_uuid,
            'window' => $this->windowBlock($filters),
            'data' => $this->stats->byHangupCause($domain_uuid, $filters),
        ], 200);
    }

    /**
     * CDR stats grouped by extension.
     *
     * @group CDR
     * @authenticated
     */
    public function byExtension(Request $request, string $domain_uuid)
    {
        $this->assertUuid($domain_uuid);
        $filters = $this->filterParser->fromRequest($request);

        return response()->json([
            'object' => 'cdr_stats_by_extension',
            'domain_uuid' => $domain_uuid,
            'window' => $this->windowBlock($filters),
            'data' => $this->stats->byExtension($domain_uuid, $filters),
        ], 200);
    }

    /**
     * CDR call volume + duration + quality as a time series.
     *
     * @group CDR
     * @authenticated
     * @queryParam bucket string hour|day (default day).
     */
    public function timeseries(Request $request, string $domain_uuid)
    {
        $this->assertUuid($domain_uuid);
        $filters = $this->filterParser->fromRequest($request);
        $bucket = strtolower(trim((string) $request->query('bucket', 'day'))) ?: 'day';

        return response()->json([
            'object' => 'cdr_stats_timeseries',
            'domain_uuid' => $domain_uuid,
            'window' => $this->windowBlock($filters),
            'bucket' => in_array($bucket, ['hour', 'day'], true) ? $bucket : 'day',
            'data' => $this->stats->timeseries($domain_uuid, $filters, $bucket),
        ], 200);
    }

    /**
     * MOS-based quality distribution.
     *
     * @group CDR
     * @authenticated
     * @queryParam poor_threshold float MOS threshold under which a call is "poor" (default 4.0).
     */
    public function quality(Request $request, string $domain_uuid)
    {
        $this->assertUuid($domain_uuid);
        $filters = $this->filterParser->fromRequest($request);
        $threshold = (float) $request->query('poor_threshold', 4.0);

        return response()->json([
            'object' => 'cdr_stats_quality',
            'domain_uuid' => $domain_uuid,
            'window' => $this->windowBlock($filters),
            ...$this->stats->quality($domain_uuid, $filters, $threshold),
        ], 200);
    }

    /**
     * Top called destination numbers.
     *
     * @group CDR
     * @authenticated
     * @queryParam limit int 1–100 (default 20).
     */
    public function topDestinations(Request $request, string $domain_uuid)
    {
        $this->assertUuid($domain_uuid);
        $filters = $this->filterParser->fromRequest($request);
        $limit = (int) $request->query('limit', 20);

        return response()->json([
            'object' => 'cdr_stats_top_destinations',
            'domain_uuid' => $domain_uuid,
            'window' => $this->windowBlock($filters),
            'data' => $this->stats->topDestinations($domain_uuid, $filters, $limit),
        ], 200);
    }

    /*
    |--------------------------------------------------------------------------
    | Global (cross-domain) variants
    |--------------------------------------------------------------------------
    | All reachable only via routes gated by `cdr.scope:global`. They optionally
    | accept a `?domain_uuid=` filter for targeted cross-tenant queries.
    */

    public function globalSummary(Request $request)
    {
        $filters = $this->filterParser->fromRequest($request);
        $scope = $this->resolveGlobalScope($request);

        if ($scope['group_by_domain']) {
            return response()->json([
                'object' => 'cdr_stats_by_domain',
                'scope' => 'global',
                'window' => $this->windowBlock($filters),
                'data' => $this->stats->byDomain($filters),
            ], 200);
        }

        return response()->json([
            'object' => 'cdr_stats_summary',
            'scope' => 'global',
            'domain_uuid' => $scope['domain_uuid'],
            'window' => $this->windowBlock($filters),
            ...$this->stats->summary($scope['domain_uuid'], $filters),
        ], 200);
    }

    public function globalByDirection(Request $request)
    {
        $filters = $this->filterParser->fromRequest($request);
        $scope = $this->resolveGlobalScope($request);

        return response()->json([
            'object' => 'cdr_stats_by_direction',
            'scope' => 'global',
            'domain_uuid' => $scope['domain_uuid'],
            'window' => $this->windowBlock($filters),
            'data' => $this->stats->byDirection($scope['domain_uuid'], $filters),
        ], 200);
    }

    public function globalByHangupCause(Request $request)
    {
        $filters = $this->filterParser->fromRequest($request);
        $scope = $this->resolveGlobalScope($request);

        return response()->json([
            'object' => 'cdr_stats_by_hangup_cause',
            'scope' => 'global',
            'domain_uuid' => $scope['domain_uuid'],
            'window' => $this->windowBlock($filters),
            'data' => $this->stats->byHangupCause($scope['domain_uuid'], $filters),
        ], 200);
    }

    private function resolveGlobalScope(Request $request): array
    {
        $domainUuid = trim((string) $request->query('domain_uuid', '')) ?: null;
        if ($domainUuid !== null) {
            $this->assertUuid($domainUuid);
        }
        $groupByDomain = filter_var(
            $request->query('group_by_domain', 'false'),
            FILTER_VALIDATE_BOOLEAN
        );

        return [
            'domain_uuid' => $domainUuid,
            'group_by_domain' => $groupByDomain,
        ];
    }

    private function windowBlock(CdrFilterData $filters): array
    {
        return [
            'from' => gmdate('Y-m-d\TH:i:s\Z', $filters->dateFromEpoch),
            'to' => gmdate('Y-m-d\TH:i:s\Z', $filters->dateToEpoch),
        ];
    }

    private function assertUuid(string $value): void
    {
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $value)) {
            throw new ApiException(400, 'invalid_request_error', 'Invalid domain_uuid.', 'invalid_request', 'domain_uuid');
        }
    }

}
