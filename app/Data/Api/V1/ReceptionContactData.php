<?php

namespace App\Data\Api\V1;

use Spatie\LaravelData\Data;

class ReceptionContactData extends Data
{
    public function __construct(
        public string $reception_contact_uuid,
        public string $object,
        public string $domain_uuid,
        public ?string $phone_number = null,
        public ?string $name = null,
        public int $total_calls = 0,
        public int $total_bookings = 0,
        public ?string $last_seen_at = null,
        public ?string $notes = null,
        /** @var array<int, ReceptionInteractionData> */
        public ?array $recent = null,
    ) {}
}
