<?php

namespace App\Services\ReceptionAgent;

use App\Models\AiAgent;
use App\Models\Domain;
use App\Services\FreeswitchEslService;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;

class ReceptionAgentSummonService
{
    public const SESSION_KEY_PREFIX = 'voxra:reception:';
    public const SESSION_TTL_SECONDS = 4 * 3600;

    public function __construct(private FreeswitchEslService $esl)
    {
    }

    /**
     * Spawn the ElevenLabs reception agent into an existing conference.
     *
     * Called from the *9 dialplan path (via voxra-summon-reception-agent.lua) once
     * the originator and peer have been transferred into a fresh conference. We
     * originate a third leg out to ElevenLabs and drop it straight into the same
     * conference, with SIP custom headers carrying the conversation context so
     * tool callbacks know which call/domain to act on.
     *
     * @return array{conf_name:string, agent_uuid:string, conversation_id:string}
     */
    public function summon(array $payload): array
    {
        $confName       = (string) ($payload['conf_name'] ?? '');
        $domainUuid     = (string) ($payload['domain_uuid'] ?? '');
        $originatorUuid = (string) ($payload['originator_uuid'] ?? '');
        $peerUuid       = (string) ($payload['peer_uuid'] ?? '');
        $originatorExt  = (string) ($payload['originator_extension'] ?? '');

        if ($confName === '' || $domainUuid === '' || $originatorUuid === '') {
            throw new RuntimeException('summon: conf_name, domain_uuid, originator_uuid required');
        }

        $agent = AiAgent::reception()
            ->forDomain($domainUuid)
            ->where('agent_enabled', 'true')
            ->first();

        if (!$agent || !$agent->elevenlabs_agent_id || !$agent->agent_extension) {
            throw new RuntimeException('No active reception agent provisioned for domain ' . $domainUuid);
        }

        $domain = Domain::where('domain_uuid', $domainUuid)->first();
        $domainName = $domain?->domain_name ?? '';

        $conversationId = (string) Str::uuid();
        $agentLegUuid   = (string) Str::uuid();

        $endpoint = sprintf(
            'sofia/external/sip:%s@sip.rtc.elevenlabs.io:5060;transport=tcp',
            $agent->agent_extension
        );

        $vars = [
            'origination_uuid'              => $agentLegUuid,
            'origination_caller_id_name'    => 'Reception Agent',
            'origination_caller_id_number'  => $agent->agent_extension,
            'ignore_early_media'            => 'true',
            'absolute_codec_string'         => 'PCMU,PCMA',
            'hangup_after_bridge'           => 'false',
            'voxra_conf_name'               => $confName,
            'voxra_conversation_id'         => $conversationId,
            'voxra_domain_uuid'             => $domainUuid,
            'voxra_originator_uuid'         => $originatorUuid,
            'voxra_peer_uuid'               => $peerUuid,
            'voxra_originator_extension'    => $originatorExt,
            'sip_h_X-Voxra-Conversation-Id' => $conversationId,
            'sip_h_X-Voxra-Domain-Uuid'     => $domainUuid,
            'sip_h_X-Voxra-Originator-Ext'  => $originatorExt,
            'sip_h_X-Voxra-Origin-Uuid'     => $originatorUuid,
            'sip_h_X-Voxra-Peer-Uuid'       => $peerUuid,
            'sip_h_X-Voxra-Conf-Name'       => $confName,
        ];

        $this->esl->originate(
            $endpoint,
            sprintf('&conference(%s@default)', $confName),
            'default',
            $vars
        );

        $session = [
            'conf_name'             => $confName,
            'conversation_id'       => $conversationId,
            'domain_uuid'           => $domainUuid,
            'domain_name'           => $domainName,
            'originator_uuid'       => $originatorUuid,
            'originator_extension'  => $originatorExt,
            'peer_uuid'             => $peerUuid,
            'agent_uuid'            => $agentLegUuid,
            'ai_agent_uuid'         => $agent->ai_agent_uuid,
            'phase'                 => 'three_way',
            'created_at'            => now()->toIso8601String(),
        ];

        Redis::setex(
            self::SESSION_KEY_PREFIX . $conversationId,
            self::SESSION_TTL_SECONDS,
            json_encode($session)
        );
        // Index by conf_name too so tool-side lookups by conf are easy.
        Redis::setex(
            self::SESSION_KEY_PREFIX . 'conf:' . $confName,
            self::SESSION_TTL_SECONDS,
            $conversationId
        );

        return [
            'conf_name'       => $confName,
            'agent_uuid'      => $agentLegUuid,
            'conversation_id' => $conversationId,
        ];
    }

    public static function loadSession(string $conversationId): ?array
    {
        $raw = Redis::get(self::SESSION_KEY_PREFIX . $conversationId);
        return $raw ? json_decode($raw, true) : null;
    }

    public static function saveSession(string $conversationId, array $session): void
    {
        Redis::setex(
            self::SESSION_KEY_PREFIX . $conversationId,
            self::SESSION_TTL_SECONDS,
            json_encode($session)
        );
    }

    public static function deleteSession(string $conversationId): void
    {
        $session = self::loadSession($conversationId);
        Redis::del(self::SESSION_KEY_PREFIX . $conversationId);
        if ($session && !empty($session['conf_name'])) {
            Redis::del(self::SESSION_KEY_PREFIX . 'conf:' . $session['conf_name']);
        }
    }
}
