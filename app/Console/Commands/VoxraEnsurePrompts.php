<?php

namespace App\Console\Commands;

use App\Services\Voxra\VoxraOwnerCallRecording;
use App\Services\Voxra\VoxraSuspendedAnnouncement;
use Illuminate\Console\Command;

/**
 * Make sure this node has the shared Voxra TTS prompts (voxragtm#157/#173).
 * Recordings aren't shared between PBX nodes, and a prompt is generated on
 * whichever node handled the provision call; this creates it on the others.
 * Cheap when the files exist (no TTS call). Dialplans fall back to a stock
 * prompt / SIT tones until it has run.
 */
class VoxraEnsurePrompts extends Command
{
    protected $signature = 'voxra:ensure-prompts';

    protected $description = 'Generate the shared Voxra TTS prompts on this node if missing';

    public function handle(VoxraOwnerCallRecording $owner, VoxraSuspendedAnnouncement $suspended): int
    {
        $this->line('owner-call recording announcement: ' . ($owner->ensurePrompt() ?? 'unavailable (stock prompt used)'));
        $this->line('suspended-number announcement: ' . ($suspended->ensure() ?? 'unavailable (SIT tones used)'));

        return Command::SUCCESS;
    }
}
