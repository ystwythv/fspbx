<?php

namespace App\Enums\Cdr;

enum CallDirection: string
{
    case Inbound = 'inbound';
    case Outbound = 'outbound';
    case Local = 'local';

    public static function tryFromLoose(?string $value): ?self
    {
        if ($value === null || $value === '') {
            return null;
        }
        return self::tryFrom(strtolower($value));
    }
}
