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
 * with ring_target=fmc, the DDI as outbound caller-ID, and no-answer /
 * busy / unregistered failover to the reception agent. Unlike the Voxra
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

    /** Ring the handset this long before failover (agent / voicemail). */
    public const CALL_TIMEOUT = 20;

    /**
     * Idempotently create/update the mobile extension + its voicemail box and
     * agent failover.
     *
     * @return array{extension: string, password: string, sip_host: string, sip_proxy: string, created: bool}
     */
    public function ensureMobileExtension(Domain $domain, string $businessName, bool $rotatePassword = false): array
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

        $this->applyAgentFailover($domain, $extension);
        $extension->save();

        $this->upsertVoicemailBox($domain, (string) $extension->extension);

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
     * Stamp the tenant's DDI on the mobile extension as outbound + emergency
     * caller-ID. The Extensions setter strips the leading '+' (the stock
     * OUTBOUND_CALLER_ID dialplan only fires on ^\d{6,25}$, and
     * OutboundCallerIdFixer re-adds the '+'). Idempotent.
     */
    public function applyCallerId(Domain $domain, string $did, string $businessName): ?Extensions
    {
        $extension = $this->findMobileExtension($domain);
        if (! $extension) {
            return null;
        }

        $digits = self::e164Digits($did);
        if ($digits === null) {
            throw new \InvalidArgumentException('did must be E.164 (+ followed by 10-15 digits)');
        }

        $businessName = trim($businessName) ?: 'Voxra';
        $extension->outbound_caller_id_number  = $digits;
        $extension->outbound_caller_id_name    = $businessName;
        $extension->emergency_caller_id_number = $digits;
        $extension->emergency_caller_id_name   = $businessName;
        $extension->save();

        FusionCache::clear('directory:' . $extension->extension . '@' . $domain->domain_name);

        return $extension;
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

    private function applyAgentFailover(Domain $domain, Extensions $extension): void
    {
        $failover = app(AgentFailoverService::class);
        $agent = $failover->enabledAgent($domain);
        if ($agent) {
            $failover->applyTo($extension, $agent);
        } else {
            $failover->clearOn($extension); // voicemail box catches it
        }
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

    private function upsertVoicemailBox(Domain $domain, string $extension): Voicemails
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

        $voicemail->voicemail_enabled = 'true';
        $voicemail->voicemail_transcription_enabled = 'true';
        $voicemail->save();

        return $voicemail;
    }
}
