<?php

namespace App\Enums\Cdr;

enum CallStatus: string
{
    case Answered = 'answered';
    case Missed = 'missed';
    case Voicemail = 'voicemail';
    case Abandoned = 'abandoned';
    case Busy = 'busy';
    case NoAnswer = 'no_answer';
    case Failed = 'failed';

    public static function tryFromLoose(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }
        $normalized = str_replace([' ', '-'], '_', strtolower($value));
        return self::tryFrom($normalized);
    }
}
