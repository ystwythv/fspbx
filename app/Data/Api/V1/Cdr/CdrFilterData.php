<?php

namespace App\Data\Api\V1\Cdr;

use App\Enums\Cdr\CallDirection;
use App\Enums\Cdr\CallStatus;
use Spatie\LaravelData\Data;

class CdrFilterData extends Data
{
    public function __construct(
        public int $dateFromEpoch,
        public int $dateToEpoch,
        public ?CallDirection $direction = null,
        public ?CallStatus $status = null,
        public ?string $hangupCause = null,
        public ?string $extensionUuid = null,
        public ?string $queueUuid = null,
        public ?string $callerNumber = null,
        public ?string $destinationNumber = null,
        public ?int $minDuration = null,
        public ?int $maxDuration = null,
        public ?float $minMos = null,
        public ?bool $hasRecording = null,
    ) {}
}
