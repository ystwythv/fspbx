<?php

namespace App\Services\ReceptionAgent;

use App\Models\Extensions;
use App\Models\ReceptionAppointment;
use App\Models\ReceptionContact;
use App\Models\ReceptionLead;
use App\Models\ReceptionMemory;
use App\Models\ReceptionTeamMember;
use App\Services\FreeswitchEslService;
use Illuminate\Support\Carbon;
use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Implements each tool the ElevenLabs reception agent can call mid-conversation.
 *
 * Each public method takes:
 *   $session — the resolved Redis session blob (from ReceptionAgentSummonService::loadSession)
 *   ...args  — tool-specific arguments
 *
 * and returns a small associative array that becomes the tool result spoken
 * back to the agent.
 */
class ReceptionAgentToolService
{
    private const EXT_CACHE_TTL = 300;     // 5 min
    private const WEATHER_CACHE_TTL = 600; // 10 min

    public function __construct(private FreeswitchEslService $esl)
    {
    }

    public function lookupUser(array $session, string $query): array
    {
        $domainUuid = (string) ($session['domain_uuid'] ?? '');
        if ($domainUuid === '' || trim($query) === '') {
            return ['matches' => []];
        }

        $directory = $this->extensionsForDomain($domainUuid);
        $needle = mb_strtolower(trim($query));

        $matches = [];
        foreach ($directory as $row) {
            $haystacks = [
                mb_strtolower((string)($row['effective_caller_id_name'] ?? '')),
                mb_strtolower((string)($row['directory_first_name'] ?? '')),
                mb_strtolower((string)($row['directory_last_name'] ?? '')),
                mb_strtolower((string)(
                    trim(($row['directory_first_name'] ?? '') . ' ' . ($row['directory_last_name'] ?? ''))
                )),
                (string)($row['extension'] ?? ''),
            ];
            foreach ($haystacks as $h) {
                if ($h !== '' && str_contains($h, $needle)) {
                    $matches[] = [
                        'extension' => $row['extension'],
                        'name'      => $row['effective_caller_id_name']
                            ?: trim(($row['directory_first_name'] ?? '') . ' ' . ($row['directory_last_name'] ?? '')),
                    ];
                    break;
                }
            }
            if (count($matches) >= 5) {
                break;
            }
        }

        return ['matches' => $this->annotateAvailability($matches, (string) ($session['domain_name'] ?? ''))];
    }

    /**
     * Tag each lookup match with on-hook/off-hook availability so the agent can
     * answer "is Alice available?" and decide whether to add/transfer:
     *   available = registered + not on a call
     *   busy      = currently on a call (off-hook)
     *   offline   = not registered (phone off / unreachable)
     *   unknown   = couldn't read live state
     */
    private function annotateAvailability(array $matches, string $domainName): array
    {
        if (empty($matches)) {
            return $matches;
        }

        // Extensions currently on a call (any leg whose presence_id / caller /
        // callee is this extension in this domain).
        $busy = [];
        try {
            foreach ($this->esl->getAllChannels() as $ch) {
                $pid = (string) ($ch['presence_id'] ?? '');
                if ($pid !== '' && str_ends_with($pid, '@' . $domainName)) {
                    $busy[strtok($pid, '@')] = true;
                }
                foreach (['cid_num', 'callee_num'] as $k) {
                    $v = (string) ($ch[$k] ?? '');
                    if ($v !== '' && ctype_digit($v)) {
                        $busy[$v] = true;
                    }
                }
            }
        } catch (\Throwable $e) {
            logger()->warning('lookup availability: getAllChannels failed: ' . $e->getMessage());
        }

        // Registered extensions (phone on/reachable).
        $registered = null;
        try {
            $registered = [];
            foreach ($this->esl->getAllSipRegistrations() as $reg) {
                // 'user' is the full AOR, e.g. "811@iqmobile.uk" — key by the bare
                // extension within this domain so it matches the lookup rows.
                $u = (string) ($reg['user'] ?? '');
                if ($u !== '' && str_ends_with($u, '@' . $domainName)) {
                    $registered[strtok($u, '@')] = true;
                }
            }
        } catch (\Throwable $e) {
            $registered = null; // unknown
            logger()->warning('lookup availability: getAllSipRegistrations failed: ' . $e->getMessage());
        }

        foreach ($matches as &$m) {
            $ext = (string) ($m['extension'] ?? '');
            if (isset($busy[$ext])) {
                $status = 'busy';
            } elseif ($registered === null) {
                $status = 'unknown';
            } elseif (isset($registered[$ext])) {
                $status = 'available';
            } else {
                $status = 'offline';
            }
            $m['status'] = $status;
            $m['available'] = ($status === 'available');
        }
        unset($m);

        return $matches;
    }

