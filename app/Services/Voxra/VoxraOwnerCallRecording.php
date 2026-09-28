<?php

namespace App\Services\Voxra;

use App\Models\Dialplans;
use App\Models\Domain;
use App\Models\DomainSettings;
use App\Models\FusionCache;
use App\Services\RecordingWebhookConfigService;
use Illuminate\Support\Str;

/**
 * Recording the calls the OWNER answers (voxragtm#157), per-tenant opt-in
 * (voxraweb sends owner_call_recording; Pro and Complete only, off by
 * default). Outbound calls are never recorded.
 *
 * Two owner-answered paths, one announcement and one recorder:
 *
 *  - Ring-first bridges (Pro ring-first / Line+AI): the DID's own actions
 *    (ProvisionNumberService::ringFirstBridgeActions) pre-answer the caller,
 *    play the announcement, arm record_session for the moment the owner's
 *    mobile answers (execute_on_answer), and disarm it again when the bridge
 *    fails, so the AI or voicemail that picks up next is never recorded here.
 *  - Complete mobile extension (200–299): the stock user_record=inbound
 *    dialplan arms the same execute_on_answer recording, and a per-domain
 *    dialplan (ANNOUNCE_ORDER, before push_wake_hook at 99 which rings the
 *    eSIM) plays the announcement to the caller first — only while the
 *    extension's user_record is inbound, so the announcement and the
 *    recording can't drift apart. push_wake.lua disarms the recording on
 *    failover to the AI / voicemail.
 *
 * The announcement plays as early media (pre_answer), so the caller isn't
 * charged for it and the recorder's execute_on_answer still fires when the
 * owner picks up. Recordings land in the tenant's
 * recordings/<domain>/archive/… dir, so `voxra:purge-media` deletes them
 * after the 10-day retention like every other Voxra call recording. The
 * stock recording.available webhook (SendRecordingWebhook, per-domain
 * recording_webhook settings) posts each one to voxraweb
 * /api/pbx/owner-recording, signed with VOXRA_CDR_WEBHOOK_SECRET.
 */
class VoxraOwnerCallRecording
{
    /** Default spoken text (services.voxra.owner_recording_announcement_text). */
    public const DEFAULT_TEXT = 'This call may be recorded.';

    /** File-name prefix of the shared TTS prompt (VoxraTtsPrompt). */
    public const PREFIX = 'call-may-be-recorded';

    /** Stock Callie prompt ("recording started") when the TTS file is absent
     *  on this node — the caller is always told before the owner answers. */
    public const FALLBACK_PROMPT = 'ivr/ivr-recording_started.wav';

    /** UK ringback while the pre-answered caller waits for the owner. */
    public const UK_RING = '%(400,200,400,450);%(400,2000,400,450)';

    /** Channel marker on owner-recorded call attempts. */
    public const MARKER = 'voxra_owner_recording=true';

    /** Per-domain announcement dialplan for the Complete mobile extension. */
    public const ANNOUNCE_DESCRIPTION = 'Voxra owner call recording announcement (auto-provisioned)';
    public const ANNOUNCE_APP_UUID = '3b7f2c8e-9d41-4e0a-b6c5-71f0a8d2e954';
    /** After call-direction (35), before user_record (50) and push_wake_hook (99). */
    public const ANNOUNCE_ORDER = 48;

    /** The voxraweb route recordings are posted to. */
    public const WEBHOOK_PATH = '/api/pbx/owner-recording';

    // ---- the announcement ---------------------------------------------

    public static function text(): string
    {
        return trim((string) config('services.voxra.owner_recording_announcement_text', self::DEFAULT_TEXT)) ?: self::DEFAULT_TEXT;
    }

    private static function voice(): string
    {
        return (string) config('services.voxra.vm_greeting_voice', '');
    }

    /** Generate the TTS prompt on this node if missing (best-effort). */
    public function ensurePrompt(): ?string
    {
        return VoxraTtsPrompt::ensure(self::PREFIX, self::text(), self::voice(), 'owner-call recording announcement');
    }

    /**
     * What `playback` should play, decided per call on the node that takes
     * it: the TTS prompt when the file exists there, else the stock phrase.
     * Generates the prompt on this node first (best-effort).
     */
    public function playbackExpression(): string
    {
        $this->ensurePrompt();

        return self::playbackExpressionFor(VoxraTtsPrompt::path(self::PREFIX, self::text(), self::voice()));
    }

