<?php

namespace App\Services\Cdr;

use App\Data\Api\V1\Cdr\CdrFilterData;
use Illuminate\Database\Eloquent\Builder;

class CdrStatsService
{
    public function __construct(protected CdrQueryService $queries) {}

    /**
     * Single-row summary for the window + filters, optionally scoped to one
     * domain. Pass `null` for $domainUuid to compute fleet-wide totals
     * (only reachable via the `cdr.scope:global` gated routes).
     *
     * @return array<string, mixed>
     */
    public function summary(?string $domainUuid, CdrFilterData $filters): array
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
    public function byDirection(?string $domainUuid, CdrFilterData $filters): array
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
     * Summary grouped by domain — only valid for global-scope calls.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byDomain(CdrFilterData $filters): array
    {
        $rows = $this->scopedQuery(null, $filters)
            ->selectRaw('domain_uuid, ' . $this->summaryExpressions())
            ->groupBy('domain_uuid')
            ->orderByRaw('COUNT(*) DESC')
            ->get();

        return $rows->map(function ($r) {
            $summary = $this->normalizeSummary($r);
            $summary['domain_uuid'] = $r->domain_uuid;
            return $summary;
        })->all();
    }

    /**
     * Failure-analysis breakdown on hangup cause + q.850 + SIP disposition.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byHangupCause(?string $domainUuid, CdrFilterData $filters): array
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

    /**
     * Per-extension breakdown. Rows are ordered by call count desc.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byExtension(?string $domainUuid, CdrFilterData $filters): array
    {
        $rows = $this->scopedQuery($domainUuid, $filters)
            ->whereNotNull('extension_uuid')
            ->selectRaw(
                'extension_uuid, ' .
                'COUNT(*) AS calls, ' .
                "SUM(CASE WHEN answer_epoch > 0 AND hangup_cause = 'NORMAL_CLEARING' AND (voicemail_message IS NULL OR voicemail_message = false) AND (missed_call IS NULL OR missed_call = false) THEN 1 ELSE 0 END) AS answered, " .
                "SUM(CASE WHEN missed_call = true AND hangup_cause = 'NORMAL_CLEARING' THEN 1 ELSE 0 END) AS missed, " .
                'COALESCE(SUM(billsec),0) AS total_billsec, ' .
                'COALESCE(AVG(NULLIF(billsec,0)),0) AS avg_billsec, ' .
                'AVG(rtp_audio_in_mos) AS mos_avg'
            )
            ->groupBy('extension_uuid')
            ->orderByRaw('COUNT(*) DESC')
            ->limit(500)
            ->get();

        return $rows->map(fn ($r) => [
            'extension_uuid' => (string) $r->extension_uuid,
            'calls' => (int) $r->calls,
            'answered' => (int) $r->answered,
            'missed' => (int) $r->missed,
            'total_billsec' => (int) $r->total_billsec,
            'avg_billsec' => (int) round((float) $r->avg_billsec),
            'mos_avg' => $r->mos_avg === null ? null : round((float) $r->mos_avg, 2),
        ])->all();
    }

    /**
     * Hour or day buckets over the window.
     *
     * @return array<int, array<string, mixed>>
     */
    public function timeseries(?string $domainUuid, CdrFilterData $filters, string $bucket): array
    {
        $bucket = in_array($bucket, ['hour', 'day'], true) ? $bucket : 'day';
        $expr = "date_trunc('{$bucket}', to_timestamp(start_epoch) AT TIME ZONE 'UTC')";

        $rows = $this->scopedQuery($domainUuid, $filters)
            ->selectRaw(
                "{$expr} AS bucket_start, " .
                'COUNT(*) AS calls, ' .
                "SUM(CASE WHEN answer_epoch > 0 AND hangup_cause = 'NORMAL_CLEARING' AND (voicemail_message IS NULL OR voicemail_message = false) AND (missed_call IS NULL OR missed_call = false) THEN 1 ELSE 0 END) AS answered, " .
                "SUM(CASE WHEN (answer_epoch IS NULL OR answer_epoch = 0) AND hangup_cause NOT IN ('NORMAL_CLEARING','USER_BUSY','NO_ANSWER','NO_USER_RESPONSE','ALLOTTED_TIMEOUT') THEN 1 ELSE 0 END) AS failed, " .
                'COALESCE(SUM(billsec),0) AS total_billsec, ' .
                'AVG(rtp_audio_in_mos) AS mos_avg'
            )
            ->groupByRaw($expr)
            ->orderByRaw($expr)
            ->get();

        return $rows->map(fn ($r) => [
            'bucket_start' => $this->formatIso($r->bucket_start),
            'calls' => (int) $r->calls,
            'answered' => (int) $r->answered,
            'failed' => (int) $r->failed,
            'total_billsec' => (int) $r->total_billsec,
            'mos_avg' => $r->mos_avg === null ? null : round((float) $r->mos_avg, 2),
        ])->all();
    }

