<?php

namespace App\Services\Voxra;

/**
 * AI + recording disclosure for Voxra reception agents (voxragtm#83).
 *
 * voxraweb builds each tenant's opening line (greeting + disclosure, the
 * tenant chooses the wording) and sends it on provision-tenant; this class is
 * the PBX-side backstop so no Voxra assistant can ever greet a caller without
 * saying it is an AI assistant (and, while Telnyx recording is on, that calls
 * may be recorded). Mirrors voxraweb src/lib/privacy/disclosure.ts.
 */
class VoxraDisclosure
{
    /** fspbx caps first_message at 500 chars. */
    public const MAX_GREETING = 480;

    /** Default for the {{recording_notice}} prompt variable when the
     *  dynamic-variables webhook doesn't answer in time. */
    public const DEFAULT_RECORDING_NOTICE = 'This call is being recorded. If asked, say just that — don\'t go into detail. If the caller objects, offer to take a short message for a call back instead.';

    private const AI_RE = '/\b(a\.?\s?i\.?|artificial intelligence|virtual (assistant|receptionist)|automated (assistant|receptionist|system)|digital (assistant|receptionist)|not a (real )?(person|human))\b/i';

    private const RECORDING_RE = '/\brecord(ed|ing|s)?\b/i';

    public static function mentionsAi(?string $text): bool
    {
        return (bool) preg_match(self::AI_RE, (string) $text);
    }

    public static function mentionsRecording(?string $text): bool
    {
        return (bool) preg_match(self::RECORDING_RE, (string) $text);
    }

    public static function disclosure(bool $recording): string
    {
        return $recording
            ? "Just so you know, I'm an AI assistant and this call is being recorded."
            : "Just so you know, I'm an AI assistant and I'll keep a written note of our call.";
    }

    public static function defaultGreeting(string $businessName, bool $recording): string
    {
        $name = trim($businessName);
        $hello = $name !== '' ? "Hi, thanks for calling {$name}." : 'Hi, thanks for calling.';

        return $hello . ' ' . self::disclosure($recording) . ' How can I help?';
    }

    /**
     * The greeting to give the assistant: the one supplied if it already
     * discloses what it must, otherwise with the disclosure worked in
     * (before a closing question, else appended). Empty → default greeting.
     */
    public static function ensure(?string $greeting, string $businessName, bool $recording): string
    {
        $g = trim(preg_replace('/\s+/', ' ', (string) $greeting));
        if ($g === '') {
            return self::defaultGreeting($businessName, $recording);
        }
        if (mb_strlen($g) <= self::MAX_GREETING
            && self::mentionsAi($g)
            && (! $recording || self::mentionsRecording($g))) {
            return $g;
        }

        $d = self::disclosure($recording);
        if (str_ends_with($g, '?') && preg_match('/^(.*[.!])\s+([^.!?]*\?)$/u', $g, $m)) {
            $line = "{$m[1]} {$d} {$m[2]}";
        } else {
            $line = "{$g} {$d}";
        }
        if (mb_strlen($line) > self::MAX_GREETING) {
            $room = max(0, self::MAX_GREETING - mb_strlen($d) - 1);
            $line = trim(mb_substr($g, 0, $room)) . ' ' . $d;
        }

        return $line;
    }
}