    public function transferCall(array $session, string $extension): array
    {
        $agentUuid      = (string) ($session['agent_uuid'] ?? '');
        $originatorUuid = (string) ($session['originator_uuid'] ?? '');
        $domainName     = (string) ($session['domain_name'] ?? '');
        $confName       = (string) ($session['conf_name'] ?? '');
        $convId         = (string) ($session['conversation_id'] ?? '');

        if ($confName === '') {
            return ['ok' => false, 'message' => 'No active conference'];
        }

        // Blind transfer: ring the target straight INTO the conference (the
        // reliable originate path the agent/three-way add already use). We must
        // NOT kill the agent + originator here synchronously — originate is async
        // (bgapi), so killing the conference members in the same breath races the
        // still-ringing target leg and FreeSWITCH aborts it (instant 503, target
        // never rings). Instead defer teardown to execute_on_answer: once the
        // target actually answers and is in the conference, the settle script
        // kills the agent + originator, leaving the original caller (peer) bridged
        // to the target. If the target never answers the agent stays live and can
        // tell the caller.
        $endpoint = sprintf('user/%s@%s', $extension, $domainName ?: '${domain_name}');
        $vars = [
            'origination_caller_id_name'   => 'Reception Transfer',
            'origination_caller_id_number' => $session['originator_extension'] ?? 'reception',
            'voxra_conversation_id'        => $convId,
            'voxra_conf_name'              => $confName,
            'execute_on_answer'            => sprintf(
                'lua lua/voxra_blind_settle.lua %s %s %s',
                $agentUuid !== '' ? $agentUuid : '-',
                $originatorUuid !== '' ? $originatorUuid : '-',
                $convId !== '' ? $convId : '-'
            ),
        ];
        $this->esl->originate($endpoint, sprintf('&conference(%s@voxra_recept)', $confName), 'default', $vars);

        return ['ok' => true, 'message' => "Transferring to extension {$extension}"];
    }

    /**
     * Announced transfer with original caller listening. The actual mute/kick
     * choreography is fired from voxra_announced_settle.lua via execute_on_answer
     * on the target leg, so the originate kickoff returns immediately and the
     * agent can speak "ringing James now" while we wait.
     */
    public function announcedTransfer(array $session, string $extension): array
    {
        $domainName = (string) ($session['domain_name'] ?? '');
        $convId     = (string) ($session['conversation_id'] ?? '');
        $confName   = (string) ($session['conf_name'] ?? '');

        if ($confName === '' || $convId === '') {
            return ['ok' => false, 'message' => 'No active conference'];
        }

        $targetLegUuid = (string) Str::uuid();

        $session['phase']           = 'announced_pending';
        $session['announced_target']= $extension;
        $session['target_leg_uuid'] = $targetLegUuid;
        ReceptionAgentSummonService::saveSession($convId, $session);

        $endpoint = sprintf('user/%s@%s', $extension, $domainName ?: '${domain_name}');
        $vars = [
            'origination_uuid'             => $targetLegUuid,
            'origination_caller_id_name'   => 'Reception Transfer',
            'origination_caller_id_number' => $session['originator_extension'] ?? 'reception',
            'voxra_conversation_id'        => $convId,
            'voxra_conf_name'              => $confName,
            // On answer, the settle script reads session state from Redis and
            // performs: mute peer, kill agent, install hangup hook on summoner.
            'execute_on_answer'            => sprintf('lua lua/voxra_announced_settle.lua %s', $convId),
        ];

        $this->esl->originate($endpoint, sprintf('&conference(%s@voxra_recept)', $confName), 'default', $vars);

        return ['ok' => true, 'message' => "Calling {$extension} now"];
    }

    public function parkCall(array $session): array
    {
        $peerUuid = (string) ($session['peer_uuid'] ?? '');
        if ($peerUuid === '') {
            return ['ok' => false, 'message' => 'No peer call to park'];
        }
        // Use the FS valet_park ext if configured, else fall back to a simple
        // park slot. Domain context preferred when known.
        $domain = (string) ($session['domain_name'] ?? 'default');
        $slot = '5901';
        $this->esl->transfer($peerUuid, $slot, 'XML', $domain);
        return ['ok' => true, 'slot' => $slot, 'message' => "Parked at slot {$slot}"];
    }

    public function bringBack(array $session, string $slot): array
    {
        $confName = (string) ($session['conf_name'] ?? '');
        if ($confName === '') {
            return ['ok' => false, 'message' => 'No active conference'];
        }
        $domain = (string) ($session['domain_name'] ?? 'default');
        // Originate back: pick up the parked slot and drop into our conference.
        $endpoint = sprintf('loopback/%s/%s', $slot, $domain);
        $this->esl->originate($endpoint, sprintf('&conference(%s@voxra_recept)', $confName), 'default', []);
        return ['ok' => true, 'message' => "Retrieving call from slot {$slot}"];
    }

