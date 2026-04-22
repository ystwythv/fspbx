<?php

namespace App\Data\Api\V1\Cdr;

use Spatie\LaravelData\Data;

class CdrCallDetailData extends Data
{
    /**
     * @param array<string, mixed>|null $call_flow
     * @param array<int, array<string, mixed>> $related_legs
     */
    public function __construct(
        public string $xml_cdr_uuid,
        public string $object,
        public string $domain_uuid,
        public ?string $direction,
        public string $status,
        public ?string $caller_id_name,
        public ?string $caller_id_number,
        public ?string $destination_number,
        public ?string $caller_destination,
        public ?string $start_time,
        public ?string $answer_time,
        public ?string $end_time,
        public ?int $duration,
        public ?int $billsec,
        public ?string $hangup_cause,
        public ?int $hangup_cause_q850,
        public ?string $sip_hangup_disposition,
        public ?string $extension_uuid,
        public ?string $queue_uuid,
        public ?float $mos_inbound,
        public ?float $mos_outbound,
        public ?float $jitter_ms,
        public ?float $packet_loss,
        public ?float $cost,
        public ?string $cost_currency,
        public bool $has_recording,
        public ?string $recording_url,
        public ?string $sip_call_id,
        public ?int $pdd_ms,
        public ?string $read_codec,
        public ?int $read_rate,
        public ?string $write_codec,
        public ?int $write_rate,
        public ?string $remote_media_ip,
        public ?string $network_addr,
        public ?string $accountcode,
        public ?array $call_flow,
        public array $related_legs,
    ) {}
}
