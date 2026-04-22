<?php

namespace App\Services\Cdr;

use App\Enums\Cdr\CallStatus;

/**
 * Derives a normalized CallStatus from the raw FreeSWITCH CDR columns.
 *
 * This mirrors the logic used by the Inertia Vue CDR page so API consumers
 * and UI consumers agree on what a call's status is.
 */
class CallStatusResolver
{
    public function resolve(object $cdr): CallStatus
    {
        $voicemail = (bool) ($cdr->voicemail_message ?? false);
        if ($voicemail) {
            return CallStatus::Voicemail;
        }

        $missed = (bool) ($cdr->missed_call ?? false);
        $hangup = (string) ($cdr->hangup_cause ?? '');
        $ccCancel = (string) ($cdr->cc_cancel_reason ?? '');
        $ccCause = (string) ($cdr->cc_cause ?? '');
        $answerEpoch = (int) ($cdr->answer_epoch ?? 0);

        if ($missed && $hangup === 'NORMAL_CLEARING' && $ccCancel === 'BREAK_OUT' && $ccCause === 'cancel') {
            return CallStatus::Abandoned;
        }

        if ($missed && $hangup === 'NORMAL_CLEARING') {
            return CallStatus::Missed;
        }

        if ($hangup === 'USER_BUSY') {
            return CallStatus::Busy;
        }

        if (in_array($hangup, ['NO_ANSWER', 'NO_USER_RESPONSE', 'ALLOTTED_TIMEOUT'], true)) {
            return CallStatus::NoAnswer;
        }

        if ($answerEpoch > 0 && $hangup === 'NORMAL_CLEARING') {
            return CallStatus::Answered;
        }

        if ($answerEpoch === 0) {
            return CallStatus::Failed;
        }

        return CallStatus::Answered;
    }
}
