<?php

namespace App\Services;

use App\Models\Destinations;
use App\Models\Domain;
use App\Models\Extensions;
use App\Models\FusionCache;
use App\Models\Voicemails;
use Illuminate\Support\Str;

/**
 * Voxra Complete (voxragtm#45): the tenant gets an IQ Mobile eSIM that the
 * FMC platform registers as a real SIP extension on the tenant's domain.
 * This service owns that "mobile extension": a registerable extension in
 * the 200–299 block whose credentials voxraweb hands to iqportal
 * (sim_card_config.sip_username / sip_password / sip_host / sip_proxy),
 * with ring_target=fmc, the SIM's own mobile number (or, by the tenant's
 * choice, the DDI) as outbound caller-ID, and no-answer /
 * busy / unregistered failover to the reception agent (push_wake.lua honours
 * those forwards for ring_target=fmc; the box's voicemail is off while an
 * agent exists). With the AI off the same forwards point at the tenant's
 * Voxra voicemail box 9260 instead (voxragtm#164). Unlike the Voxra
 * Line extension (9260, never registers) the password here IS the SIM's
 * registration secret, so it is preserved across re-provisions.
 */
class ProvisionCompleteService
{
    /** Mobile extension block — outside the 9250–9299 agent range and 9260 line. */
    public const EXTENSION_MIN = 200;
    public const EXTENSION_MAX = 299;

    /** Stable marker used to find the tenant's mobile extension on re-provision. */
    public const EXTENSION_DESCRIPTION = 'Voxra mobile (auto-provisioned)';

    /** Marker on the inbound destination routing the SIM's own MSISDN → extension. */
    public const MSISDN_DESTINATION_DESCRIPTION = 'Voxra mobile MSISDN (auto-provisioned)';

    /** Ring the handset this long before failover (agent / voicemail). 30s
     *  (was 20) so the owner has time to pick up; calls to the eSIM's own
     *  mobile number are answered early by push_wake.lua so the carrier's
     *  ~14-20s no-answer timer can't cut the ring short (voxragtm#194). */
    public const CALL_TIMEOUT = 30;

    /** Caller-ID on the eSIM's own outbound calls: its mobile number (default)… */
    public const OUTBOUND_CLI_MOBILE = 'mobile';
    /** …or the tenant's Voxra business number (DDI). */
    public const OUTBOUND_CLI_BUSINESS = 'business';
    public const OUTBOUND_CLI_CHOICES = [self::OUTBOUND_CLI_MOBILE, self::OUTBOUND_CLI_BUSINESS];

