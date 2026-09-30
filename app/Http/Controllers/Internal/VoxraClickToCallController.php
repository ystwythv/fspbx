<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Extensions;
use App\Services\FreeswitchEslService;
use App\Services\ProvisionNumberService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Click-to-call for Voxra urgent call-backs (voxragtm#24).
 *
 * voxraweb → here when the owner / on-call person taps "Call back now" on an
 * urgent-call push (or replies CALL to the WhatsApp/SMS alert). We ring the
 * OWNER first — their extension (user/<ext>@<domain>) or their number via
 * loopback into the tenant's own domain context, so the "Voxra Outbound"
 * route and gateway carry it — presenting the business's Voxra number. Only
 * when the owner answers does the leg run &bridge() out to the caller, again
 * via the domain's outbound route with the business number as caller ID. The
 * caller's phone doesn't ring until the owner has answered, and a missed call
 * isn't retried.
 *
 * A number leg asks the owner to press 1 first (the ring-first
 * voxra_owner_confirm.lua) so a carrier voicemail answering the owner's
 * mobile can't swallow the call and dial the customer into a voicemail
 * greeting. FreeSWITCH clears the group_confirm_* variables on the winning
 * leg, so the prompt never reaches the caller's leg.
 *
 * HMAC-authed by VerifyVoxraInternalSignature.
 */
class VoxraClickToCallController extends Controller
{
    private const UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const E164_RE = '/^\+\d{8,15}$/';
    private const EXTENSION_RE = '/^\d{2,10}$/';
    /** Escalation ids are uuids/slugs; the charset keeps them safe inside a dial string. */
    private const REF_RE = '/^[A-Za-z0-9._:-]{1,64}$/';

    public const MAX_SKEW_SECONDS = 300;
    public const DEDUPE_SECONDS = 60;
    public const OWNER_TIMEOUT = 30;
    public const CALLER_TIMEOUT = 45;
    public const OWNER_CALLER_ID_NAME = 'Voxra call-back';

    public function start(Request $request, FreeswitchEslService $esl): JsonResponse
    {
        $domainUuid = (string) $request->input('domain_uuid', '');
        $owner = trim((string) $request->input('owner', ''));
        $ownerKind = (string) $request->input('owner_kind', '');
        $caller = trim((string) $request->input('caller', ''));
        $cli = trim((string) $request->input('cli', ''));
        $ref = (string) $request->input('ref', '');
        $ts = (int) $request->input('ts', 0);

        if (! preg_match(self::UUID_RE, $domainUuid)) {
            return self::error('bad domain_uuid', 422);
        }
        if (! in_array($ownerKind, ['number', 'extension'], true)) {
            return self::error('owner_kind must be number or extension', 422);
        }
        if ($ownerKind === 'number' && ! preg_match(self::E164_RE, $owner)) {
            return self::error('owner must be E.164', 422);
        }
        if ($ownerKind === 'extension' && ! preg_match(self::EXTENSION_RE, $owner)) {
            return self::error('owner must be a 2-10 digit extension', 422);
        }
        if (! preg_match(self::E164_RE, $caller)) {
            return self::error('caller must be E.164', 422);
        }
        if (! preg_match(self::E164_RE, $cli)) {
            return self::error('cli must be E.164', 422);
        }
        if (! preg_match(self::REF_RE, $ref)) {
            return self::error('ref must be 1-64 chars of [A-Za-z0-9._:-]', 422);
        }
        if ($caller === $cli || $caller === $owner) {
            return self::error('caller must differ from owner and cli', 422);
        }
        if (abs(time() - $ts) > self::MAX_SKEW_SECONDS) {
            return self::error('stale', 401);
        }

        $domain = Domain::where('domain_uuid', $domainUuid)->first();
        if (! $domain) {
            return self::error('unknown domain', 404);
        }
        $domainName = (string) $domain->domain_name;

        if ($ownerKind === 'extension') {
            $exists = Extensions::where('domain_uuid', $domainUuid)
                ->where('extension', $owner)
                ->exists();
            if (! $exists) {
                return self::error('unknown extension', 422);
            }
        } elseif (app(ProvisionNumberService::class)->isHostedNumber($owner)) {
            // Ringing a DID hosted on this PBX would go out the gateway and
            // straight back in to the tenant's own inbound routing (the AI).
            return self::error('owner number is hosted on this PBX', 422);
        }

        $callUuid = (string) Str::uuid();
        $dedupeKey = 'voxra:click-to-call:' . strtolower($domainUuid) . ':' . $caller;

        // A double tap (push action + WhatsApp reply, or a retried request)
        // must not ring the owner twice. Cache::add is atomic (SET NX).
        if (! Cache::add($dedupeKey, $callUuid, self::DEDUPE_SECONDS)) {
            $previous = (string) Cache::get($dedupeKey, '');
            logger(sprintf(
                'Voxra click-to-call duplicate domain=%s ref=%s caller=%s',
                $domainName, $ref, self::mask($caller)
            ));

            return response()->json([
                'ok' => true,
                'duplicate' => true,
                'call_uuid' => $previous !== '' ? $previous : null,
            ]);
        }

        [$endpoint, $app, $vars] = self::buildOriginate($domainUuid, $domainName, $owner, $ownerKind, $caller, $cli, $ref, $callUuid);

        $out = null;
        try {
            $out = $esl->originate($endpoint, $app, 'default', $vars);
        } catch (\Throwable $e) {
            logger('Voxra click-to-call originate threw: ' . $e->getMessage());
        }

        $outStr = is_string($out) ? $out : (is_array($out) ? json_encode($out) : '');
        if ($out === null || $outStr === '' || str_starts_with($outStr, '-ERR')) {
            Cache::forget($dedupeKey);
            logger(sprintf(
                'Voxra click-to-call FAILED domain=%s ref=%s owner=%s(%s) caller=%s: %s',
                $domainName, $ref, self::mask($owner), $ownerKind, self::mask($caller), $outStr ?: 'no ESL response'
            ));

            return self::error('originate failed', 500);
        }

        logger(sprintf(
            'Voxra click-to-call started domain=%s ref=%s owner=%s(%s) caller=%s cli=%s call_uuid=%s',
            $domainName, $ref, self::mask($owner), $ownerKind, self::mask($caller), $cli, $callUuid
        ));

        return response()->json(['ok' => true, 'call_uuid' => $callUuid]);
    }

    /**
     * The owner-leg originate: [endpoint, application, channel vars].
     *
     * Owner leg (A): the business number as caller ID, 30 s to answer, early
     * media ignored so the owner must really answer. outbound_caller_id_number
     * is digits-only because the patched OUTBOUND_CALLER_ID dialplan only
     * fires on ^\d{6,25}$ and re-adds the '+' (see OutboundCallerIdFixer);
     * effective_caller_id_* is set directly too so an unpatched domain still
     * presents it.
     *
     * Caller leg (B, the &bridge app): same CLI, via loopback into the domain
     * so the Voxra Outbound route picks the gateway. Values in the bridge's
     * dial string never contain spaces or commas (the originate arguments are
     * space-separated). ignore_early_media=false + bridge_early_media=true
     * override the owner leg's copies so the owner hears the caller ringing.
     *
     * @return array{0: string, 1: string, 2: array<string, string>}
     */
    public static function buildOriginate(
        string $domainUuid,
        string $domainName,
        string $owner,
        string $ownerKind,
        string $caller,
        string $cli,
        string $ref,
        string $callUuid,
    ): array {
        $cliDigits = ltrim($cli, '+');

        $callerVars = [
            'origination_caller_id_number' => $cli,
            'origination_caller_id_name'   => $cli,
            'effective_caller_id_number'   => $cli,
            'effective_caller_id_name'     => $cli,
            'outbound_caller_id_number'    => $cliDigits,
            'outbound_caller_id_name'      => $cli,
            'ignore_early_media'           => 'false',
            'bridge_early_media'           => 'true',
            'originate_timeout'            => (string) self::CALLER_TIMEOUT,
            'call_direction'               => 'outbound',
            'domain_uuid'                  => $domainUuid,
            'domain_name'                  => $domainName,
            'voxra_click_to_call'          => 'true',
            'voxra_escalation_ref'         => $ref,
        ];
        $pairs = [];
        foreach ($callerVars as $k => $v) {
            $pairs[] = $k . '=' . $v;
        }
        $app = sprintf('&bridge({%s}loopback/%s/%s)', implode(',', $pairs), $caller, $domainName);

        $vars = [
            'origination_uuid'             => $callUuid,
            'origination_caller_id_number' => $cli,
            'origination_caller_id_name'   => self::OWNER_CALLER_ID_NAME,
            'effective_caller_id_number'   => $cli,
            'effective_caller_id_name'     => self::OWNER_CALLER_ID_NAME,
            'outbound_caller_id_number'    => $cliDigits,
            'outbound_caller_id_name'      => self::OWNER_CALLER_ID_NAME,
            'originate_timeout'            => (string) self::OWNER_TIMEOUT,
            'ignore_early_media'           => 'true',
            'hangup_after_bridge'          => 'true',
            'call_direction'               => $ownerKind === 'extension' ? 'local' : 'outbound',
            'domain_uuid'                  => $domainUuid,
            'domain_name'                  => $domainName,
            'voxra_click_to_call'          => 'true',
            'voxra_escalation_ref'         => $ref,
        ];

        if ($ownerKind === 'extension') {
            $endpoint = sprintf('user/%s@%s', $owner, $domainName);
        } else {
            $endpoint = sprintf('loopback/%s/%s', $owner, $domainName);
            $vars['group_confirm_key'] = 'exec';
            $vars['group_confirm_file'] = ProvisionNumberService::OWNER_CONFIRM_APP;
            $vars['group_confirm_cancel_timeout'] = '1';
        }

        return [$endpoint, $app, $vars];
    }

    /** +447700900123 → +4477*****123 */
    public static function mask(string $number): string
    {
        $len = strlen($number);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }

        return substr($number, 0, 4) . str_repeat('*', $len - 7) . substr($number, -3);
    }

    private static function error(string $message, int $status): JsonResponse
    {
        return response()->json(['ok' => false, 'error' => $message], $status);
    }
}
