<?php

namespace App\Services\Voxra;

use App\Services\ProvisionLineService;
use App\Services\Tts\ElevenLabsTtsService;
use Illuminate\Support\Facades\Storage;

/**
 * The "this number is temporarily unavailable" announcement a suspended Voxra
 * number plays before hanging up (voxragtm#173). One shared, unbranded WAV
 * for every tenant, generated once via ElevenLabs (same voice as the
 * voicemail greetings) into the FreeSWITCH recordings dir and reused; the
 * file name carries a text+voice hash, so changing either regenerates it.
 *
 * Best-effort: without ELEVENLABS_API_KEY or on a TTS failure the suspended
 * DID plays the standard special-information tones instead (the "number
 * unavailable" signal, no file needed). Suspension itself never fails.
 */
class VoxraSuspendedAnnouncement
{
    /** Folder under the recordings disk (/var/lib/freeswitch/recordings). */
    public const DIR = 'voxra';

    /** Special information tones (ITU-T E.180 / "number unavailable"), x3. */
    public const FALLBACK_TONE = 'tone_stream://%(274,0,914);%(274,0,1371);%(380,500,1777);loops=3';

    /** What the suspended DID should play: the TTS file, or the SIT fallback. */
    public function playbackTarget(): string
    {
        return $this->ensure() ?? self::FALLBACK_TONE;
    }

    /** Absolute path of the announcement WAV, generating it if missing; null
     *  when it can't be produced. */
    public function ensure(): ?string
    {
        try {
            $text = trim((string) config('services.voxra.suspended_announcement_text', ''));
            $voice = (string) config('services.voxra.vm_greeting_voice', '');
            if ($text === '' || $voice === '') {
                return null;
            }

            $relative = self::relativePath($text, $voice);
            $disk = Storage::disk('recordings');
            if ($disk->exists($relative)) {
                return $disk->path($relative);
            }

            $pcm = (new ElevenLabsTtsService())->textToSpeech($text, [
                'voice' => $voice,
                'response_format' => 'pcm',
            ]);
            if (strlen($pcm) < 1000) {
                throw new \RuntimeException('ElevenLabs returned implausibly short audio (' . strlen($pcm) . ' bytes)');
            }

            $disk->put($relative, ProvisionLineService::pcmToWav($pcm, 16000));
            // match the perms FreeSWITCH writes its own files with
            @chmod($disk->path($relative), 0660);
            logger('Voxra suspended-number announcement generated (' . $relative . ')');

            return $disk->path($relative);
        } catch (\Throwable $e) {
            logger()->warning('Voxra suspended-number announcement unavailable, using SIT tones: ' . $e->getMessage());

            return null;
        }
    }

    public static function relativePath(string $text, string $voice): string
    {
        return self::DIR . '/number-unavailable-' . substr(hash('sha256', $text . '|' . $voice), 0, 12) . '.wav';
    }
}