    /** Pure: `${cond(${file_exists(<path>)} == true ? <path> : <fallback>)}`. */
    public static function playbackExpressionFor(?string $path): string
    {
        if ($path === null || $path === '') {
            return self::FALLBACK_PROMPT;
        }

        return '${cond(${file_exists(' . $path . ')} == true ? ' . $path . ' : ' . self::FALLBACK_PROMPT . ')}';
    }

    // ---- ring-first bridge actions (DID destination_actions) -----------

    /**
     * Before the owner's mobile is bridged: tell the caller (early media,
     * then UK ringback) and arm the recording for when the owner answers.
     */
    public static function beforeBridgeActions(string $prompt): array
    {
        return [
            ['destination_app' => 'set', 'destination_data' => self::MARKER],
            ['destination_app' => 'set', 'destination_data' => 'ringback=' . self::UK_RING],
            ['destination_app' => 'set', 'destination_data' => 'transfer_ringback=' . self::UK_RING],
            ['destination_app' => 'pre_answer', 'destination_data' => ''],
            ['destination_app' => 'sleep', 'destination_data' => '500'],
            ['destination_app' => 'playback', 'destination_data' => $prompt],
            ['destination_app' => 'set', 'destination_data' => 'record_path=${recordings_dir}/${domain_name}/archive/${strftime(%Y)}/${strftime(%b)}/${strftime(%d)}'],
            ['destination_app' => 'set', 'destination_data' => 'record_name=${uuid}.${record_ext}'],
            ['destination_app' => 'mkdir', 'destination_data' => '${record_path}'],
            ['destination_app' => 'set', 'destination_data' => 'RECORD_ANSWER_REQ=true'],
            ['destination_app' => 'set', 'destination_data' => 'execute_on_answer=record_session ${record_path}/${record_name}'],
        ];
    }

    /**
     * After a failed bridge (continue_on_fail): disarm the recording so the
     * AI / voicemail that answers next isn't recorded as an owner call, and
     * drop record_path/record_name so the CDR doesn't point at a file that
     * was never written.
     */
    public static function afterBridgeActions(): array
    {
        return [
            ['destination_app' => 'unset', 'destination_data' => 'execute_on_answer'],
            ['destination_app' => 'unset', 'destination_data' => 'RECORD_ANSWER_REQ'],
            ['destination_app' => 'unset', 'destination_data' => 'record_path'],
            ['destination_app' => 'unset', 'destination_data' => 'record_name'],
            ['destination_app' => 'unset', 'destination_data' => 'voxra_owner_recording'],
        ];
    }

    // ---- Complete mobile extension announcement dialplan ---------------

    /**
     * Create (prompt given) or remove (null) the per-domain announcement
     * dialplan. Must succeed BEFORE the mobile extension's user_record is
     * set to inbound — callers only turn recording on once this returned.
     */
    public function applyMobileAnnouncement(Domain $domain, ?string $prompt): void
    {
        $existing = Dialplans::where('domain_uuid', $domain->domain_uuid)
            ->where('dialplan_description', self::ANNOUNCE_DESCRIPTION)
            ->first();

        if ($prompt === null) {
            if ($existing) {
                Dialplans::where('dialplan_uuid', $existing->dialplan_uuid)->delete();
                FusionCache::clear('dialplan.' . $domain->domain_name);
            }

            return;
        }

        $uuid = $existing?->dialplan_uuid ?? (string) Str::uuid();
        $xml = self::announcementDialplanXml($uuid, $prompt);
        if ($existing && $existing->dialplan_xml === $xml && $existing->dialplan_enabled) {
            return;
        }

        $dialplan = $existing ?? new Dialplans();
        if (! $existing) {
            $dialplan->dialplan_uuid        = $uuid;
            $dialplan->app_uuid             = self::ANNOUNCE_APP_UUID;
            $dialplan->domain_uuid          = $domain->domain_uuid;
            $dialplan->dialplan_description = self::ANNOUNCE_DESCRIPTION;
            $dialplan->insert_date          = date('Y-m-d H:i:s');
        } else {
            $dialplan->update_date          = date('Y-m-d H:i:s');
        }
        $dialplan->dialplan_order    = self::ANNOUNCE_ORDER;
        $dialplan->dialplan_name     = 'Voxra owner call recording';
        $dialplan->dialplan_context  = $domain->domain_name;
        $dialplan->dialplan_continue = 'true';
        $dialplan->dialplan_enabled  = true;
        $dialplan->dialplan_xml      = $xml;
        $dialplan->save();

        FusionCache::clear('dialplan.' . $domain->domain_name);
    }

