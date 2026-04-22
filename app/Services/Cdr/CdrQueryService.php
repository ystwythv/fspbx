<?php

namespace App\Services\Cdr;

use App\Data\Api\V1\Cdr\CdrFilterData;
use App\Enums\Cdr\CallStatus;
use App\Models\CDR;
use Illuminate\Database\Eloquent\Builder;

/**
 * Domain-scoped CDR query builder for the API.
 *
 * Deliberately separate from the session-coupled CdrDataService so existing
 * Inertia pages keep working without modification.
 */
class CdrQueryService
{
    /**
     * Base query scoped to a single domain and a date window on start_epoch.
     * Excludes LOSE_RACE + child legs to match the existing Vue CDR list.
     */
    public function baseQuery(string $domainUuid, int $fromEpoch, int $toEpoch): Builder
    {
        return CDR::query()
            ->where('domain_uuid', $domainUuid)
            ->where('start_epoch', '>=', $fromEpoch)
            ->where('start_epoch', '<=', $toEpoch)
            ->where(function ($q) {
                $q->where('hangup_cause', '!=', 'LOSE_RACE')
                  ->orWhereNull('hangup_cause');
            })
            ->whereNull('cc_member_session_uuid')
            ->whereNull('originating_leg_uuid');
    }

    public function applyFilters(Builder $query, CdrFilterData $filters): Builder
    {
        if ($filters->direction !== null) {
            $query->where('direction', $filters->direction->value);
        }

        if ($filters->hangupCause !== null) {
            $query->where('hangup_cause', $filters->hangupCause);
        }

        if ($filters->extensionUuid !== null) {
            $query->where('extension_uuid', $filters->extensionUuid);
        }

        if ($filters->queueUuid !== null) {
            $query->where('call_center_queue_uuid', $filters->queueUuid);
        }

        if ($filters->callerNumber !== null && $filters->callerNumber !== '') {
            $query->where('caller_id_number', 'ilike', '%' . $filters->callerNumber . '%');
        }

        if ($filters->destinationNumber !== null && $filters->destinationNumber !== '') {
            $query->where(function ($q) use ($filters) {
                $q->where('destination_number', 'ilike', '%' . $filters->destinationNumber . '%')
                  ->orWhere('caller_destination', 'ilike', '%' . $filters->destinationNumber . '%');
            });
        }

        if ($filters->minDuration !== null) {
            $query->where('duration', '>=', $filters->minDuration);
        }

        if ($filters->maxDuration !== null) {
            $query->where('duration', '<=', $filters->maxDuration);
        }

        if ($filters->minMos !== null) {
            $query->where('rtp_audio_in_mos', '>=', $filters->minMos);
        }

        if ($filters->hasRecording === true) {
            $query->whereNotNull('record_name')->where('record_name', '!=', '');
        } elseif ($filters->hasRecording === false) {
            $query->where(function ($q) {
                $q->whereNull('record_name')->orWhere('record_name', '=', '');
            });
        }

        if ($filters->status !== null) {
            $this->applyStatus($query, $filters->status);
        }

        return $query;
    }

    /**
     * Cursor paginate on xml_cdr_uuid for stable pagination. Returns
     * [Collection $rows, bool $hasMore].
     */
    public function paginate(Builder $query, int $limit, ?string $startingAfter = null): array
    {
        $query = $query->clone()
            ->reorder('xml_cdr_uuid')
            ->orderBy('xml_cdr_uuid');

        if ($startingAfter !== null && $startingAfter !== '') {
            $query->where('xml_cdr_uuid', '>', $startingAfter);
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;

        return [$rows->take($limit), $hasMore];
    }

    private function applyStatus(Builder $query, CallStatus $status): void
    {
        switch ($status) {
            case CallStatus::Voicemail:
                $query->where('voicemail_message', true);
                break;

            case CallStatus::Abandoned:
                $query->where('voicemail_message', false)
                      ->where('missed_call', true)
                      ->where('hangup_cause', 'NORMAL_CLEARING')
                      ->where('cc_cancel_reason', 'BREAK_OUT')
                      ->where('cc_cause', 'cancel');
                break;

            case CallStatus::Missed:
                $query->where('voicemail_message', false)
                      ->where('missed_call', true)
                      ->where('hangup_cause', 'NORMAL_CLEARING')
                      ->where(function ($q) {
                          $q->whereNull('cc_cancel_reason')->orWhere('cc_cancel_reason', '!=', 'BREAK_OUT');
                      });
                break;

            case CallStatus::Busy:
                $query->where('hangup_cause', 'USER_BUSY');
                break;

            case CallStatus::NoAnswer:
                $query->whereIn('hangup_cause', ['NO_ANSWER', 'NO_USER_RESPONSE', 'ALLOTTED_TIMEOUT']);
                break;

            case CallStatus::Answered:
                $query->where('answer_epoch', '>', 0)
                      ->where('hangup_cause', 'NORMAL_CLEARING')
                      ->where(function ($q) {
                          $q->where('voicemail_message', false)
                            ->where(function ($q2) {
                                $q2->whereNull('missed_call')->orWhere('missed_call', false);
                            });
                      });
                break;

            case CallStatus::Failed:
                $query->where(function ($q) {
                    $q->whereNull('answer_epoch')->orWhere('answer_epoch', 0);
                })
                ->whereNotIn('hangup_cause', [
                    'NORMAL_CLEARING', 'USER_BUSY',
                    'NO_ANSWER', 'NO_USER_RESPONSE', 'ALLOTTED_TIMEOUT',
                ]);
                break;
        }
    }
}