    public function threeWayAdd(array $session, string $extension): array
    {
        $confName = (string) ($session['conf_name'] ?? '');
        if ($confName === '') {
            return ['ok' => false, 'message' => 'No active conference'];
        }
        $domain = (string) ($session['domain_name'] ?? '${domain_name}');

        // Play UK ringback INTO the conference while the new party rings, so the
        // existing members hear it ringing instead of silence. The originated
        // leg stops it on answer (execute_on_answer -> voxra_conf_stop_ringback).
        // L=30 caps it (~2 min) in case the party never answers.
        $this->esl->executeCommand(sprintf(
            'conference %s play tone_stream://L=30;%%(400,200,400,450);%%(400,2000,400,450) async',
            $confName
        ));

        $endpoint = sprintf('user/%s@%s', $extension, $domain);
        $this->esl->originate($endpoint, sprintf('&conference(%s@voxra_recept)', $confName), 'default', [
            'origination_caller_id_name'   => 'Three-Way',
            'origination_caller_id_number' => $session['originator_extension'] ?? 'reception',
            'execute_on_answer'            => sprintf('lua lua/voxra_conf_stop_ringback.lua %s', $confName),
        ]);

        // The agent's job is done once the party is being added — gracefully leave
        // so the humans + new party keep the call. Done server-side (deferred so
        // the agent's spoken confirmation drains first) instead of via a separate
        // complete_and_exit tool turn, which Telnyx voices as "empty response".
        $agentUuid = (string) ($session['agent_uuid'] ?? '');
        $convId    = (string) ($session['conversation_id'] ?? '');
        if ($agentUuid !== '') {
            $this->esl->executeCommand(sprintf('sched_api +4 none uuid_kill %s', $agentUuid));
        }
        if ($convId !== '') {
            ReceptionAgentSummonService::deleteSession($convId);
        }

        return ['ok' => true, 'message' => "Adding {$extension} to the call"];
    }

    public function completeAndExit(array $session, ?string $message = null): array
    {
        $agentUuid = (string) ($session['agent_uuid'] ?? '');
        $convId    = (string) ($session['conversation_id'] ?? '');

        // Defer the agent-leg hangup a few seconds so its final spoken line isn't
        // chopped off mid-word: the assistant typically says a one-line closer and
        // calls complete_and_exit in the same turn, and an immediate uuid_kill
        // truncates the in-flight TTS. sched_api lets the audio drain first.
        if ($agentUuid !== '') {
            $this->esl->executeCommand(sprintf('sched_api +4 none uuid_kill %s', $agentUuid));
        }
        if ($convId !== '') {
            ReceptionAgentSummonService::deleteSession($convId);
        }

        return ['ok' => true, 'message' => $message ?? 'Done'];
    }

    /**
     * Capture a note during the call. Notes accrue on the Redis session so they
     * can be surfaced/emailed in the post-call summary.
     */
    public function takeNotes(array $session, string $note): array
    {
        $note = trim($note);
        if ($note === '') {
            return ['ok' => false, 'message' => 'Nothing to note'];
        }

        $convId = (string) ($session['conversation_id'] ?? '');
        if ($convId !== '') {
            $current = ReceptionAgentSummonService::loadSession($convId) ?? $session;
            $notes = $current['notes'] ?? [];
            $notes[] = ['at' => now()->toIso8601String(), 'text' => $note];
            $current['notes'] = $notes;
            ReceptionAgentSummonService::saveSession($convId, $current);
        }

        // Leave gracefully after confirming (deferred so the spoken line drains)
        // rather than via a complete_and_exit tool turn, which Telnyx voices as
        // "empty response". Session is left intact so a same-breath "email me those
        // notes" can still read them within the window.
        $agentUuid = (string) ($session['agent_uuid'] ?? '');
        if ($agentUuid !== '') {
            $this->esl->executeCommand(sprintf('sched_api +4 none uuid_kill %s', $agentUuid));
        }

        return ['ok' => true, 'message' => 'Noted'];
    }

    /**
     * Email a reminder/summary to an address the caller provides.
     */
    public function emailReminder(array $session, string $to, string $subject, string $body): array
    {
        $to = trim($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'message' => 'I need a valid email address to send to'];
        }

        $subject = trim($subject) !== '' ? trim($subject) : 'Reminder from your call';
        $body = trim($body);
        if ($body === '') {
            return ['ok' => false, 'message' => 'There is nothing to send yet'];
        }

        try {
            Mail::raw($body, function ($message) use ($to, $subject) {
                $message->to($to)->subject($subject);
            });
        } catch (Throwable $e) {
            logger()->error('reception-agent email_reminder failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Sorry, I could not send that email'];
        }