    /**
     * Idempotently create/update the mobile extension + its voicemail box and
     * agent failover. $recordOwnerCalls (voxragtm#157) records the inbound
     * calls the owner answers on the eSIM — only pass true once the
     * announcement dialplan is in place (VoxraOwnerCallRecording).
     *
     * @return array{extension: string, password: string, sip_host: string, sip_proxy: string, created: bool}
     */
    public function ensureMobileExtension(Domain $domain, string $businessName, bool $rotatePassword = false, bool $recordOwnerCalls = false): array
    {
        $businessName = trim($businessName) ?: 'Voxra';
        $extension = $this->findMobileExtension($domain);
        $created = false;

        if (! $extension) {
            $number = $this->allocateExtensionNumber($domain);
            $extension = new Extensions();
            $extension->fill([
                'extension_uuid'             => (string) Str::uuid(),
                'domain_uuid'                => $domain->domain_uuid,
                'extension'                  => $number,
                'password'                   => self::generateSipPassword(),
                'user_context'               => $domain->domain_name,
                'effective_caller_id_number' => $number,
                'directory_visible'          => 'false',
                'directory_exten_visible'    => 'false',
                'enabled'                    => 'true',
                'description'                => self::EXTENSION_DESCRIPTION,
                'insert_date'                => date('Y-m-d H:i:s'),
            ]);
            $created = true;
        } elseif ($rotatePassword) {
            $extension->password = self::generateSipPassword();
        }

        // Re-asserted on every call so a renamed business / stale row converges.
        $extension->effective_caller_id_name = $businessName;
        $extension->directory_first_name     = $businessName;
        $extension->directory_last_name      = 'Mobile';
        $extension->ring_target              = 'fmc';
        $extension->call_timeout             = self::CALL_TIMEOUT;
        $extension->enabled                  = 'true';
        // Owner-answered calls are recorded only when the tenant opted in
        // (voxragtm#157; inbound only, after the caller announcement);
        // otherwise never (voxragtm#83). Re-asserted either way so a portal
        // toggle can't quietly start recording the owner's calls.
        $extension->user_record              = \App\Services\Voxra\VoxraOwnerCallRecording::userRecordFor($recordOwnerCalls);

        $hasAgent = $this->applyAgentFailover($domain, $extension);
        $extension->save();

        // With an agent the mobile extension's own voicemail box is off:
        // every unanswered call (unregistered eSIM, phone off, no answer,
        // busy, rejected) forwards to the AI, which takes the message — a
        // caller must never hear "extension two zero zero is not available"
        // (voxragtm#45 / QA run 8aba57e5). Without an agent the box is the
        // only safety net, so it stays on.
        $this->upsertVoicemailBox($domain, (string) $extension->extension, ! $hasAgent);

        FusionCache::clear('directory:' . $extension->extension . '@' . $domain->domain_name);
        FusionCache::clear('dialplan.' . $domain->domain_name);

        return [
            'extension' => (string) $extension->extension,
            // $hidden only affects serialisation — property access is fine here
            'password'  => (string) $extension->password,
            'sip_host'  => $domain->domain_name,
            'sip_proxy' => self::registrar(),
            'created'   => $created,
        ];
    }

    /** The SIP registrar the FMC platform should register to (sip_proxy). */
    public static function registrar(): string
    {
        return (string) config('services.voxra.fmc_registrar', 'reg.voxra.uk') ?: 'reg.voxra.uk';
    }

    /** The tenant's mobile extension row, if provisioned. */
    public function findMobileExtension(Domain $domain): ?Extensions
    {
        return Extensions::where('domain_uuid', $domain->domain_uuid)
            ->where('description', self::EXTENSION_DESCRIPTION)
            ->orderBy('extension')
            ->first();
    }

    /**
     * Stamp the mobile extension's outbound + emergency caller-ID
     * (voxragtm#45, eSIM CLI fix 30 Sept). Outbound: the eSIM's own mobile
     * number by default ($outboundCli = mobile), or the tenant's DDI when
     * they chose "your business number" — also the fallback while the
     * MSISDN isn't known yet. Emergency: always the DDI when there is one
     * (unchanged), else the MSISDN.
     *
     * $msisdn null → the SIM's number from its MSISDN destination row, so a
     * routine re-provision that only sends `did` still presents the mobile.
     *
     * Only the extension's own calls use these fields (the stock
     * OUTBOUND_CALLER_ID dialplan turns them into the From / P-Asserted-
     * Identity on the trunk); AI transfers and forwarded calls keep the
     * original caller's CLI. The Extensions setter strips the leading '+'
     * (that dialplan only fires on ^\d{6,25}$, and OutboundCallerIdFixer
     * re-adds the '+'). Idempotent.
     */
    public function applyCallerId(
        Domain $domain,
        ?string $did,
        string $businessName,
        ?string $msisdn = null,
        string $outboundCli = self::OUTBOUND_CLI_MOBILE,
    ): ?Extensions {
        $extension = $this->findMobileExtension($domain);
        if (! $extension) {
            return null;
        }

        $didDigits = null;
        if ($did !== null && $did !== '') {
            $didDigits = self::e164Digits($did);
            if ($didDigits === null) {
                throw new \InvalidArgumentException('did must be E.164 (+ followed by 10-15 digits)');
            }
        }
        $msisdnDigits = self::e164Digits($msisdn ?? $this->currentMsisdn($domain));

        $outbound = self::outboundCliDigits($outboundCli, $didDigits, $msisdnDigits);
        if ($outbound === null) {
            return null; // neither number known yet — leave the extension as it is
        }

        $businessName = trim($businessName) ?: 'Voxra';
        $extension->outbound_caller_id_number  = $outbound;
        $extension->outbound_caller_id_name    = $businessName;
        $extension->emergency_caller_id_number = $didDigits ?? $msisdnDigits;
        $extension->emergency_caller_id_name   = $businessName;
        $extension->save();

        FusionCache::clear('directory:' . $extension->extension . '@' . $domain->domain_name);

        return $extension;
    }

