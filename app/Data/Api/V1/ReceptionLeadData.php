<?php

namespace App\Data\Api\V1;

use Spatie\LaravelData\Data;

class ReceptionLeadData extends Data
{
    public function __construct(
        public string $reception_lead_uuid,
        public string $object,
        public string $domain_uuid,

        public ?string $conversation_id = null,
        public ?string $caller_number = null,
        public ?string $name = null,
        public ?string $postcode = null,
        public ?string $job_description = null,
        public ?string $urgency = null,
        public ?string $status = null,

        public ?string $created_at = null,
    ) {}
}
