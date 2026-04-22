<?php

namespace App\Services\Cdr;

use App\Data\Api\V1\Cdr\CdrFilterData;
use App\Models\CDR;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class CdrStatsService
{
    public function __construct(protected CdrQueryService $queries) {}

    /**
     * Single-row summary for the domain + window + filters.
     *
     * @return array<string, mixed>
     */
    public function summary(string $domainUuid, CdrFilterData $filters): array
    {
        $row = $this->scopedQuery($domainUuid, $filters)
            ->selectRaw($this->summaryExpressions())
            ->first();

        return $this->normalizeSummary($row);
    }

    /**
     * Summary grouped by direction.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byDirection(string $domainUuid, CdrFilterData $filters): array
    {
        $rows = $this->scopedQuery($domainUuid, $filters)
            ->selectRaw('direction, ' . $this->summaryExpressions())
            ->groupBy('direction')
            ->orderBy('direction')
            ->get();

        return $rows->map(function ($r) {
            $summary = $this->normalizeSummary($r);
            $summary['direction'] = $r->direction ?? 'unknown';
            return $summary;
        })->all();
    }

    /**
     * Failure-analysis breakdown on hangup cause + q.850 + SIP disposition.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byHangupCause(string $domainUuid, CdrFilterData $filters): array
    {
        $total = (int) $this->scopedQuery($domainUuid, $filters)->count();

        $rows = $this->scopedQuery($domainUuid, $filters)
            ->selectRaw(
                'hangup_cause, hangup_cause_q850, sip_hangup_disposition, ' .
                'COUNT(*) AS calls, ' .
                'COALESCE(SUM(duration),0) AS total_duration_sec, ' .
                'COALESCE(SUM(billsec),0) AS total_billsec, ' .
                'COALESCE(AVG(NULLIF(duration,0)),0) AS avg_duration_sec'
            )
            ->groupBy('hangup_cause', 'hangup_cause_q850', 'sip_hangup_disposition')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        return $rows->map(function ($r) use ($total) {
            $calls = (int) $r->calls;
            return [
                'hangup_cause' => $r->hangup_cause,
                'hangup_cause_q850' => $r->hangup_cause_q850 === null ? null : (int) $r->hangup_cause_q850,
                'sip_hangup_disposition' => $r->sip_hangup_disposition,
                'calls' => $calls,
                'pct_of_total' => $total > 0 ? round($calls / $total, 4) : 0.0,
                'total_duration_sec' => (int) $r->total_duration_sec,
                'total_billsec' => (int) $r->total_billsec,
                'avg_duration_sec' => (float) round((float) $r->avg_duration_sec, 2),
            ];
        })->all();
    }

    protected function scopedQuery(string $domainUuid, CdrFilterData $filters): Builder
    {
        $query = $this->queries->baseQuery($domainUuid, $filters->dateFromEpoch, $filters->dateToEpoch);
        return $this->queries->applyFilters($query, $filters);
    }

    protected function summaryExpressions(): string
    {
        return implode(', ', [
            'COUNT(*) AS calls',
            "SUM(CASE WHEN answer_epoch > 0 AND hangup_cause = 'NORMAL_CLEARING' AND (voicemail_message IS NULL OR voicemail_message = false) AND (missed_call IS NULL OR missed_call = false) THEN 1 ELSE 0 END) AS answered",
            "SUM(CASE WHEN missed_call = true AND hangup_cause = 'NORMAL_CLEARING' AND (voicemail_message IS NULL OR voicemail_message = false) AND (cc_cancel_reason IS NULL OR cc_cancel_reason <> 'BREAK_OUT') THEN 1 ELSE 0 END) AS missed",
            "SUM(CASE WHEN voicemail_message = true THEN 1 ELSE 0 END) AS voicemail",
            "SUM(CASE WHEN missed_call = true AND hangup_cause = 'NORMAL_CLEARING' AND cc_cancel_reason = 'BREAK_OUT' AND cc_cause = 'cancel' THEN 1 ELSE 0 END) AS abandoned",
            "SUM(CASE WHEN hangup_cause IN ('NO_ANSWER','NO_USER_RESPONSE','ALLOTTED_TIMEOUT') THEN 1 ELSE 0 END) AS no_answer",
            "SUM(CASE WHEN hangup_cause = 'USER_BUSY' THEN 1 ELSE 0 END) AS busy",
            "SUM(CASE WHEN (answer_epoch IS NULL OR answer_epoch = 0) AND hangup_cause NOT IN ('NORMAL_CLEARING','USER_BUSY','NO_ANSWER','NO_USER_RESPONSE','ALLOTTED_TIMEOUT') THEN 1 ELSE 0 END) AS failed",
            'COALESCE(SUM(duration),0) AS total_duration_sec',
            'COALESCE(SUM(billsec),0) AS total_billsec',
            'COALESCE(AVG(NULLIF(duration,0)),0) AS avg_duration_sec',
        ]);
    }

    protected function normalizeSummary($row): array
    {
        $calls = (int) ($row->calls ?? 0);
        $answered = (int) ($row->answered ?? 0);
        $busy = (int) ($row->busy ?? 0);
        $noAnswer = (int) ($row->no_answer ?? 0);
        $failed = (int) ($row->failed ?? 0);
        $asrDenominator = $answered + $busy + $noAnswer + $failed;
        $asr = $asrDenominator > 0 ? round($answered / $asrDenominator, 4) : 0.0;

        $totalBillsec = (int) ($row->total_billsec ?? 0);
        $acd = $answered > 0 ? (int) round($totalBillsec / $answered) : 0;

        return [
            'totals' => [
                'calls' => $calls,
                'answered' => $answered,
                'missed' => (int) ($row->missed ?? 0),
                'voicemail' => (int) ($row->voicemail ?? 0),
                'abandoned' => (int) ($row->abandoned ?? 0),
                'busy' => $busy,
                'no_answer' => $noAnswer,
                'failed' => $failed,
            ],
            'duration' => [
                'total_sec' => (int) ($row->total_duration_sec ?? 0),
                'total_billsec' => $totalBillsec,
                'avg_sec' => (int) round((float) ($row->avg_duration_sec ?? 0)),
            ],
            'rates' => [
                'asr' => $asr,
                'acd_sec' => $acd,
            ],
            'cost' => [
                'total' => null,
                'currency' => null,
            ],
        ];
    }
}
