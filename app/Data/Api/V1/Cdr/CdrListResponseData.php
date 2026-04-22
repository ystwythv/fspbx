<?php

namespace App\Data\Api\V1\Cdr;

use Spatie\LaravelData\Data;

class CdrListResponseData extends Data
{
    /**
     * @param array<int, CdrCallData> $data
     */
    public function __construct(
        public string $object,
        public string $url,
        public bool $has_more,
        public array $data,
    ) {}
}
