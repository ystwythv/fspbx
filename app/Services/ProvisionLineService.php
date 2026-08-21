<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\Extensions;
use App\Models\FollowMe;
use App\Models\FusionCache;
use App\Models\Voicemails;
use Illuminate\Support\Str;

/**
 * Voxra Line (voxragtm#25): the £5 no-AI plan. The tenant's DID transfers to
 * a stock FusionPBX extension whose follow-me rings the owner's mobile with
 * press-1 answer confirmation, falling back to the extension's voicemail box
 * (transcription enabled). No custom dialplans — the stock local_extension /
 * follow_me / voicemail apps do all the routing; we only write their rows.
 */
class ProvisionLineService
{
    /** Stable line extension — distinct from the reception agent's 9250. */
    public const LINE_EXTENSION = '9260';

    /** Ring the owner's mobile this long before voicemail answers. */
    public const FOLLOW_ME_TIMEOUT = 25;

    /**
     * Idempotently create/update the line extension, its follow-me and its
     * voicemail box. Safe to re-run on every provision call.
     *
     * @return array{extension: string, straight_to_voicemail: bool}
     */
    public function ensureLineExtension(Domain $domain, ?string $ownerMobile): array
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
        $this->upsertVoicemailBox($domain);

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
     *  carrier voicemail answering the leg can't swallow the call. */
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

    private function upsertVoicemailBox(Domain $domain): void
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
    }
}
