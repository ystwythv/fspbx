<?php

namespace App\Services\Voxra;

/**
 * The "this number is temporarily unavailable" announcement a suspended Voxra
 * number plays before hanging up (voxragtm#173). One shared, unbranded WAV
 * for every tenant, generated once via ElevenLabs (same voice as the
 * voicemail greetings; Telnyx TTS if ElevenLabs fails) into the FreeSWITCH recordings dir and reused; the
 * file name carries a text+voice hash, so changing either regenerates it
 * (VoxraTtsPrompt).
 *
 * Best-effort: if every TTS provider fails the suspended
 * DID plays the standard special-information tones instead (the "number
 * unavailable" signal, no file needed). Suspension itself never fails.
 */
class VoxraSuspendedAnnouncement
{
    /** Folder under the recordings disk (/var/lib/freeswitch/recordings). */
    public const DIR = VoxraTtsPrompt::DIR;

    /** Special information tones (ITU-T E.180 / "number unavailable"), x3. */
    public const FALLBACK_TONE = 'tone_stream://%(274,0,914);%(274,0,1371);%(380,500,1777);loops=3';

    private const PREFIX = 'number-unavailable';

    /** What the suspended DID should play: the TTS file, or the SIT fallback. */
    public function playbackTarget(): string
    {
        return $this->ensure() ?? self::FALLBACK_TONE;
    }

    /** Absolute path of the announcement WAV, generating it if missing; null
     *  when it can't be produced. */
    public function ensure(): ?string
    {
        return VoxraTtsPrompt::ensure(
            self::PREFIX,
            (string) config('services.voxra.suspended_announcement_text', ''),
            (string) config('services.voxra.vm_greeting_voice', ''),
            'suspended-number announcement',
        );
    }

    public static function relativePath(string $text, string $voice): string
    {
        return VoxraTtsPrompt::relativePath(self::PREFIX, $text, $voice);
    }
}
