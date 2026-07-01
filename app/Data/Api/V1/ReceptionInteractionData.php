<?php

namespace App\Data\Api\V1;

use Spatie\LaravelData\Data;

class ReceptionInteractionData extends Data
{
    public function __construct(
        public string $reception_interaction_uuid,
        public string $object,
        public ?string $channel = null,
        public ?string $summary = null,
        public ?string $outcome = null,
        public ?string $occurred_at = null,
    ) {}
}