    /**
     * Inbound calls to a mobile extension (200–299) whose user_record is
     * inbound: pre-answer, announce, then UK ringback while push_wake rings
     * the eSIM. continue="true" so user_record / push_wake still run.
     */
    public static function announcementDialplanXml(string $dialplanUuid, string $prompt): string
    {
        $prompt = htmlspecialchars($prompt, ENT_QUOTES | ENT_XML1);

        return implode("\n", [
            '<extension name="Voxra owner call recording" continue="true" uuid="' . $dialplanUuid . '">',
            '  <condition field="${call_direction}" expression="^inbound$"/>',
            '  <condition field="${user_record}" expression="^(inbound|all)$"/>',
            '  <condition field="destination_number" expression="^(2\d\d)$">',
            '    <action application="set" data="' . self::MARKER . '"/>',
            '    <action application="set" data="ringback=' . self::UK_RING . '"/>',
            '    <action application="set" data="transfer_ringback=' . self::UK_RING . '"/>',
            '    <action application="pre_answer"/>',
            '    <action application="sleep" data="500"/>',
            '    <action application="playback" data="' . $prompt . '"/>',
            '  </condition>',
            '</extension>',
        ]);
    }

    /** The mobile extension's user_record value for the opt-in. */
    public static function userRecordFor(bool $enabled): ?string
    {
        return $enabled ? 'inbound' : null;
    }

    // ---- recording.available webhook → voxraweb -----------------------

    /**
     * Point the domain's recording webhook at voxraweb (enabled) or switch it
     * off, keeping the rows. Same secret as cdr.finalized, so voxraweb
     * verifies one HMAC (pbxSignatureOk). Returns false when voxraweb isn't
     * configured on this PBX (nothing written).
     */
    public function applyWebhook(Domain $domain, bool $enabled): bool
    {
        $values = self::webhookSettings(
            $enabled,
            (string) config('services.voxra.app_url', ''),
            (string) config('services.voxra.cdr_webhook_secret', ''),
        );
        if ($values === null) {
            if ($enabled) {
                logger()->warning('Voxra owner-call recording webhook not configured for ' . $domain->domain_name
                    . ': VOXRA_APP_URL / VOXRA_CDR_WEBHOOK_SECRET missing');
            }

            return false;
        }

        foreach ($values as $sub => $value) {
            DomainSettings::updateOrCreate(
                [
                    'domain_uuid'                => $domain->domain_uuid,
                    'domain_setting_category'    => RecordingWebhookConfigService::CATEGORY,
                    'domain_setting_subcategory' => $sub,
                ],
                [
                    'domain_setting_name'        => 'text',
                    'domain_setting_value'       => $value,
                    'domain_setting_enabled'     => true,
                    'domain_setting_description' => 'Voxra owner-call recordings → voxraweb (auto-provisioned; do not edit)',
                ]
            );
        }

        return true;
    }

    /**
     * Pure: the recording_webhook subcategory values. Inbound only, the
     * recording.available event, a one-hour signed URL. Null when voxraweb's
     * URL or the shared secret is missing.
     *
     * @return array<string, string>|null
     */
    public static function webhookSettings(bool $enabled, string $appUrl, string $secret): ?array
    {
        $base = rtrim(trim($appUrl), '/');
        if ($base === '' || trim($secret) === '') {
            return null;
        }

        return [
            'enabled'    => $enabled ? 'true' : 'false',
            'url'        => $base . self::WEBHOOK_PATH,
            'secret'     => $secret,
            'directions' => 'inbound',
            'events'     => RecordingWebhookConfigService::EVENT_AVAILABLE,
            'url_ttl'    => '3600',
        ];
    }
}
