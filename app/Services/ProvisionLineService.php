<?php

namespace App\Services;

use App\Models\Dialplans;
use App\Models\Domain;
use App\Models\Extensions;
use App\Models\FollowMe;
use App\Models\FusionCache;
use App\Models\VoicemailGreetings;
use App\Models\Voicemails;
use App\Services\Tts\ElevenLabsTtsService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Voxra Line (voxragtm#25): the £5 no-AI plan. The tenant's DID transfers to
 * a stock FusionPBX extension whose follow-me rings the owner's mobile with
 * press-1 answer confirmation, falling back to the extension's voicemail box
 * (transcription enabled). The stock local_extension / follow_me / voicemail
 * apps do the routing; we write their rows plus one per-domain dialplan: a
 * voicemail-fallback wrapper around the stock follow-me run (voxragtm#110) —
 * see ensureVoicemailFallbackDialplan for why the stock path alone can strand
 * the caller.
 */
class ProvisionLineService
{
    /** Stable line extension — distinct from the reception agent's 9250. */
    public const LINE_EXTENSION = '9260';

    /** Ring the owner's mobile this long before voicemail answers. */
    public const FOLLOW_ME_TIMEOUT = 25;

    /** Stable marker used to find/update the fallback dialplan on re-provision. */
    public const FALLBACK_DIALPLAN_DESCRIPTION = 'Voxra line voicemail fallback (auto-provisioned)';

    /** Owning app marker (this provisioning code), not a template app_uuid. */
    public const FALLBACK_APP_UUID = 'f1c92d7e-5a04-4b38-8c6d-2e9ab4d0c751';

    /** Must sit before the stock global follow-me-destinations entry (order
     *  520) so it takes over 9260's follow-me run, and after the last stock
     *  entries that set the variables it conditions on (≤ 500). */
    public const FALLBACK_DIALPLAN_ORDER = 510;

    /** Marker naming the TTS greeting rows this service owns. Rows with any
     *  other greeting_name (owner-recorded / UI-uploaded) are never touched. */
    public const GREETING_NAME = 'Voxra greeting (auto-provisioned)';

    /** greeting_description prefix carrying the text+voice hash for
     *  idempotence: "voxra-tts:<hash> <spoken text>". */
    public const GREETING_HASH_PREFIX = 'voxra-tts:';

    /**
     * Idempotently create/update the line extension, its follow-me and its
     * voicemail box. Safe to re-run on every provision call.
     *
     * @return array{extension: string, straight_to_voicemail: bool}
     */
    public function ensureLineExtension(Domain $domain, ?string $ownerMobile, ?string $businessName = null): array
    {
        // Same normalisation + hosted-DID loop guard as ring-mobile-first.
        $mobile = app(ProvisionNumberService::class)
            ->resolveRingFirstMobile($domain, true, $ownerMobile);

        $extension = $this->findLineExtension($domain);
        if (! $extension) {
            $extension = new Extensions();
            $extension->fill([
                'extension_uuid'             => (string) Str::uuid(),
                'domain_uuid'                => $domain->domain_uuid,
                'extension'                  => self::LINE_EXTENSION,
                // no device ever registers; the password just has to be unguessable
                'password'                   => bin2hex(random_bytes(16)),
                'user_context'               => $domain->domain_name,
                'effective_caller_id_name'   => 'Voxra Line',
                'effective_caller_id_number' => self::LINE_EXTENSION,
                'call_timeout'               => self::FOLLOW_ME_TIMEOUT,
                'directory_visible'          => 'false',
                'directory_exten_visible'    => 'false',
                'enabled'                    => 'true',
                'description'                => 'Voxra line (auto-provisioned)',
                'insert_date'                => date('Y-m-d H:i:s'),
            ]);
        }

        $this->upsertFollowMe($domain, $extension, $mobile);
        $extension->save();
        $voicemail = $this->upsertVoicemailBox($domain);
        $this->ensureVoicemailGreeting($domain, $voicemail, $businessName);
        $this->ensureVoicemailFallbackDialplan($domain);

        FusionCache::clear('directory:' . self::LINE_EXTENSION . '@' . $domain->domain_name);

        return [
            'extension'             => self::LINE_EXTENSION,
            'straight_to_voicemail' => $mobile === null,
        ];
    }

    /** The tenant's line extension row, if provisioned. */
    public function findLineExtension(Domain $domain): ?Extensions
    {
        return Extensions::where('domain_uuid', $domain->domain_uuid)
            ->where('extension', self::LINE_EXTENSION)
            ->first();
    }

    /** Follow-me destination row for the owner's mobile: press-1 confirm so a
     *  carrier voicemail answering the leg can't swallow the call.
     *
     *  Number-format story (voxragtm#110): the destination is stored in E.164
     *  (+44…) — normaliseOwnerMobile's output — the same format ring-first
     *  dials, and the per-domain "Voxra Outbound" route
     *  (ProvisionOutboundRouteService) explicitly matches E.164 alongside UK
     *  national. One format end to end; the route accepts both. */
    public static function followMeDestinationAttributes(string $mobile): array
    {
        return [
            'follow_me_destination' => $mobile,
            'follow_me_delay'       => 0,
            'follow_me_timeout'     => self::FOLLOW_ME_TIMEOUT,
            // '1' = stock follow-me answer confirmation (confirm.lua press-1)
            'follow_me_prompt'      => '1',
            'follow_me_order'       => 1,
        ];
    }

    private function upsertFollowMe(Domain $domain, Extensions $extension, ?string $mobile): void
    {
        $followMe = $extension->follow_me_uuid ? FollowMe::find($extension->follow_me_uuid) : null;
        if (! $followMe) {
            $followMe = new FollowMe([
                'follow_me_uuid' => (string) Str::uuid(),
                'domain_uuid'    => $domain->domain_uuid,
            ]);
            $extension->follow_me_uuid = $followMe->follow_me_uuid;
        }

        // No usable mobile → follow-me off: the unregistered extension times
        // out straight into its voicemail box (stock *99 fallback).
        $enabled = $mobile !== null ? 'true' : 'false';
        $followMe->follow_me_enabled = $enabled;
        $followMe->save();

        $followMe->followMeDestinations()->delete();
        if ($mobile !== null) {
            $followMe->followMeDestinations()->create(array_merge(
                ['domain_uuid' => $domain->domain_uuid],
                self::followMeDestinationAttributes($mobile),
            ));
        }

        $extension->follow_me_enabled = $enabled;
    }

    private function upsertVoicemailBox(Domain $domain): Voicemails
    {
        $voicemail = Voicemails::where('domain_uuid', $domain->domain_uuid)
            ->where('voicemail_id', self::LINE_EXTENSION)
            ->first();

        if (! $voicemail) {
            $voicemail = new Voicemails();
            $voicemail->fill([
                'domain_uuid'           => $domain->domain_uuid,
                'voicemail_id'          => self::LINE_EXTENSION,
                'voicemail_password'    => (string) random_int(100000, 999999),
                'voicemail_tutorial'    => 'false',
                'voicemail_description' => 'Voxra line voicemail',
                'insert_date'           => date('Y-m-d H:i:s'),
            ]);
        }

        // Transcript rides the voicemail.finalized webhook — keep the box
        // enabled and transcribable even if it pre-existed with other flags.
        $voicemail->voicemail_enabled = 'true';
        $voicemail->voicemail_transcription_enabled = 'true';
        $voicemail->save();

        return $voicemail;
    }

    /**
     * Branded per-business TTS voicemail greeting (voxragtm#110). Generated
     * once per text+voice combination via ElevenLabs at provision time
     * (~2s measured — fine inside the provision request), then selected as
     * the box's active greeting so the FusionPBX voicemail lua plays it
     * instead of the stock "the person at extension 9260…" phrase.
     *
     * Rules:
     *  - only rows named GREETING_NAME are ever created/updated — a greeting
     *    the owner recorded (*98) or uploaded is never overwritten, and if
     *    the owner selected their own greeting we never steal the selection;
     *  - idempotent via a text+voice hash carried in greeting_description —
     *    re-provisioning with unchanged text/voice makes no TTS call;
     *  - best-effort: missing ELEVENLABS_API_KEY or a TTS failure logs a
     *    warning and leaves the stock greeting — provisioning never fails.
     */
    private function ensureVoicemailGreeting(Domain $domain, Voicemails $voicemail, ?string $businessName): void
    {
        try {
            $businessName = trim((string) $businessName);
            if ($businessName === '') {
                return; // nothing to brand the greeting with
            }

            $voice = (string) config('services.voxra.vm_greeting_voice', '');
            $template = (string) config('services.voxra.vm_greeting_text', '');
            $text = trim(str_replace('{business}', $businessName, $template));
            if ($voice === '' || $text === '') {
                return;
            }

            $hash = substr(hash('sha256', $text . '|' . $voice), 0, 16);

            $ours = VoicemailGreetings::where('domain_uuid', $domain->domain_uuid)
                ->where('voicemail_id', self::LINE_EXTENSION)
                ->where('greeting_name', self::GREETING_NAME)
                ->first();

            // Owner recorded/uploaded + selected their own greeting → theirs
            // wins, permanently. (greeting_id null/-1/0 = no active greeting.)
            $activeId = (int) ($voicemail->greeting_id ?? 0);
            if ($activeId > 0 && (int) ($ours?->greeting_id ?? 0) !== $activeId) {
                return;
            }

            $relativePath = $domain->domain_name . '/' . self::LINE_EXTENSION
                . '/greeting_' . ($ours?->greeting_id ?? '') . '.wav';
            if (
                $ours
                && str_starts_with((string) $ours->greeting_description, self::GREETING_HASH_PREFIX . $hash . ' ')
                && $activeId === (int) $ours->greeting_id
                && Storage::disk('voicemail')->exists($relativePath)
            ) {
                return; // unchanged — no TTS call
            }

            // ElevenLabsTtsService throws when ELEVENLABS_API_KEY is unset;
            // raw 16-bit mono PCM at 16 kHz, wrapped in a WAV header below
            // (same 16-bit mono PCM family as the UI's saved greetings).
            $pcm = (new ElevenLabsTtsService())->textToSpeech($text, [
                'voice' => $voice,
                'response_format' => 'pcm',
            ]);
            if (strlen($pcm) < 1000) {
                throw new \RuntimeException('ElevenLabs returned implausibly short audio (' . strlen($pcm) . ' bytes)');
            }

            $greetingId = (int) ($ours->greeting_id ?? $this->nextGreetingId($domain));
            $filename = 'greeting_' . $greetingId . '.wav';
            $path = $domain->domain_name . '/' . self::LINE_EXTENSION . '/' . $filename;

            Storage::disk('voicemail')->put($path, self::pcmToWav($pcm, 16000));
            // match the perms FreeSWITCH writes its own voicemail files with
            @chmod(Storage::disk('voicemail')->path($path), 0660);

            if (! $ours) {
                $ours = new VoicemailGreetings([
                    'domain_uuid' => $domain->domain_uuid,
                    'voicemail_id' => self::LINE_EXTENSION,
                    'greeting_name' => self::GREETING_NAME,
                    'insert_date' => date('Y-m-d H:i:s'),
                ]);
            }
            $ours->greeting_id = $greetingId;
            $ours->greeting_filename = $filename;
            $ours->greeting_description = self::GREETING_HASH_PREFIX . $hash . ' ' . $text;
            $ours->update_date = date('Y-m-d H:i:s');
            $ours->save();

            $voicemail->greeting_id = $greetingId;
            $voicemail->save();

            logger('Voxra line greeting generated for ' . $domain->domain_name . ' (greeting_' . $greetingId . '.wav)');
        } catch (\Throwable $e) {
            // stock greeting keeps playing; provisioning must not fail
            logger()->warning('Voxra line greeting TTS skipped for ' . $domain->domain_name . ': ' . $e->getMessage());
        }
    }

    /** Lowest free greeting_id for the box — same gap-scan the greeting UI
     *  uses, so we can't collide with an owner-recorded greeting's slot. */
    private function nextGreetingId(Domain $domain): int
    {
        $existing = VoicemailGreetings::where('domain_uuid', $domain->domain_uuid)
            ->where('voicemail_id', self::LINE_EXTENSION)
            ->pluck('greeting_id')
            ->map(fn ($id) => (int) $id)
            ->sort()
            ->values();

        $next = 1;
        foreach ($existing as $id) {
            if ($id === $next) {
                $next++;
            }
        }

        return $next;
    }

    /** Wrap raw 16-bit mono little-endian PCM in a RIFF/WAVE header —
     *  FreeSWITCH-native, no sox/ffmpeg dependency. */
    public static function pcmToWav(string $pcm, int $sampleRate): string
    {
        $channels = 1;
        $bitsPerSample = 16;
        $byteRate = intdiv($sampleRate * $channels * $bitsPerSample, 8);
        $blockAlign = intdiv($channels * $bitsPerSample, 8);

        return 'RIFF' . pack('V', 36 + strlen($pcm)) . 'WAVE'
            . 'fmt ' . pack('V', 16)
            . pack('vvVVvv', 1, $channels, $sampleRate, $byteRate, $blockAlign, $bitsPerSample)
            . 'data' . pack('V', strlen($pcm))
            . $pcm;
    }

    /**
     * Per-domain dialplan wrapping the stock follow-me run with a voicemail
     * fallback (voxragtm#110).
     *
     * Why: the stock follow_me lua only transfers to voicemail (*99<ext>) for
     * a whitelist of originate dispositions (NO_ANSWER, USER_BUSY, timeouts…).
     * When the mobile bridge fails with anything else — live failure: the
     * loopback leg found no outbound route and died with NORMAL_CLEARING
     * (call 106d25ae-d90a-42a9-bf35-f4935ae470af) — the lua returns without
     * transferring, and the stock follow-me-destinations entry has no further
     * actions: the caller leg "has executed the last dialplan instruction"
     * and hangs up. Dead air, no voicemail.
     *
     * Fix: run the identical follow-me actions from our own entry ordered
     * just before the stock one (FALLBACK_DIALPLAN_ORDER 510 < 520), with a
     * voicemail tail. On the whitelisted dispositions the lua still transfers
     * to *99 (the tail never runs); on a completed bridge hangup_after_bridge
     * (set inside the lua) ends the call; on every other failure the tail
     * answers and drops the caller into 9260's transcribed voicemail box —
     * the same terminal the stock local_extension tail uses. When follow-me
     * is disabled (no usable mobile) the entry doesn't match and the stock
     * local_extension → voicemail path handles the call as before.
     */
    private function ensureVoicemailFallbackDialplan(Domain $domain): void
    {
        $existing = Dialplans::where('domain_uuid', $domain->domain_uuid)
            ->where('dialplan_description', self::FALLBACK_DIALPLAN_DESCRIPTION)
            ->first();

        $dialplanUuid = $existing?->dialplan_uuid ?? (string) Str::uuid();

        $dialPlan = $existing ?? new Dialplans();
        if (! $existing) {
            $dialPlan->dialplan_uuid        = $dialplanUuid;
            $dialPlan->app_uuid             = self::FALLBACK_APP_UUID;
            $dialPlan->domain_uuid          = $domain->domain_uuid;
            $dialPlan->dialplan_order       = self::FALLBACK_DIALPLAN_ORDER;
            $dialPlan->dialplan_description = self::FALLBACK_DIALPLAN_DESCRIPTION;
            $dialPlan->insert_date          = date('Y-m-d H:i:s');
        } else {
            $dialPlan->update_date          = date('Y-m-d H:i:s');
        }

        $dialPlan->dialplan_name     = 'Voxra Line voicemail fallback';
        $dialPlan->dialplan_number   = self::LINE_EXTENSION;
        $dialPlan->dialplan_context  = $domain->domain_name;
        $dialPlan->dialplan_continue = 'false';
        $dialPlan->dialplan_enabled  = 'true';
        $dialPlan->dialplan_xml      = self::fallbackDialplanXml($dialplanUuid);
        $dialPlan->save();

        FusionCache::clear('dialplan.' . $domain->domain_name);
    }

    /** Same shape as the stock follow-me-destinations entry (conditions and
     *  the unset/lua pair are verbatim), plus the voicemail tail. The
     *  hangup_after_bridge=false reset only ever executes after a FAILED
     *  originate (a successful bridge hangs up inside the lua first), so it
     *  can't suppress the normal after-call hangup — it just makes sure the
     *  voicemail tail runs unencumbered.
     *
     *  The tail mirrors the stock send_to_voicemail (*99) actions — the
     *  FusionPBX voicemail lua, NOT mod_voicemail ("voicemail" application):
     *  only the lua reads v_voicemails.greeting_id / v_voicemail_greetings
     *  (so the branded TTS greeting plays) and writes v_voicemail_messages
     *  (so the voicemail.finalized webhook + transcription fire). */
    public static function fallbackDialplanXml(string $dialplanUuid): string
    {
        $ext = self::LINE_EXTENSION;

        return implode("\n", [
            '<extension name="Voxra Line voicemail fallback" continue="false" uuid="' . $dialplanUuid . '">',
            '  <condition field="destination_number" expression="^' . $ext . '$" break="on-false"/>',
            '  <condition field="${user_exists}" expression="^true$" break="on-false"/>',
            '  <condition field="${follow_me_enabled}" expression="^true$" break="on-false">',
            '    <action application="unset" data="call_timeout"/>',
            '    <action application="lua" data="app.lua follow_me"/>',
            '    <action application="set" data="hangup_after_bridge=false"/>',
            '    <action application="answer"/>',
            '    <action application="sleep" data="1000"/>',
            '    <action application="set" data="voicemail_action=save"/>',
            '    <action application="set" data="voicemail_id=' . $ext . '"/>',
            '    <action application="set" data="voicemail_profile=default"/>',
            '    <action application="set" data="send_to_voicemail=true"/>',
            '    <action application="lua" data="app.lua voicemail"/>',
            '  </condition>',
            '</extension>',
        ]);
    }
}
