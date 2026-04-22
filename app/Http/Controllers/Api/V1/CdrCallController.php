<?php

namespace App\Http\Controllers\Api\V1;

use App\Data\Api\V1\Cdr\CdrCallData;
use App\Data\Api\V1\Cdr\CdrCallDetailData;
use App\Data\Api\V1\Cdr\CdrListResponseData;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\CDR;
use App\Services\CallRecordingUrlService;
use App\Services\Cdr\CallStatusResolver;
use App\Services\Cdr\CdrApiFilterParser;
use App\Services\Cdr\CdrQueryService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CdrCallController extends Controller
{
    public function __construct(
        protected CdrQueryService $queries,
        protected CallStatusResolver $statusResolver,
        protected CallRecordingUrlService $recordingUrls,
        protected CdrApiFilterParser $filterParser,
    ) {}

    /**
     * List call detail records
     *
     * Returns CDRs for the specified domain within a bounded date window.
     *
     * Access rules:
     * - Tenant token: must match the domain_uuid in the URL.
     * - Global token: may access any domain.
     * - Caller must have the `cdr_api_read` permission.
     *
     * Window rules:
     * - `date_from` and `date_to` are required, ISO 8601 UTC.
     * - The window may not exceed 30 days.
     * - `date_from` must be within the last 90 days.
     *
     * Pagination (cursor-based):
     * - Order is by `xml_cdr_uuid`. Default `limit` is 25, max 100.
     * - Pass `starting_after` equal to the previous page's last `xml_cdr_uuid`.
     *
     * @group CDR
     * @authenticated
     *
     * @urlParam domain_uuid string required The domain UUID.
     *
     * @queryParam date_from string required ISO 8601 start of window. Example: 2026-04-01T00:00:00Z
     * @queryParam date_to string required ISO 8601 end of window. Example: 2026-04-15T00:00:00Z
     * @queryParam direction string One of: inbound, outbound, local.
     * @queryParam status string One of: answered, missed, voicemail, abandoned, busy, no_answer, failed.
     * @queryParam hangup_cause string FreeSWITCH hangup cause (e.g. NORMAL_CLEARING, USER_BUSY).
     * @queryParam extension_uuid string Filter to a single extension.
     * @queryParam queue_uuid string Filter to a single call-center queue.
     * @queryParam caller_number string Substring match on caller_id_number.
     * @queryParam destination_number string Substring match on destination_number / caller_destination.
     * @queryParam min_duration int Seconds.
     * @queryParam max_duration int Seconds.
     * @queryParam min_mos float 1.0–5.0.
     * @queryParam has_recording bool
     * @queryParam limit int 1–100 (default 25).
     * @queryParam starting_after string Last xml_cdr_uuid from the previous page.
     */
    public function index(Request $request, string $domain_uuid)
    {
        $this->assertUuid($domain_uuid, 'domain_uuid');

        $filters = $this->filterParser->fromRequest($request);

        $limit = $this->parseLimit($request);
        $startingAfter = trim((string) $request->query('starting_after', '')) ?: null;

        $query = $this->queries->baseQuery($domain_uuid, $filters->dateFromEpoch, $filters->dateToEpoch);
        $this->queries->applyFilters($query, $filters);

        [$rows, $hasMore] = $this->queries->paginate($query, $limit, $startingAfter);

        $data = $rows->map(fn (CDR $cdr) => $this->toCallData($cdr))->all();

        $payload = new CdrListResponseData(
            object: 'list',
            url: '/api/v1/domains/' . $domain_uuid . '/cdr/calls',
            has_more: $hasMore,
            data: $data,
        );

        return response()->json($payload->toArray(), 200);
    }

    /**
     * Retrieve a single CDR.
     *
     * @group CDR
     * @authenticated
     *
     * @urlParam domain_uuid string required The domain UUID.
     * @urlParam xml_cdr_uuid string required The CDR UUID.
     */
    public function show(Request $request, string $domain_uuid, string $xml_cdr_uuid)
    {
        $this->assertUuid($domain_uuid, 'domain_uuid');
        $this->assertUuid($xml_cdr_uuid, 'xml_cdr_uuid');

        $cdr = CDR::query()
            ->where('domain_uuid', $domain_uuid)
            ->where('xml_cdr_uuid', $xml_cdr_uuid)
            ->first();

        if (! $cdr) {
            throw new ApiException(
                404,
                'invalid_request_error',
                'CDR not found.',
                'resource_missing',
                'xml_cdr_uuid',
            );
        }

        $recordingUrl = $this->resolveRecordingUrl($cdr);
        $status = $this->statusResolver->resolve($cdr)->value;

        $relatedLegs = CDR::query()
            ->where('domain_uuid', $domain_uuid)
            ->where(function ($q) use ($cdr) {
                $q->where('originating_leg_uuid', $cdr->xml_cdr_uuid)
                  ->orWhere('cc_member_session_uuid', $cdr->xml_cdr_uuid);
            })
            ->orderBy('start_epoch')
            ->limit(50)
            ->get()
            ->map(fn (CDR $leg) => [
                'xml_cdr_uuid' => (string) $leg->xml_cdr_uuid,
                'direction' => $leg->direction,
                'destination_number' => $leg->destination_number,
                'duration' => (int) ($leg->duration ?? 0),
                'billsec' => (int) ($leg->billsec ?? 0),
                'hangup_cause' => $leg->hangup_cause,
                'start_time' => $this->epochToIso($leg->start_epoch),
            ])
            ->all();

        $payload = new CdrCallDetailData(
            xml_cdr_uuid: (string) $cdr->xml_cdr_uuid,
            object: 'cdr_call',
            domain_uuid: (string) $cdr->domain_uuid,
            direction: $cdr->direction,
            status: $status,
            caller_id_name: $cdr->caller_id_name,
            caller_id_number: $cdr->caller_id_number,
            destination_number: $cdr->destination_number,
            caller_destination: $cdr->caller_destination,
            start_time: $this->epochToIso($cdr->start_epoch),
            answer_time: $this->epochToIso($cdr->answer_epoch),
            end_time: $this->epochToIso($cdr->end_epoch),
            duration: $cdr->duration === null ? null : (int) $cdr->duration,
            billsec: $cdr->billsec === null ? null : (int) $cdr->billsec,
            hangup_cause: $cdr->hangup_cause,
            hangup_cause_q850: $cdr->hangup_cause_q850 === null ? null : (int) $cdr->hangup_cause_q850,
            sip_hangup_disposition: $cdr->sip_hangup_disposition,
            extension_uuid: $cdr->extension_uuid,
            queue_uuid: $cdr->call_center_queue_uuid,
            mos_inbound: $cdr->rtp_audio_in_mos === null ? null : (float) $cdr->rtp_audio_in_mos,
            mos_outbound: $cdr->rtp_audio_out_mos === null ? null : (float) $cdr->rtp_audio_out_mos,
            jitter_ms: $cdr->rtp_audio_in_jitter_ms === null ? null : (float) $cdr->rtp_audio_in_jitter_ms,
            packet_loss: $cdr->rtp_audio_in_packet_loss === null ? null : (float) $cdr->rtp_audio_in_packet_loss,
            cost: null,
            cost_currency: null,
            has_recording: ! empty($cdr->record_name),
            recording_url: $recordingUrl,
            sip_call_id: $cdr->sip_call_id,
            pdd_ms: $cdr->pdd_ms === null ? null : (int) $cdr->pdd_ms,
            read_codec: $cdr->read_codec ?? null,
            read_rate: isset($cdr->read_rate) ? (int) $cdr->read_rate : null,
            write_codec: $cdr->write_codec ?? null,
            write_rate: isset($cdr->write_rate) ? (int) $cdr->write_rate : null,
            remote_media_ip: $cdr->remote_media_ip ?? null,
            network_addr: $cdr->network_addr ?? null,
            accountcode: $cdr->accountcode ?? null,
            call_flow: $this->decodeCallFlow($cdr),
            related_legs: $relatedLegs,
        );

        return response()->json($payload->toArray(), 200);
    }

    private function toCallData(CDR $cdr): CdrCallData
    {
        return new CdrCallData(
            xml_cdr_uuid: (string) $cdr->xml_cdr_uuid,
            object: 'cdr_call',
            domain_uuid: (string) $cdr->domain_uuid,
            direction: $cdr->direction,
            status: $this->statusResolver->resolve($cdr)->value,
            caller_id_name: $cdr->caller_id_name,
            caller_id_number: $cdr->caller_id_number,
            destination_number: $cdr->destination_number,
            caller_destination: $cdr->caller_destination,
            start_time: $this->epochToIso($cdr->start_epoch),
            answer_time: $this->epochToIso($cdr->answer_epoch),
            end_time: $this->epochToIso($cdr->end_epoch),
            duration: $cdr->duration === null ? null : (int) $cdr->duration,
            billsec: $cdr->billsec === null ? null : (int) $cdr->billsec,
            hangup_cause: $cdr->hangup_cause,
            hangup_cause_q850: $cdr->hangup_cause_q850 === null ? null : (int) $cdr->hangup_cause_q850,
            sip_hangup_disposition: $cdr->sip_hangup_disposition,
            extension_uuid: $cdr->extension_uuid,
            queue_uuid: $cdr->call_center_queue_uuid,
            mos_inbound: $cdr->rtp_audio_in_mos === null ? null : (float) $cdr->rtp_audio_in_mos,
            cost: null,
            cost_currency: null,
            has_recording: ! empty($cdr->record_name),
            recording_url: null, // detail endpoint returns this; list stays light
            sip_call_id: $cdr->sip_call_id,
        );
    }

    /**
     * Stream CDRs as a CSV file.
     *
     * Honors the same filters + window rules as the JSON list endpoint, but
     * streams rows row-by-row (no pagination) to keep memory flat on large
     * exports. Capped at the server-configured max export rows.
     *
     * @group CDR
     * @authenticated
     */
    public function exportCsv(Request $request, string $domain_uuid)
    {
        $this->assertUuid($domain_uuid, 'domain_uuid');
        $filters = $this->filterParser->fromRequest($request);
        return $this->streamCsv($domain_uuid, $filters);
    }

    /**
     * Global (cross-domain) CSV export. Reachable via cdr.scope:global routes
     * only; accepts optional `?domain_uuid=` filter.
     *
     * @group CDR
     * @authenticated
     */
    public function globalExportCsv(Request $request)
    {
        $filters = $this->filterParser->fromRequest($request);
        $domainUuid = trim((string) $request->query('domain_uuid', '')) ?: null;
        if ($domainUuid !== null) {
            $this->assertUuid($domainUuid, 'domain_uuid');
        }
        return $this->streamCsv($domainUuid, $filters);
    }

    private function streamCsv(?string $domainUuid, \App\Data\Api\V1\Cdr\CdrFilterData $filters): StreamedResponse
    {
        $query = $this->queries->baseQuery($domainUuid, $filters->dateFromEpoch, $filters->dateToEpoch);
        $this->queries->applyFilters($query, $filters);
        $query->orderBy('start_epoch');

        $maxRows = (int) config('cdr.csv_max_rows', 250000);
        $filename = sprintf(
            'cdrs-%s-%s-to-%s.csv',
            $domainUuid ?? 'all',
            gmdate('Ymd', $filters->dateFromEpoch),
            gmdate('Ymd', $filters->dateToEpoch),
        );

        $resolver = $this->statusResolver;

        $callback = function () use ($query, $maxRows, $resolver) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [
                'xml_cdr_uuid', 'domain_uuid', 'direction', 'status',
                'caller_id_name', 'caller_id_number', 'destination_number',
                'start_time', 'answer_time', 'end_time',
                'duration_sec', 'billsec_sec',
                'hangup_cause', 'hangup_cause_q850', 'sip_hangup_disposition',
                'extension_uuid', 'queue_uuid',
                'mos_inbound', 'mos_outbound', 'jitter_ms', 'packet_loss',
                'has_recording', 'sip_call_id',
            ]);

            $written = 0;
            foreach ($query->lazyById(1000, 'xml_cdr_uuid') as $cdr) {
                fputcsv($out, [
                    (string) $cdr->xml_cdr_uuid,
                    (string) $cdr->domain_uuid,
                    $cdr->direction,
                    $resolver->resolve($cdr)->value,
                    $cdr->caller_id_name,
                    $cdr->caller_id_number,
                    $cdr->destination_number,
                    $cdr->start_epoch ? gmdate('Y-m-d\TH:i:s\Z', (int) $cdr->start_epoch) : '',
                    $cdr->answer_epoch ? gmdate('Y-m-d\TH:i:s\Z', (int) $cdr->answer_epoch) : '',
                    $cdr->end_epoch ? gmdate('Y-m-d\TH:i:s\Z', (int) $cdr->end_epoch) : '',
                    $cdr->duration,
                    $cdr->billsec,
                    $cdr->hangup_cause,
                    $cdr->hangup_cause_q850,
                    $cdr->sip_hangup_disposition,
                    $cdr->extension_uuid,
                    $cdr->call_center_queue_uuid,
                    $cdr->rtp_audio_in_mos,
                    $cdr->rtp_audio_out_mos ?? null,
                    $cdr->rtp_audio_in_jitter_ms ?? null,
                    $cdr->rtp_audio_in_packet_loss ?? null,
                    empty($cdr->record_name) ? 'false' : 'true',
                    $cdr->sip_call_id,
                ]);

                if (++$written >= $maxRows) {
                    fputcsv($out, ['# Export truncated at ' . $maxRows . ' rows']);
                    break;
                }

                if ($written % 500 === 0 && function_exists('ob_flush')) {
                    @ob_flush();
                    @flush();
                }
            }

            fclose($out);
        };

        return response()->stream($callback, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Accel-Buffering' => 'no',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * List CDRs across the whole fleet.
     *
     * Reachable only via `cdr.scope:global`. Accepts an optional
     * `?domain_uuid=` query param to filter to a single tenant.
     *
     * @group CDR
     * @authenticated
     */
    public function globalIndex(Request $request)
    {
        $filters = $this->filterParser->fromRequest($request);

        $domainUuid = trim((string) $request->query('domain_uuid', '')) ?: null;
        if ($domainUuid !== null) {
            $this->assertUuid($domainUuid, 'domain_uuid');
        }

        $limit = $this->parseLimit($request);
        $startingAfter = trim((string) $request->query('starting_after', '')) ?: null;

        $query = $this->queries->baseQuery($domainUuid, $filters->dateFromEpoch, $filters->dateToEpoch);
        $this->queries->applyFilters($query, $filters);

        [$rows, $hasMore] = $this->queries->paginate($query, $limit, $startingAfter);

        $data = $rows->map(fn (CDR $cdr) => $this->toCallData($cdr))->all();

        $payload = new CdrListResponseData(
            object: 'list',
            url: '/api/v1/cdr/calls',
            has_more: $hasMore,
            data: $data,
        );

        $envelope = $payload->toArray();
        $envelope['scope'] = 'global';
        $envelope['domain_uuid_filter'] = $domainUuid;

        return response()->json($envelope, 200);
    }

    private function resolveRecordingUrl(CDR $cdr): ?string
    {
        if (empty($cdr->record_name) && empty($cdr->archive_recording?->object_key)) {
            return null;
        }

        $urls = $this->recordingUrls->urlsForCdr((string) $cdr->xml_cdr_uuid, 1800);
        return $urls['audio_url'] ?? null;
    }

    private function decodeCallFlow(CDR $cdr): ?array
    {
        $raw = $cdr->call_flow ?? null;
        if ($raw === null) {
            return null;
        }
        if (is_array($raw)) {
            return $raw;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function parseLimit(Request $request): int
    {
        $limit = (int) $request->query('limit', 25);
        return max(1, min(100, $limit));
    }

    private function assertUuid(string $value, string $field): void
    {
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $value)) {
            throw new ApiException(
                400,
                'invalid_request_error',
                'Invalid ' . $field . '.',
                'invalid_request',
                $field,
            );
        }
    }

    private function epochToIso($epoch): ?string
    {
        if (empty($epoch)) {
            return null;
        }
        return gmdate('Y-m-d\TH:i:s\Z', (int) $epoch);
    }
}
