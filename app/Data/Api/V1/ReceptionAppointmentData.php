<?php

namespace App\Data\Api\V1;

use Spatie\LaravelData\Data;

class ReceptionAppointmentData extends Data
{
    public function __construct(
        public string $reception_appointment_uuid,
        public string $object,
        public string $domain_uuid,

        public ?string $reception_lead_uuid = null,
        public ?string $conversation_id = null,
        public ?string $customer_name = null,
        public ?string $customer_number = null,
        public ?string $service = null,
        public ?string $starts_at = null,
        public ?string $ends_at = null,
        public ?string $deposit_amount = null,
        public ?string $status = null,
    ) {}
}
