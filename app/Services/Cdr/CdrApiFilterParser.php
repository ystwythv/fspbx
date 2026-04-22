<?php

namespace App\Services\Cdr;

use App\Data\Api\V1\Cdr\CdrFilterData;
use App\Enums\Cdr\CallDirection;
use App\Enums\Cdr\CallStatus;
use App\Exceptions\ApiException;
use Illuminate\Http\Request;

class CdrApiFilterParser
{
    public function __construct(
        private readonly int $maxWindowSeconds = 30 * 86400,
        private readonly int $maxAgeSeconds = 90 * 86400,
    ) {}

    public function fromRequest(Request $request): CdrFilterData
    {
        $dateFrom = (string) $request->query('date_from', '');
        $dateTo = (string) $request->query('date_to', '');

        if ($dateFrom === '' || $dateTo === '') {
            throw new ApiException(
                422,
                'invalid_request_error',
                'date_from and date_to are required.',
                'parameter_missing',
                $dateFrom === '' ? 'date_from' : 'date_to',
            );
        }

        $fromEpoch = strtotime($dateFrom);
        $toEpoch = strtotime($dateTo);

        if ($fromEpoch === false || $toEpoch === false) {
            throw new ApiException(
                422,
                'invalid_request_error',
                'Invalid date format. Use ISO 8601 (e.g. 2026-04-01T00:00:00Z).',
                'invalid_request',
                $fromEpoch === false ? 'date_from' : 'date_to',
            );
        }

        if ($toEpoch <= $fromEpoch) {
            throw new ApiException(
                422,
                'invalid_request_error',
                'date_to must be after date_from.',
                'invalid_request',
                'date_to',
            );
        }

        if (($toEpoch - $fromEpoch) > $this->maxWindowSeconds) {
            throw new ApiException(
                422,
                'invalid_request_error',
                'Window exceeds the maximum of ' . ((int) ($this->maxWindowSeconds / 86400)) . ' days.',
                'window_too_large',
                'date_to',
            );
        }

        if ((time() - $fromEpoch) > $this->maxAgeSeconds) {
            throw new ApiException(
                422,
                'invalid_request_error',
                'date_from is older than the retention window (' . ((int) ($this->maxAgeSeconds / 86400)) . ' days).',
                'window_too_old',
                'date_from',
            );
        }

        $direction = CallDirection::tryFromLoose($request->query('direction'));
        if ($request->query('direction') !== null && $direction === null) {
            throw new ApiException(
                422,
                'invalid_request_error',
                'Invalid direction. Expected: inbound, outbound, or local.',
                'invalid_request',
                'direction',
            );
        }

        $status = CallStatus::tryFromLoose($request->query('status'));
        if ($request->query('status') !== null && $status === null) {
            throw new ApiException(
                422,
                'invalid_request_error',
                'Invalid status.',
                'invalid_request',
                'status',
            );
        }

        return new CdrFilterData(
            dateFromEpoch: $fromEpoch,
            dateToEpoch: $toEpoch,
            direction: $direction,
            status: $status,
            hangupCause: $this->str($request->query('hangup_cause')),
            extensionUuid: $this->str($request->query('extension_uuid')),
            queueUuid: $this->str($request->query('queue_uuid')),
            callerNumber: $this->str($request->query('caller_number')),
            destinationNumber: $this->str($request->query('destination_number')),
            minDuration: $this->intOrNull($request->query('min_duration')),
            maxDuration: $this->intOrNull($request->query('max_duration')),
            minMos: $this->floatOrNull($request->query('min_mos')),
            hasRecording: $this->boolOrNull($request->query('has_recording')),
        );
    }

    private function str($v): ?string
    {
        if ($v === null) return null;
        $t = trim((string) $v);
        return $t === '' ? null : $t;
    }

    private function intOrNull($v): ?int
    {
        $s = $this->str($v);
        return $s === null ? null : (int) $s;
    }

    private function floatOrNull($v): ?float
    {
        $s = $this->str($v);
        return $s === null ? null : (float) $s;
    }

    private function boolOrNull($v): ?bool
    {
        if ($v === null || $v === '') return null;
        return filter_var($v, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    }
}
