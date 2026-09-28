<?php

namespace App\Services\Voxra;

use App\Models\Domain;
use App\Models\Extensions;
use App\Models\VoicemailGreetings;
use App\Services\ProvisionLineService;
use App\Services\Tts\PromptTts;
use Illuminate\Support\Facades\Storage;

/**
 * Puts the Voxra Line voicemail greeting on every PBX node (voxragtm#157/#162).
 *
 * The greeting rows are in the replicated database, but the WAV sits on
 * node-local disk (/var/lib/freeswitch/storage/voicemail/default/<domain>/9260/).
 * ProvisionLineService::ensureVoicemailGreeting writes it only on the node that
 * took the provision call. So after a failover the other node found the row
 * selected, had no file, and played the stock greeting.
 *
 * `voxra:ensure-prompts` (hourly on every node) calls this. For each Voxra
 * tenant domain with the Line extension, it looks at every greeting row Voxra
 * made (GREETING_NAME plus the "voxra-tts:<hash> <text>" description). When the
 * file that row names is missing on this node, it speaks the stored text
 * again with the same TTS (PromptTts: ElevenLabs, then Telnyx) and writes the
 * same file: path, 16 kHz 16-bit mono WAV, 0660, owned by www-data.
 *
 * It only reads the database. It never creates rows or greeting ids, never
 * changes the box's selected greeting, and never touches greetings the owner
 * recorded or uploaded. When the file is already there, it makes no TTS call.
 */
final class VoxraLineGreetingSync
{
    /** FreeSWITCH runs as this user and must be able to read the greeting
     *  and write messages into the box folder. */
    public const FILE_OWNER = 'www-data';

    public const STATUS_PRESENT  = 'present';
    public const STATUS_RESTORED = 'restored';
    public const STATUS_SKIPPED  = 'skipped';
    public const STATUS_FAILED   = 'failed';

    /**
     * Restores missing Voxra Line greetings on this node. Never throws.
     *
     * @return list<array{domain: string, file: string, status: string, detail: string}>
     */
    public function ensureOnThisNode(): array
    {
        try {
            $rows = VoicemailGreetings::where('voicemail_id', ProvisionLineService::LINE_EXTENSION)
                ->where('greeting_name', ProvisionLineService::GREETING_NAME)
                ->where('greeting_description', 'like', ProvisionLineService::GREETING_HASH_PREFIX . '%')
                ->orderBy('domain_uuid')
                ->orderBy('greeting_id')
                ->get();
            if ($rows->isEmpty()) {
                return [];
            }

            $domains = Domain::whereIn('domain_uuid', $rows->pluck('domain_uuid')->unique()->values()->all())
                ->where('domain_description', 'like', 'voxra-tenant:%')
                ->get()
                ->keyBy('domain_uuid');

            $withLine = Extensions::whereIn('domain_uuid', $domains->keys()->all())
                ->where('extension', ProvisionLineService::LINE_EXTENSION)
                ->pluck('domain_uuid')
                ->flip();
        } catch (\Throwable $e) {
            logger()->warning('Voxra line greeting sync: could not read the greeting rows: ' . $e->getMessage());

            return [['domain' => '*', 'file' => '', 'status' => self::STATUS_FAILED, 'detail' => $e->getMessage()]];
        }

        $results = [];
        foreach ($rows as $row) {
            $domain = $domains->get($row->domain_uuid);
            if (! $domain || ! $withLine->has($row->domain_uuid)) {
                continue; // not a Voxra tenant, or no Line extension
            }
            $results[] = $this->ensureRow($domain, $row);
        }

        return $results;
    }