    /**
     * The tenant's "Show on calls you make from your Voxra mobile" choice:
     * the request value when valid, else the stored one, else the mobile.
     */
    public static function resolveOutboundCli(?string $requested, array $previous): string
    {
        foreach ([$requested, $previous['outbound_cli'] ?? null] as $value) {
            if (is_string($value) && in_array($value, self::OUTBOUND_CLI_CHOICES, true)) {
                return $value;
            }
        }

        return self::OUTBOUND_CLI_MOBILE;
    }

    /** Digits to present for $choice; falls back to whichever number is known. */
    public static function outboundCliDigits(string $choice, ?string $didDigits, ?string $msisdnDigits): ?string
    {
        return $choice === self::OUTBOUND_CLI_BUSINESS
            ? ($didDigits ?? $msisdnDigits)
            : ($msisdnDigits ?? $didDigits);
    }

    /** The SIM's own number (+E.164) from its MSISDN destination row, newest first. */
    public function currentMsisdn(Domain $domain): ?string
    {
        $number = Destinations::where('domain_uuid', $domain->domain_uuid)
            ->where('destination_type', 'inbound')
            ->where('destination_description', self::MSISDN_DESTINATION_DESCRIPTION)
            ->orderByDesc('insert_date')
            ->value('destination_number');

        return is_string($number) && self::e164Digits($number) !== null ? $number : null;
    }

    /**
     * Route the SIM's own MSISDN to the mobile extension. The FMC platform
     * delivers mobile-terminated calls as INVITE +<msisdn>@<sip_host> via the
     * reseller peering gateway (sip-in.voxra.uk:5080 → public context), so
     * without this row a call to the mobile number never reaches the PBX
     * (and never gets the agent failover). Same +E.164 convention as the
     * Voxra reception DID rows. Idempotent: keyed on marker + number.
     */
    public function ensureMsisdnDestination(Domain $domain, string $msisdn): ?Destinations
    {
        $extension = $this->findMobileExtension($domain);
        if (! $extension) {
            return null;
        }

        $digits = self::e164Digits($msisdn);
        if ($digits === null) {
            throw new \InvalidArgumentException('sim_msisdn must be E.164 (+ followed by 10-15 digits)');
        }
        $number = '+' . $digits;

        $actions = json_encode([buildDestinationAction(
            ['type' => 'extensions', 'extension' => (string) $extension->extension],
            $domain->domain_name,
        )]);

        $dest = Destinations::where('domain_uuid', $domain->domain_uuid)
            ->where('destination_type', 'inbound')
            ->where('destination_description', self::MSISDN_DESTINATION_DESCRIPTION)
            ->where('destination_number', $number)
            ->first();

        if (! $dest) {
            $dest = new Destinations();
            $dest->fill([
                'destination_uuid'        => (string) Str::uuid(),
                'domain_uuid'             => $domain->domain_uuid,
                'dialplan_uuid'           => (string) Str::uuid(),
                'destination_type'        => 'inbound',
                'destination_number'      => $number,
                'destination_context'     => 'public',
                'destination_description' => self::MSISDN_DESTINATION_DESCRIPTION,
            ]);
            $dest->insert_date = date('Y-m-d H:i:s');
        } elseif ($dest->destination_actions === $actions && (string) $dest->destination_enabled === '1') {
            return $dest; // unchanged — no dialplan rebuild
        }

        $dest->destination_actions = $actions;
        $dest->destination_enabled = true;
        $dest->save();

        dispatch(new \App\Jobs\BuildDialplanForPhoneNumber($dest->destination_uuid, $domain->domain_name));

        return $dest;
    }

