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