    /** @return array{domain: string, file: string, status: string, detail: string} */
    private function ensureRow(Domain $domain, VoicemailGreetings $row): array
    {
        $name = (string) $domain->domain_name;
        $result = fn (string $file, string $status, string $detail) => [
            'domain' => $name, 'file' => $file, 'status' => $status, 'detail' => $detail,
        ];

        try {
            $parsed = self::parseDescription((string) $row->greeting_description);
            if ($parsed === null) {
                return $result('', self::STATUS_SKIPPED, 'greeting description has no hash + text');
            }

            $filename = self::filenameFor($row);
            if ($filename === null) {
                logger()->warning('Voxra line greeting sync: ' . $name . ' has an unexpected greeting filename, skipped');

                return $result((string) $row->greeting_filename, self::STATUS_SKIPPED, 'unexpected greeting filename');
            }
            if (! self::isSafeDomainName($name)) {
                return $result($filename, self::STATUS_SKIPPED, 'unexpected domain name');
            }

            $dir = $name . '/' . ProvisionLineService::LINE_EXTENSION;
            $path = $dir . '/' . $filename;
            $disk = Storage::disk('voicemail');
            if ($disk->exists($path)) {
                return $result($filename, self::STATUS_PRESENT, 'already on this node');
            }

            $voice = (string) config('services.voxra.vm_greeting_voice', '');
            if ($voice === '') {
                return $result($filename, self::STATUS_SKIPPED, 'no greeting voice configured');
            }

            // The hash covers text + voice. When the voice setting has changed
            // since provisioning, speak it in today's voice and leave the row
            // alone: the next provision call re-voices it on every node anyway.
            $note = self::hashFor($parsed['text'], $voice) === $parsed['hash']
                ? ''
                : ' (voice changed since the row was written; hash kept)';

            ['pcm' => $pcm, 'provider' => $provider] = app(PromptTts::class)
                ->pcm16k($parsed['text'], $voice, 'line greeting for ' . $name . ' (node sync)');

            $newDirs = array_values(array_filter([$name, $dir], fn ($d) => ! $disk->exists($d)));

            $disk->put($path, ProvisionLineService::pcmToWav($pcm, 16000));
            // the perms and owner FreeSWITCH gives its own voicemail files
            @chmod($disk->path($path), 0660);
            self::giveToFreeswitch($disk->path($path));
            foreach ($newDirs as $d) {
                @chmod($disk->path($d), 0770);
                self::giveToFreeswitch($disk->path($d));
            }

            logger('Voxra line greeting restored on ' . gethostname() . ' for ' . $name
                . ' (' . $filename . ') via ' . $provider . $note);

            return $result($filename, self::STATUS_RESTORED, 'via ' . $provider . $note);
        } catch (\Throwable $e) {
            logger()->warning('Voxra line greeting sync failed for ' . $name . ': ' . $e->getMessage());

            return $result((string) $row->greeting_filename, self::STATUS_FAILED, $e->getMessage());
        }
    }

    /**
     * "voxra-tts:<16 hex> <spoken text>" → hash + text, or null.
     *
     * @return array{hash: string, text: string}|null
     */
    public static function parseDescription(string $description): ?array
    {
        $prefix = preg_quote(ProvisionLineService::GREETING_HASH_PREFIX, '/');
        if (! preg_match('/^' . $prefix . '([0-9a-f]{16}) (.+)$/s', $description, $m)) {
            return null;
        }
        $text = trim($m[2]);

        return $text === '' ? null : ['hash' => $m[1], 'text' => $text];
    }

    /** Same hash ProvisionLineService writes into the description. */
    public static function hashFor(string $text, string $voice): string
    {
        return substr(hash('sha256', $text . '|' . $voice), 0, 16);
    }

    /** The file the row names (greeting_filename, else greeting_<id>.wav).
     *  Null for anything that isn't a plain greeting_<n>.wav. */
    public static function filenameFor(VoicemailGreetings $row): ?string
    {
        $filename = trim((string) $row->greeting_filename);
        if ($filename === '' && (int) $row->greeting_id > 0) {
            $filename = 'greeting_' . (int) $row->greeting_id . '.wav';
        }

        return preg_match('/^greeting_\d+\.wav$/', $filename) ? $filename : null;
    }

    private static function isSafeDomainName(string $name): bool
    {
        return $name !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9.-]*$/', $name) === 1 && ! str_contains($name, '..');
    }

    /**
     * The scheduler runs as root, and FreeSWITCH (www-data) can't read a
     * root-owned 0660 greeting or write messages into a root-owned box
     * folder. Hand files and folders we create to www-data. Does nothing when
     * not root, since the file already belongs to whoever runs PHP.
     */
    public static function giveToFreeswitch(string $absolutePath): void
    {
        if (! function_exists('posix_geteuid') || posix_geteuid() !== 0) {
            return;
        }
        @chown($absolutePath, self::FILE_OWNER);
        @chgrp($absolutePath, self::FILE_OWNER);
    }
}
