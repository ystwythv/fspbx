<?php

namespace App\Services\Voxra;

use App\Models\DomainSettings;

/**
 * The Voxra tenant's plan mode and routing preferences, persisted per domain
 * (voxragtm#162/#163/#164/#173) as one v_domain_settings row
 * (category "voxra", subcategory "routing", JSON value).
 *
 * Why it is stored: voxraweb re-provisions from many places (answering sync,
 * billing pauses, disclosure refreshes) and most of those calls leave the
 * routing fields out. Without a record of the last explicit values, a bare
 * re-provision would silently drop ring-first, un-suspend a suspended number
 * or reset a Line+AI ring timeout. The mode is also read outside the
 * provision request: the 9250 inbound dialplan's voicemail safety net, the
 * *9 bind and the Telnyx tool sync (no transfer_to_owner on Line+AI) are all
 * rebuilt by the resync command and the admin UI too.
 *
 * Keys: mode (MODE_*), ring_first (Pro preference), owner_mobile (validated
 * E.164 or null), ring_first_timeout (seconds), service_suspended (bool),
 * owner_call_recording (bool), outbound_cli ("mobile" | "business": what the
 * Complete eSIM's own outbound calls present, ProvisionCompleteService).
 */
final class VoxraRoutingState
{
    public const CATEGORY = 'voxra';
    public const SUBCATEGORY = 'routing';

    /** Voxra Line v1 (voxragtm#25): follow-me 9260 then voicemail, no AI. */
    public const MODE_LINE = 'line';
    /** Voxra Line + AI (voxragtm#163): owner's mobile first, then the AI. */
    public const MODE_LINE_AI = 'line_ai';
    /** Pro / trial: AI first, or ring-first as an option. */
    public const MODE_PRO = 'pro';
    /** Voxra Complete (voxragtm#45): eSIM extension, then the AI. */
    public const MODE_COMPLETE = 'complete';

    /** Complete wins, then Line+AI, then Line v1; anything else is Pro. */
    public static function modeFor(bool $lineMode, bool $lineAi, bool $completeMode): string
    {
        if ($completeMode) {
            return self::MODE_COMPLETE;
        }
        if ($lineAi) {
            return self::MODE_LINE_AI;
        }

        return $lineMode ? self::MODE_LINE : self::MODE_PRO;
    }

    /** The stored state, or [] when none / unreadable (never throws). */
    public static function load(?string $domainUuid): array
    {
        if (! $domainUuid) {
            return [];
        }

        try {
            $value = DomainSettings::where('domain_uuid', $domainUuid)
                ->where('domain_setting_category', self::CATEGORY)
                ->where('domain_setting_subcategory', self::SUBCATEGORY)
                ->value('domain_setting_value');
        } catch (\Throwable $e) {
            return [];
        }

        $state = is_string($value) ? json_decode($value, true) : null;

        return is_array($state) ? $state : [];
    }

    /** Idempotently store the state (one row per domain). */
    public static function save(string $domainUuid, array $state): void
    {
        DomainSettings::updateOrCreate(
            [
                'domain_uuid'                => $domainUuid,
                'domain_setting_category'    => self::CATEGORY,
                'domain_setting_subcategory' => self::SUBCATEGORY,
            ],
            [
                'domain_setting_name'        => 'text',
                'domain_setting_value'       => json_encode($state, JSON_UNESCAPED_SLASHES),
                'domain_setting_enabled'     => true,
                'domain_setting_description' => 'Voxra plan + routing (auto-provisioned; do not edit)',
            ]
        );
    }

    /** The stored plan mode, or null when unknown. */
    public static function mode(?string $domainUuid): ?string
    {
        $mode = self::load($domainUuid)['mode'] ?? null;

        return is_string($mode) ? $mode : null;
    }

    public static function isLineAi(?string $domainUuid): bool
    {
        return self::mode($domainUuid) === self::MODE_LINE_AI;
    }
}