        // Leave gracefully after confirming (deferred so the spoken line drains)
        // rather than via a complete_and_exit tool turn (Telnyx voices that empty
        // turn as "empty response").
        $agentUuid = (string) ($session['agent_uuid'] ?? '');
        $convId    = (string) ($session['conversation_id'] ?? '');
        if ($agentUuid !== '') {
            $this->esl->executeCommand(sprintf('sched_api +4 none uuid_kill %s', $agentUuid));
        }
        if ($convId !== '') {
            ReceptionAgentSummonService::deleteSession($convId);
        }

        return ['ok' => true, 'message' => "Emailed {$to}"];
    }

    public function getTimeInCity(string $city): array
    {
        $tz = $this->resolveTimezone($city);
        if ($tz === null) {
            return ['ok' => false, 'message' => "I don't know the timezone for {$city}"];
        }
        $now = new DateTime('now', new DateTimeZone($tz));
        return [
            'ok'   => true,
            'time' => $now->format('g:i A'),
            'date' => $now->format('l, F j'),
            'tz'   => $tz,
        ];
    }

    public function getWeather(string $city): array
    {
        $cacheKey = 'voxra:weather:' . mb_strtolower(trim($city));
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $geo = Http::timeout(3)->get('https://geocoding-api.open-meteo.com/v1/search', [
                'name' => $city,
                'count' => 1,
            ])->json();

            $place = $geo['results'][0] ?? null;
            if (!$place) {
                return ['ok' => false, 'message' => "I couldn't find {$city}"];
            }

            $forecast = Http::timeout(3)->get('https://api.open-meteo.com/v1/forecast', [
                'latitude'  => $place['latitude'],
                'longitude' => $place['longitude'],
                'current'   => 'temperature_2m,weather_code,wind_speed_10m',
                'timezone'  => 'auto',
            ])->json();

            $current = $forecast['current'] ?? [];
            $result = [
                'ok'        => true,
                'city'      => $place['name'] . ($place['country'] ? ', ' . $place['country'] : ''),
                'temp_c'    => $current['temperature_2m'] ?? null,
                'wind_kph'  => $current['wind_speed_10m'] ?? null,
                'summary'   => $this->wmoCodeToText((int) ($current['weather_code'] ?? -1)),
            ];
            Cache::put($cacheKey, $result, self::WEATHER_CACHE_TTL);
            return $result;
        } catch (Throwable $e) {
            logger()->warning('voxra weather lookup failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => "I couldn't fetch the weather right now"];
        }
    }

    /**
     * Per-domain extensions list, cached in Redis for fast fuzzy lookups.
     * Returns a small array of rows so JSON encoding stays cheap.
     */
    private function extensionsForDomain(string $domainUuid): array
    {
        $key = 'voxra:reception:extensions:' . $domainUuid;
        $cached = Redis::get($key);
        if ($cached) {
            $decoded = json_decode($cached, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $rows = Extensions::where('domain_uuid', $domainUuid)
            ->where('enabled', 'true')
            ->get(['extension', 'effective_caller_id_name', 'directory_first_name', 'directory_last_name'])
            ->map(fn($e) => [
                'extension'                => $e->extension,
                'effective_caller_id_name' => $e->effective_caller_id_name,
                'directory_first_name'     => $e->directory_first_name,
                'directory_last_name'      => $e->directory_last_name,
            ])
            ->toArray();

        Redis::setex($key, self::EXT_CACHE_TTL, json_encode($rows));
        return $rows;
    }

    private function resolveTimezone(string $city): ?string
    {
        $needle = strtolower(trim($city));
        if ($needle === '') {
            return null;
        }

        // Common cities → tz mapping for instant lookups, then fall back to
        // matching against PHP's full DateTimeZone identifier list.
        static $cityMap = [
            'new york'      => 'America/New_York',
            'newyork'       => 'America/New_York',
            'nyc'           => 'America/New_York',
            'los angeles'   => 'America/Los_Angeles',
            'la'            => 'America/Los_Angeles',
            'san francisco' => 'America/Los_Angeles',
            'chicago'       => 'America/Chicago',
            'denver'        => 'America/Denver',
            'london'        => 'Europe/London',
            'paris'         => 'Europe/Paris',
            'berlin'        => 'Europe/Berlin',
            'madrid'        => 'Europe/Madrid',
            'rome'          => 'Europe/Rome',
            'amsterdam'     => 'Europe/Amsterdam',
            'dublin'        => 'Europe/Dublin',
            'lisbon'        => 'Europe/Lisbon',
            'tokyo'         => 'Asia/Tokyo',
            'seoul'         => 'Asia/Seoul',
            'beijing'       => 'Asia/Shanghai',
            'shanghai'      => 'Asia/Shanghai',
            'hong kong'     => 'Asia/Hong_Kong',
            'singapore'     => 'Asia/Singapore',
            'mumbai'        => 'Asia/Kolkata',
            'delhi'         => 'Asia/Kolkata',
            'sydney'        => 'Australia/Sydney',
            'melbourne'     => 'Australia/Melbourne',
            'auckland'      => 'Pacific/Auckland',
            'dubai'         => 'Asia/Dubai',
            'moscow'        => 'Europe/Moscow',
            'istanbul'      => 'Europe/Istanbul',
            'cairo'         => 'Africa/Cairo',
            'johannesburg'  => 'Africa/Johannesburg',
            'sao paulo'     => 'America/Sao_Paulo',
            'rio'           => 'America/Sao_Paulo',
            'buenos aires'  => 'America/Argentina/Buenos_Aires',
            'mexico city'   => 'America/Mexico_City',
            'toronto'       => 'America/Toronto',
            'vancouver'     => 'America/Vancouver',
        ];

        if (isset($cityMap[$needle])) {
            return $cityMap[$needle];
        }

        // Fuzzy match: search timezone identifiers for the city as a substring.
        $needleSlug = str_replace(' ', '_', $needle);
        foreach (DateTimeZone::listIdentifiers() as $tz) {
            if (str_contains(strtolower($tz), $needleSlug)) {
                return $tz;
            }
        }
        return null;
    }

    private function wmoCodeToText(int $code): string
    {
        // WMO weather interpretation codes (Open-Meteo).
        return match (true) {
            $code === 0           => 'clear',
            in_array($code, [1, 2, 3], true)            => 'partly cloudy',
            in_array($code, [45, 48], true)             => 'foggy',
            in_array($code, [51, 53, 55], true)         => 'drizzle',
            in_array($code, [56, 57], true)             => 'freezing drizzle',
            in_array($code, [61, 63, 65], true)         => 'rain',
            in_array($code, [66, 67], true)             => 'freezing rain',
            in_array($code, [71, 73, 75, 77], true)     => 'snow',
            in_array($code, [80, 81, 82], true)         => 'rain showers',
            in_array($code, [85, 86], true)             => 'snow showers',
            in_array($code, [95, 96, 99], true)         => 'thunderstorms',
            default                                      => 'unsettled',
        };
    }

    // ---------------------------------------------------------------------
    // Voxra reception: qualify (capture_lead) + book (check/book) — #28/#29
    // ---------------------------------------------------------------------

    /**
     * Capture / update the caller's lead as the agent qualifies them, and flag
     * repeat callers. Idempotent per conversation. (voxragtm#28)
     */
    public function captureLead(array $session, array $args): array
    {
        $domainUuid = (string) ($session['domain_uuid'] ?? '');
        $convId     = (string) ($session['conversation_id'] ?? '');
        if ($domainUuid === '') {
            return ['ok' => false, 'message' => 'No active tenant context'];
        }

        $callerNumber = trim((string) ($args['caller_number'] ?? ''));
        $urgency      = strtolower(trim((string) ($args['urgency'] ?? '')));
        $urgency      = in_array($urgency, ['emergency', 'urgent', 'routine'], true) ? $urgency : null;

        $attrs = array_filter([
            'caller_number'   => $callerNumber ?: null,
            'name'            => trim((string) ($args['name'] ?? '')) ?: null,
            'postcode'        => strtoupper(trim((string) ($args['postcode'] ?? ''))) ?: null,
            'job_description' => trim((string) ($args['job_description'] ?? '')) ?: null,
            'urgency'         => $urgency,
        ], fn ($v) => $v !== null);

        // Repeat-caller recognition: prior lead for this number in the tenant.
        $returning = false;
        $previousJob = null;
        if ($callerNumber !== '') {
            $prior = ReceptionLead::where('domain_uuid', $domainUuid)
                ->where('caller_number', $callerNumber)
                ->when($convId !== '', fn ($q) => $q->where('conversation_id', '!=', $convId))
                ->orderByDesc('insert_date')
                ->first();
            if ($prior) {
                $returning = true;
                $previousJob = $prior->job_description;
            }
        }

        $lead = $convId !== ''
            ? ReceptionLead::where('domain_uuid', $domainUuid)->where('conversation_id', $convId)->first()
            : null;
        $isNewLead = $lead === null;

        if ($lead) {
            $lead->fill($attrs);
            if (($lead->status ?? 'new') === 'new') {
                $lead->status = 'qualified';
            }
            $lead->update_date = now();
            $lead->save();
        } else {
            $lead = ReceptionLead::create(array_merge([
                'domain_uuid'     => $domainUuid,
                'conversation_id' => $convId ?: null,
                'status'          => 'qualified',
                'insert_date'     => now(),
            ], $attrs));
        }

        // Contact memory: touch the per-customer record (voxragtm#89). Only count
        // a call on the first capture of this conversation.
        $contact = $this->touchContact(
            $domainUuid,
            $callerNumber,
            trim((string) ($args['name'] ?? '')) ?: null,
            $isNewLead,
            false,
        );

        return [
            'ok'               => true,
            'message'          => $returning
                ? 'Noted — I recognise this number from a previous call.'
                : 'Got it, thank you.',
            'returning_caller' => $returning,
            'previous_job'     => $previousJob,
            'times_called'     => $contact?->total_calls,
            'notes_on_file'    => $contact?->notes ?: null,
            'lead_ref'         => substr((string) $lead->reception_lead_uuid, 0, 8),
        ];
    }

    // ----- contact memory (voxragtm#89) --------------------------------------

    private function normNumber(?string $number): ?string
    {
        $n = trim((string) $number);
        return $n !== '' ? $n : null;
    }

    private function resolveContact(string $domainUuid, ?string $number): ?ReceptionContact
    {
        $number = $this->normNumber($number);
        if ($number === null) {
            return null;
        }
        return ReceptionContact::where('domain_uuid', $domainUuid)
            ->where('phone_number', $number)
            ->first();
    }

    /**
     * Upsert the caller's contact record, bumping counters. Counts are opt-in per
     * call so we don't over-count repeated tool calls within one conversation.
     */
    private function touchContact(
        string $domainUuid,
        ?string $number,
        ?string $name,
        bool $newConversation,
        bool $newBooking,
    ): ?ReceptionContact {
        $number = $this->normNumber($number);
        if ($number === null) {
            return null;
        }
        $name = trim((string) $name) ?: null;

        $contact = ReceptionContact::where('domain_uuid', $domainUuid)
            ->where('phone_number', $number)
            ->first();

        if (!$contact) {
            return ReceptionContact::create([
                'domain_uuid'    => $domainUuid,
                'phone_number'   => $number,
                'name'           => $name,
                'first_seen_at'  => now(),
                'last_seen_at'   => now(),
                'total_calls'    => $newConversation ? 1 : 0,
                'total_bookings' => $newBooking ? 1 : 0,
                'insert_date'    => now(),
            ]);
        }

        if ($name && !$contact->name) {
            $contact->name = $name;
        }
        $contact->last_seen_at = now();
        if ($newConversation) {
            $contact->total_calls = (int) $contact->total_calls + 1;
        }
        if ($newBooking) {
            $contact->total_bookings = (int) $contact->total_bookings + 1;
        }
        $contact->update_date = now();
        $contact->save();

        return $contact;
    }

    /** Return the caller's number from args, else the session (inbound). */
    private function callerFromArgsOrSession(array $session, array $args): ?string
    {
        return $this->normNumber(
            (string) ($args['number'] ?? $args['caller_number'] ?? $session['caller_number'] ?? ''),
        );
    }

    /**
     * Recall what we know about the current caller (or a given number) so the
     * agent can greet returning customers by context. (voxragtm#89)
     */
    public function recallCaller(array $session, array $args): array
    {
        $domainUuid = (string) ($session['domain_uuid'] ?? '');
        if ($domainUuid === '') {
            return ['ok' => false, 'message' => 'No active tenant context'];
        }
        $number = $this->callerFromArgsOrSession($session, $args);
        if ($number === null) {
            return ['ok' => true, 'found' => false, 'message' => "I don't have a number to look up."];
        }

        $contact = $this->resolveContact($domainUuid, $number);
        if (!$contact) {
            return ['ok' => true, 'found' => false, 'message' => 'No history for this caller yet.'];
        }

        return [
            'ok'            => true,
            'found'         => true,
            'name'          => $contact->name,
            'times_called'  => (int) $contact->total_calls,
            'times_booked'  => (int) $contact->total_bookings,
            'last_seen'     => $contact->last_seen_at ? $contact->last_seen_at->toDateString() : null,
            'notes'         => $contact->notes,
        ];
    }

    /**
     * Save a note about the caller to their record so the whole team remembers it
     * next time (e.g. "prefers mornings", "gate code 1234"). (voxragtm#89)
     */
    public function rememberAboutCaller(array $session, array $args): array
    {
        $domainUuid = (string) ($session['domain_uuid'] ?? '');
        if ($domainUuid === '') {
            return ['ok' => false, 'message' => 'No active tenant context'];
        }
        $note = trim((string) ($args['note'] ?? ''));
        if ($note === '') {
            return ['ok' => false, 'message' => 'What should I remember?'];
        }
        $number = $this->callerFromArgsOrSession($session, $args);
        if ($number === null) {
            return ['ok' => false, 'message' => "I need the caller's number to save that."];
        }

        $contact = $this->touchContact($domainUuid, $number, null, false, false);
        $line = '[' . now()->format('Y-m-d') . '] ' . $note;
        $contact->notes = $contact->notes ? $contact->notes . "\n" . $line : $line;
        $contact->update_date = now();
        $contact->save();

        return ['ok' => true, 'message' => "Saved to their record."];
    }

    // ----- team identity (voxragtm#92) ---------------------------------------

    private function resolveTeamMember(string $domainUuid, ?string $number): ?ReceptionTeamMember
    {
        $number = $this->normNumber($number);
        if ($number === null) {
            return null;
        }
        return ReceptionTeamMember::where('domain_uuid', $domainUuid)
            ->where('phone_number', $number)
            ->first();
    }

    // ----- business memory (voxragtm#90) -------------------------------------

    /**
     * Remember a durable business fact/preference for the tenant, shared across
     * the team. Provenance is recorded from the speaker; sensitive changes
     * (pricing/policy) by a non-owner are saved as `pending` for approval.
     */
    public function remember(array $session, array $args): array
    {
        $domainUuid = (string) ($session['domain_uuid'] ?? '');
        if ($domainUuid === '') {
            return ['ok' => false, 'message' => 'No active tenant context'];
        }
        $fact = trim((string) ($args['fact'] ?? ''));
        if ($fact === '') {
            return ['ok' => false, 'message' => 'What should I remember?'];
        }
        $category = strtolower(trim((string) ($args['category'] ?? 'general'))) ?: 'general';

        $speakerNumber = $this->normNumber((string) ($session['caller_number'] ?? ''));
        $member = $this->resolveTeamMember($domainUuid, $speakerNumber);
        $isOwner = $member?->isOwner() ?? false;

        $sensitive = in_array($category, ReceptionMemory::SENSITIVE, true);
        $status = ($sensitive && !$isOwner) ? 'pending' : 'active';

        ReceptionMemory::create([
            'domain_uuid'       => $domainUuid,
            'category'          => $category,
            'fact'              => $fact,
            'status'            => $status,
            'created_by_number' => $speakerNumber,
            'created_by_name'   => $member?->name,
            'source'            => (string) ($session['source'] ?? '') ?: null,
            'insert_date'       => now(),
        ]);

        return [
            'ok'      => true,
            'status'  => $status,
            'message' => $status === 'pending'
                ? "I've noted that, but a change to {$category} needs the owner to confirm before I use it."
                : "Got it — I'll remember that.",
        ];
    }

    /**
     * Recall the tenant's active business facts (optionally filtered), so the
     * agent can answer using what the owner has told it over time.
     */
    public function recallBusiness(array $session, array $args): array
    {
        $domainUuid = (string) ($session['domain_uuid'] ?? '');
        if ($domainUuid === '') {
            return ['ok' => false, 'message' => 'No active tenant context'];
        }
        $category = strtolower(trim((string) ($args['category'] ?? '')));

        $facts = ReceptionMemory::where('domain_uuid', $domainUuid)
            ->where('status', 'active')
            ->when($category !== '', fn ($q) => $q->where('category', $category))
            ->orderByDesc('insert_date')
            ->limit(50)
            ->get()
            ->map(fn ($m) => ['category' => $m->category, 'fact' => $m->fact])
            ->all();

        return ['ok' => true, 'count' => count($facts), 'facts' => $facts];
    }

    /**
     * A short natural-language brief about a caller + the business, for the
     * retrieval layer to inject at conversation start (voxragtm#93).
     */
    public function callerContextBrief(string $domainUuid, ?string $callerNumber): string
    {
        $parts = [];

        $contact = $this->resolveContact($domainUuid, $callerNumber);
        if ($contact) {
            $bits = [];
            if ($contact->name) $bits[] = "name {$contact->name}";
            $bits[] = "{$contact->total_calls} previous call(s)";
            if ((int) $contact->total_bookings > 0) $bits[] = "{$contact->total_bookings} booking(s)";
            if ($contact->notes) $bits[] = "notes: " . str_replace("\n", "; ", $contact->notes);
            $parts[] = "Returning caller — " . implode(", ", $bits) . ".";
        }

        $facts = ReceptionMemory::where('domain_uuid', $domainUuid)
            ->where('status', 'active')
            ->orderByDesc('insert_date')
            ->limit(8)
            ->pluck('fact')
            ->all();
        if ($facts) {
            $parts[] = "Business notes to honour: " . implode("; ", $facts) . ".";
        }

        return trim(implode(" ", $parts));
    }

    /**
     * Return free hourly slots within business hours (09:00–17:00) for a day,
     * excluding times already booked for this tenant. (voxragtm#29)
     */
    public function checkAvailability(array $session, array $args): array
    {
        $domainUuid = (string) ($session['domain_uuid'] ?? '');
        if ($domainUuid === '') {
            return ['ok' => false, 'message' => 'No active tenant context'];
        }

        $dateStr = trim((string) ($args['date'] ?? ''));
        try {
            $day = $dateStr !== '' ? Carbon::parse($dateStr) : null;
        } catch (Throwable $e) {
            $day = null;
        }
        if (!$day) {
            return ['ok' => false, 'message' => 'Which day would you like?'];
        }

        $booked = ReceptionAppointment::where('domain_uuid', $domainUuid)
            ->where('status', 'booked')
            ->whereBetween('starts_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->get()
            ->map(fn ($a) => Carbon::parse($a->starts_at)->format('H:i'))
            ->all();

        $free = [];
        for ($h = 9; $h < 17; $h++) {
            $slot = $day->copy()->setTime($h, 0);
            if (!in_array($slot->format('H:i'), $booked, true)) {
                $free[] = $slot->format('g:i A');
            }
        }

        return [
            'ok'        => true,
            'date'      => $day->format('l, F j'),
            'available' => count($free) > 0,
            'slots'     => array_slice($free, 0, 4),
            'message'   => count($free) > 0
                ? 'I can offer ' . implode(', ', array_slice($free, 0, 4))
                : 'That day is fully booked.',
        ];
    }

    /**
     * Book an appointment into the tenant diary, linking the current lead.
     * Rejects clashes with existing bookings. (voxragtm#29)
     */
    public function bookAppointment(array $session, array $args): array
    {
        $domainUuid = (string) ($session['domain_uuid'] ?? '');
        $convId     = (string) ($session['conversation_id'] ?? '');
        if ($domainUuid === '') {
            return ['ok' => false, 'message' => 'No active tenant context'];
        }

        $startsRaw = trim((string) ($args['starts_at'] ?? ''));
        $service   = trim((string) ($args['service'] ?? ''));
        if ($startsRaw === '') {
            return ['ok' => false, 'message' => 'What date and time works for you?'];
        }
        try {
            $starts = Carbon::parse($startsRaw);
        } catch (Throwable $e) {
            return ['ok' => false, 'message' => "Sorry, I didn't catch the time — could you say it again?"];
        }

        $duration = (int) ($args['duration_minutes'] ?? 60);
        $duration = $duration > 0 ? $duration : 60;
        $ends = $starts->copy()->addMinutes($duration);

        $clash = ReceptionAppointment::where('domain_uuid', $domainUuid)
            ->where('status', 'booked')
            ->where('starts_at', '<', $ends)
            ->where(function ($q) use ($starts) {
                $q->whereNull('ends_at')->orWhere('ends_at', '>', $starts);
            })
            ->exists();
        if ($clash) {
            return ['ok' => false, 'message' => 'That slot was just taken — shall I offer another time?'];
        }

        $lead = $convId !== ''
            ? ReceptionLead::where('domain_uuid', $domainUuid)->where('conversation_id', $convId)->first()
            : null;

        $deposit = isset($args['deposit_amount']) && $args['deposit_amount'] !== ''
            ? (float) $args['deposit_amount']
            : null;

        $appt = ReceptionAppointment::create(array_filter([
            'domain_uuid'         => $domainUuid,
            'reception_lead_uuid' => $lead?->reception_lead_uuid,
            'conversation_id'     => $convId ?: null,
            'customer_name'       => (trim((string) ($args['customer_name'] ?? '')) ?: $lead?->name) ?: null,
            'customer_number'     => (trim((string) ($args['customer_number'] ?? '')) ?: $lead?->caller_number) ?: null,
            'service'             => $service ?: null,
            'starts_at'           => $starts,
            'ends_at'             => $ends,
            'deposit_amount'      => $deposit,
            'status'              => 'booked',
            'insert_date'         => now(),
        ], fn ($v) => $v !== null));

        if ($lead) {
            $lead->status = 'booked';
            $lead->update_date = now();
            $lead->save();
        }

        // Contact memory: record the booking against the caller. (voxragtm#89)
        $this->touchContact(
            $domainUuid,
            (trim((string) ($args['customer_number'] ?? '')) ?: $lead?->caller_number) ?: null,
            (trim((string) ($args['customer_name'] ?? '')) ?: $lead?->name) ?: null,
            false,
            true,
        );

        return [
            'ok'              => true,
            'message'         => sprintf(
                'Booked %s for %s.',
                $service !== '' ? $service : 'the appointment',
                $starts->format('l, F j \a\t g:i A'),
            ),
            'appointment_ref' => substr((string) $appt->reception_appointment_uuid, 0, 8),
            'starts_at'       => $starts->toIso8601String(),
        ];
    }
}