    /** Digits of an E.164 number (10-15 digits after the '+'), or null. */
    public static function e164Digits(?string $raw): ?string
    {
        $n = preg_replace('/[\s().-]/', '', (string) $raw);

        return preg_match('/^\+(\d{10,15})$/', $n, $m) ? $m[1] : null;
    }

    /**
     * Alphanumeric registration secret. Deliberately not generate_password():
     * its `!^$%*?.` symbols travel through iqportal's form-encoded partner
     * API, the Transatel USI (SIP_PASSWORD) and the reg-bot digest layer —
     * 24 alphanumerics (~143 bits) avoid every quoting edge without losing
     * strength.
     */
    public static function generateSipPassword(): string
    {
        return Str::random(24);
    }

    /**
     * Point the forwards at the agent; returns whether there is one.
     *
     * AI off (minutes used up / unpaid, voxragtm#164): the forwards go to the
     * tenant's Voxra voicemail box (*99 9260 — branded greeting, transcribed,
     * voicemail.finalized to voxraweb) when it exists. Clearing them instead
     * would leave push_wake.lua's own fallback, which hands the caller to
     * mod_voicemail's box for the extension: stock greeting, and none of the
     * FusionPBX voicemail lua's message rows, so voxraweb never hears of it.
     */
    private function applyAgentFailover(Domain $domain, Extensions $extension): bool
    {
        $failover = app(AgentFailoverService::class);
        $agent = $failover->enabledAgent($domain);
        if ($agent) {
            $failover->applyTo($extension, $agent);

            return true;
        }

        if ($this->hasVoxraVoicemailBox($domain)) {
            $failover->applyVoicemail($extension, ProvisionLineService::LINE_EXTENSION);
        } else {
            $failover->clearOn($extension); // the extension's own box catches it
        }

        return false;
    }

    private function hasVoxraVoicemailBox(Domain $domain): bool
    {
        return Voicemails::where('domain_uuid', $domain->domain_uuid)
            ->where('voicemail_id', ProvisionLineService::LINE_EXTENSION)
            ->where('voicemail_enabled', 'true')
            ->exists();
    }

    /** First free number in the 200–299 block for the domain. */
    private function allocateExtensionNumber(Domain $domain): string
    {
        $used = Extensions::where('domain_uuid', $domain->domain_uuid)
            ->pluck('extension')
            ->map(fn ($e) => (string) $e)
            ->flip();

        for ($n = self::EXTENSION_MIN; $n <= self::EXTENSION_MAX; $n++) {
            if (! isset($used[(string) $n])) {
                return (string) $n;
            }
        }

        throw new \RuntimeException(sprintf(
            'No mobile extensions available in %d-%d for %s',
            self::EXTENSION_MIN,
            self::EXTENSION_MAX,
            $domain->domain_name
        ));
    }

    private function upsertVoicemailBox(Domain $domain, string $extension, bool $enabled): Voicemails
    {
        $voicemail = Voicemails::where('domain_uuid', $domain->domain_uuid)
            ->where('voicemail_id', $extension)
            ->first();

        if (! $voicemail) {
            $voicemail = new Voicemails();
            $voicemail->fill([
                'domain_uuid'           => $domain->domain_uuid,
                'voicemail_id'          => $extension,
                'voicemail_password'    => (string) random_int(100000, 999999),
                'voicemail_tutorial'    => 'false',
                'voicemail_description' => 'Voxra mobile voicemail',
                'insert_date'           => date('Y-m-d H:i:s'),
            ]);
        }

        $voicemail->voicemail_enabled = $enabled ? 'true' : 'false';
        $voicemail->voicemail_transcription_enabled = 'true';
        $voicemail->save();

        return $voicemail;
    }
}
