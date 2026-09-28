<?php

namespace App\Services\Voxra;

use App\Services\ProvisionLineService;
use App\Services\Tts\PromptTts;
use Illuminate\Support\Facades\Storage;

/**
 * A shared, unbranded Voxra TTS prompt (one WAV for every tenant), generated
 * once per node via ElevenLabs (the voicemail-greeting voice; Telnyx TTS
 * when ElevenLabs fails, see PromptTts) into
 * /var/lib/freeswitch/recordings/voxra/ and reused. The file name carries a
 * text+voice hash, so changing either regenerates it. Used by the
 * suspended-number announcement (voxragtm#173) and the owner-call recording
 * announcement (voxragtm#157).
 *
 * Recordings are per node (not shared storage): a prompt generated on the
 * node that handled the provision call is missing on the other one until
 * `voxra:ensure-prompts` (scheduled hourly on every node) creates it there,
 * so dialplans that play one should fall back when the file is absent.
 */
final class VoxraTtsPrompt
{
    /** Folder under the recordings disk (/var/lib/freeswitch/recordings). */
    public const DIR = 'voxra';

    public static function relativePath(string $prefix, string $text, string $voice): string
    {
        return self::DIR . '/' . $prefix . '-' . substr(hash('sha256', $text . '|' . $voice), 0, 12) . '.wav';
    }

    /** Absolute path the prompt lives at on this node (whether or not it
     *  exists yet), or null when the text or voice isn't configured. */
    public static function path(string $prefix, string $text, string $voice): ?string
    {
        $text = trim($text);
        if ($text === '' || $voice === '') {
            return null;
        }

        return Storage::disk('recordings')->path(self::relativePath($prefix, $text, $voice));
    }

    /**
     * Absolute path of the prompt WAV, generating it if missing; null when it
     * can't be produced (no text/voice, every TTS provider failing).
     * Never throws.
     */
    public static function ensure(string $prefix, string $text, string $voice, string $label): ?string
    {
        try {
            $text = trim($text);
            if ($text === '' || $voice === '') {
                return null;
            }

            $relative = self::relativePath($prefix, $text, $voice);
            $disk = Storage::disk('recordings');
            if ($disk->exists($relative)) {
                return $disk->path($relative);
            }

            ['pcm' => $pcm, 'provider' => $provider] = app(PromptTts::class)->pcm16k($text, $voice, $label);

            $disk->put($relative, ProvisionLineService::pcmToWav($pcm, 16000));
            // match the perms FreeSWITCH writes its own files with
            @chmod($disk->path($relative), 0660);
            logger('Voxra ' . $label . ' generated (' . $relative . ') via ' . $provider);

            return $disk->path($relative);
        } catch (\Throwable $e) {
            logger()->warning('Voxra ' . $label . ' unavailable, using the fallback: ' . $e->getMessage());

            return null;
        }
    }
}
