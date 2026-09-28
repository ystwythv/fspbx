<?php

namespace App\Console\Commands;

use App\Services\Voxra\VoxraLineGreetingSync;
use App\Services\Voxra\VoxraOwnerCallRecording;
use App\Services\Voxra\VoxraSuspendedAnnouncement;
use Illuminate\Console\Command;

/**
 * Make sure this node has the Voxra TTS audio (voxragtm#157/#162/#173): the
 * shared prompts, and each Voxra Line tenant's voicemail greeting.
 * Recordings and voicemail storage aren't shared between PBX nodes, and the
 * audio is generated on whichever node handled the provision call; this
 * creates it on the others. Cheap when the files exist (no TTS call).
 * Dialplans fall back to a stock prompt / SIT tones, and voicemail to the
 * stock greeting, until it has run.
 */
class VoxraEnsurePrompts extends Command
{
    protected $signature = 'voxra:ensure-prompts';

    protected $description = 'Generate the shared Voxra TTS prompts and Line voicemail greetings on this node if missing';

    public function handle(
        VoxraOwnerCallRecording $owner,
        VoxraSuspendedAnnouncement $suspended,
        VoxraLineGreetingSync $lineGreetings,
    ): int {
        $this->line('owner-call recording announcement: ' . ($owner->ensurePrompt() ?? 'unavailable (stock prompt used)'));
        $this->line('suspended-number announcement: ' . ($suspended->ensure() ?? 'unavailable (SIT tones used)'));

        foreach ($lineGreetings->ensureOnThisNode() as $r) {
            $this->line('line greeting ' . $r['domain'] . ($r['file'] !== '' ? '/' . $r['file'] : '')
                . ': ' . $r['status'] . ($r['detail'] !== '' ? ' (' . $r['detail'] . ')' : ''));
        }

        return Command::SUCCESS;
    }
}
