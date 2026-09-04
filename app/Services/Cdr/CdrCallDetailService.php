<?php

namespace App\Services\Cdr;

use App\Data\Api\V1\Cdr\CdrCallDetailData;
use App\Models\CDR;
use App\Services\CallRecordingUrlService;

/**
 * Builds the CdrCallDetailData payload used by both the CDR detail
 * endpoint (GET /cdr/calls/{uuid}) and the cdr.finalized webhook, so the
 * two stay byte-for-byte the same shape.
 */
class CdrCallDetailService
{
    public function __construct(
        protected CallStatusResolver $statusResolver,
        protected CallRecordingUrlService $recordingUrls,
    ) {}

    public function detail(CDR $cdr): CdrCallDetailData
    {
        $relatedLegs = CDR::query()
            ->where('domain_uuid', $cdr->domain_uuid)
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

        $recording = $this->resolveRecording($cdr);

        return new CdrCallDetailData(
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
            mos_outbound: $cdr->rtp_audio_out_mos === null ? null : (float) $cdr->rtp_audio_out_mos,
            jitter_ms: $cdr->rtp_audio_in_jitter_ms === null ? null : (float) $cdr->rtp_audio_in_jitter_ms,
            packet_loss: $cdr->rtp_audio_in_packet_loss === null ? null : (float) $cdr->rtp_audio_in_packet_loss,
            cost: $cdr->call_cost === null ? null : (float) $cdr->call_cost,
            cost_currency: $cdr->call_cost_currency,
            has_recording: ! empty($cdr->record_name),
            recording_url: $recording['audio_url'] ?? null,
            recording_storage: $recording['storage'] ?? null,
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
    }

    private function resolveRecording(CDR $cdr): array
    {
        if (empty($cdr->record_name) && empty($cdr->archive_recording?->object_key)) {
            return [];
        }

        return $this->recordingUrls->urlsForCdr((string) $cdr->xml_cdr_uuid, 1800);
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

    private function epochToIso($epoch): ?string
    {
        $epoch = (int) ($epoch ?? 0);
        return $epoch > 0 ? gmdate('Y-m-d\TH:i:s\Z', $epoch) : null;
    }
}
