<?php

namespace App\Services\ReceptionAgent;

use App\Models\Extensions;
use App\Services\FreeswitchEslService;
use DateTime;
use DateTimeZone;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

        return ['matches' => $matches];
    }

    public function transferCall(array $session, string $extension): array
    {
        $peerUuid       = (string) ($session['peer_uuid'] ?? '');
        $agentUuid      = (string) ($session['agent_uuid'] ?? '');
        $originatorUuid = (string) ($session['originator_uuid'] ?? '');
        $domainName     = (string) ($session['domain_name'] ?? '');
        $convId         = (string) ($session['conversation_id'] ?? '');

        if ($peerUuid === '') {
            return ['ok' => false, 'message' => 'No peer call to transfer'];
        }

        $this->esl->transfer($peerUuid, $extension, 'XML', $domainName ?: 'default');
        if ($agentUuid !== '') {
            $this->esl->killChannel($agentUuid);
        }
        if ($originatorUuid !== '') {
            $this->esl->killChannel($originatorUuid);
        }
        if ($convId !== '') {
            ReceptionAgentSummonService::deleteSession($convId);
        }

        return ['ok' => true, 'message' => "Transferred to extension {$extension}"];
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
            'execute_on_answer'            => sprintf('lua voxra_announced_settle.lua %s', $convId),
        ];

        $this->esl->originate($endpoint, sprintf('&conference(%s@default)', $confName), 'default', $vars);

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
        $this->esl->originate($endpoint, sprintf('&conference(%s@default)', $confName), 'default', []);
        return ['ok' => true, 'message' => "Retrieving call from slot {$slot}"];
    }

    public function threeWayAdd(array $session, string $extension): array
    {
        $confName = (string) ($session['conf_name'] ?? '');
        if ($confName === '') {
            return ['ok' => false, 'message' => 'No active conference'];
        }
        $domain = (string) ($session['domain_name'] ?? '${domain_name}');
        $endpoint = sprintf('user/%s@%s', $extension, $domain);
        $this->esl->originate($endpoint, sprintf('&conference(%s@default)', $confName), 'default', [
            'origination_caller_id_name'   => 'Three-Way',
            'origination_caller_id_number' => $session['originator_extension'] ?? 'reception',
        ]);
        return ['ok' => true, 'message' => "Adding {$extension} to the call"];
    }

    public function completeAndExit(array $session, ?string $message = null): array
    {
        $agentUuid = (string) ($session['agent_uuid'] ?? '');
        $convId    = (string) ($session['conversation_id'] ?? '');

        if ($agentUuid !== '') {
            $this->esl->killChannel($agentUuid);
        }
        if ($convId !== '') {
            ReceptionAgentSummonService::deleteSession($convId);
        }

        return ['ok' => true, 'message' => $message ?? 'Done'];
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
}