    /**
     * MOS distribution + counts below a quality threshold.
     *
     * @return array<string, mixed>
     */
    public function quality(?string $domainUuid, CdrFilterData $filters, float $poorThreshold = 4.0): array
    {
        $row = $this->scopedQuery($domainUuid, $filters)
            ->whereNotNull('rtp_audio_in_mos')
            ->selectRaw(
                'COUNT(*) AS samples, ' .
                'AVG(rtp_audio_in_mos) AS mos_avg, ' .
                'MIN(rtp_audio_in_mos) AS mos_min, ' .
                'MAX(rtp_audio_in_mos) AS mos_max, ' .
                'PERCENTILE_CONT(0.5) WITHIN GROUP (ORDER BY rtp_audio_in_mos) AS mos_p50, ' .
                'PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY rtp_audio_in_mos) AS mos_p95, ' .
                'AVG(rtp_audio_out_mos) AS mos_out_avg, ' .
                'MIN(rtp_audio_out_mos) AS mos_out_min, ' .
                'MAX(rtp_audio_out_mos) AS mos_out_max, ' .
                'AVG(rtp_audio_in_jitter_ms) AS jitter_avg, ' .
                'MAX(rtp_audio_in_jitter_ms) AS jitter_max, ' .
                'AVG(rtp_audio_in_packet_loss) AS loss_avg, ' .
                'MAX(rtp_audio_in_packet_loss) AS loss_max, ' .
                'SUM(CASE WHEN rtp_audio_in_mos >= 4.3 THEN 1 ELSE 0 END) AS excellent, ' .
                'SUM(CASE WHEN rtp_audio_in_mos >= 4.0 AND rtp_audio_in_mos < 4.3 THEN 1 ELSE 0 END) AS good, ' .
                'SUM(CASE WHEN rtp_audio_in_mos >= 3.6 AND rtp_audio_in_mos < 4.0 THEN 1 ELSE 0 END) AS fair, ' .
                'SUM(CASE WHEN rtp_audio_in_mos < 3.6 THEN 1 ELSE 0 END) AS poor, ' .
                'SUM(CASE WHEN rtp_audio_in_mos < ? THEN 1 ELSE 0 END) AS below_threshold',
                [$poorThreshold]
            )
            ->first();

        $samples = (int) ($row->samples ?? 0);
        $roundOr = fn ($v, int $dp = 2) => $v === null ? null : round((float) $v, $dp);

        return [
            'samples' => $samples,
            'mos_inbound' => [
                'avg' => $roundOr($row->mos_avg ?? null),
                'min' => $roundOr($row->mos_min ?? null),
                'max' => $roundOr($row->mos_max ?? null),
                'p50' => $roundOr($row->mos_p50 ?? null),
                'p95' => $roundOr($row->mos_p95 ?? null),
            ],
            'mos_outbound' => [
                'avg' => $roundOr($row->mos_out_avg ?? null),
                'min' => $roundOr($row->mos_out_min ?? null),
                'max' => $roundOr($row->mos_out_max ?? null),
            ],
            'jitter_ms' => [
                'avg' => $roundOr($row->jitter_avg ?? null, 3),
                'max' => $roundOr($row->jitter_max ?? null, 3),
            ],
            'packet_loss' => [
                'avg' => $roundOr($row->loss_avg ?? null),
                'max' => $roundOr($row->loss_max ?? null),
            ],
            'distribution' => [
                'excellent_4_3_plus' => (int) ($row->excellent ?? 0),
                'good_4_0_4_3' => (int) ($row->good ?? 0),
                'fair_3_6_4_0' => (int) ($row->fair ?? 0),
                'poor_below_3_6' => (int) ($row->poor ?? 0),
            ],
            'poor_threshold' => $poorThreshold,
            'poor_calls_count' => (int) ($row->below_threshold ?? 0),
        ];
    }

    /**
     * Top N called destination numbers by call count.
     *
     * @return array<int, array<string, mixed>>
     */
    public function topDestinations(?string $domainUuid, CdrFilterData $filters, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));

        $rows = $this->scopedQuery($domainUuid, $filters)
            ->whereNotNull('destination_number')
            ->where('destination_number', '!=', '')
            ->selectRaw(
                'destination_number, ' .
                'COUNT(*) AS calls, ' .
                'COALESCE(SUM(billsec),0) AS total_billsec, ' .
                'COALESCE(AVG(NULLIF(billsec,0)),0) AS avg_billsec'
            )
            ->groupBy('destination_number')
            ->orderByRaw('COUNT(*) DESC')
            ->limit($limit)
            ->get();

        return $rows->map(fn ($r) => [
            'destination_number' => $r->destination_number,
            'calls' => (int) $r->calls,
            'total_billsec' => (int) $r->total_billsec,
            'avg_billsec' => (int) round((float) $r->avg_billsec),
            'cost' => null,
            'cost_currency' => null,
        ])->all();
    }

    protected function scopedQuery(?string $domainUuid, CdrFilterData $filters): Builder
    {
        $query = $this->queries->baseQuery($domainUuid, $filters->dateFromEpoch, $filters->dateToEpoch);
        return $this->queries->applyFilters($query, $filters);
    }

    private function formatIso($value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d\TH:i:s\Z');
        }
        $ts = strtotime((string) $value);
        return $ts === false ? null : gmdate('Y-m-d\TH:i:s\Z', $ts);
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
