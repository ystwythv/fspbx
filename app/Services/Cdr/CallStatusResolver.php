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

        // Voxra calls to an eSIM's mobile number are answered early by
        // push_wake.lua (ringing played to the caller) so the carrier can't
        // time out while the owner's handset rings (voxragtm#194). FusionPBX
        // then sees billsec > 0 and never flags them missed: if the call was
        // never connected to anyone (no bridge to the owner or the AI) the
        // caller hung up while it rang, which is a missed call.
        if ($answerEpoch > 0 && empty($cdr->bridge_uuid ?? null) && $this->preAnswered($cdr)) {
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

    /** push_wake.lua set voxra_pre_answered on this call (the CDR json's variables). */
    private function preAnswered(object $cdr): bool
    {
        $raw = $cdr->json ?? null;
        if (is_string($raw)) {
            if (! str_contains($raw, 'voxra_pre_answered')) {
                return false;
            }
            $raw = json_decode($raw, true);
        }
        if (is_object($raw)) {
            $raw = json_decode(json_encode($raw), true);
        }

        return is_array($raw) && (($raw['variables']['voxra_pre_answered'] ?? null) === 'true');
    }
}
