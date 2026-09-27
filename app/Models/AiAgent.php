<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class AiAgent extends Model
{
    use HasFactory, \App\Models\Traits\TraitUuid;

    protected $table = 'v_ai_agents';

    public $timestamps = false;

    protected $primaryKey = 'ai_agent_uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'domain_uuid',
        'dialplan_uuid',
        'agent_name',
        'agent_extension',
        'provider',
        'model',
        'elevenlabs_agent_id',
        'elevenlabs_phone_number_id',
        'telnyx_assistant_id',
        'telnyx_uac_connection_id',
        'telnyx_attach_extension_uuid',
        'telnyx_attach_extension',
        'system_prompt',
        'first_message',
        'voice_id',
        'language',
        'agent_enabled',
        'description',
        'mode',
        'tools_enabled',
        'feature_code',
        'bind_dialplan_uuid',
        'insert_date',
        'insert_user',
        'update_date',
        'update_user',
    ];

    protected $casts = [
        'tools_enabled' => 'array',
    ];

    public const MODE_DIRECT = 'direct';
    public const MODE_RECEPTION = 'reception';

    public const PROVIDER_ELEVENLABS = 'elevenlabs';
    public const PROVIDER_TELNYX = 'telnyx';

    public function scopeReception($query)
    {
        return $query->where('mode', self::MODE_RECEPTION);
    }

    public function scopeForDomain($query, string $domainUuid)
    {
        return $query->where('domain_uuid', $domainUuid);
    }

    public function domain()
    {
        return $this->belongsTo(Domain::class, 'domain_uuid', 'domain_uuid');
    }

    public function kbDocuments()
    {
        return $this->hasMany(AiAgentKbDocument::class, 'ai_agent_uuid', 'ai_agent_uuid');
    }

    public function getId()
    {
        return $this->agent_extension;
    }

    public function getName()
    {
        return $this->agent_extension . ' - ' . $this->agent_name;
    }

    public function getNameFormattedAttribute()
    {
        return $this->agent_extension . ' - ' . $this->agent_name;
    }

    /** AI-agent extension range (inclusive). */
    public const AGENT_EXTENSION_MIN = 9250;
    public const AGENT_EXTENSION_MAX = 9299;

    /**
     * Numbers inside the agent range that other Voxra features own, so no
     * allocator may hand them to an agent (voxragtm#110): 9260 is the Voxra
     * Line follow-me/voicemail extension (ProvisionLineService). An agent on
     * 9260 would collide with the Line extension's dialplan and voicemail box
     * the moment the tenant moves to (or back to) Line.
     */
    public const RESERVED_AGENT_EXTENSIONS = [\App\Services\ProvisionLineService::LINE_EXTENSION];

    /**
     * First free agent extension in 9250-9299, skipping $used and the
     * reserved numbers. Pure — shared by every agent allocator.
     *
     * @param  iterable<int|string>  $used
     */
    public static function firstFreeAgentExtension(iterable $used): ?string
    {
        $taken = [];
        foreach ($used as $u) {
            $taken[(string) $u] = true;
        }
        foreach (self::RESERVED_AGENT_EXTENSIONS as $r) {
            $taken[(string) $r] = true;
        }

        for ($ext = self::AGENT_EXTENSION_MIN; $ext <= self::AGENT_EXTENSION_MAX; $ext++) {
            if (! isset($taken[(string) $ext])) {
                return (string) $ext;
            }
        }

        return null;
    }

    /**
     * Generates a unique sequence number in the 9250-9299 range (never a
     * reserved one — see RESERVED_AGENT_EXTENSIONS).
     */
    public function generateUniqueSequenceNumber(): ?string
    {
        $domainUuid = session('domain_uuid');

        $usedExtensions = Dialplans::where('domain_uuid', $domainUuid)
            ->where('dialplan_number', 'not like', '*%')
            ->pluck('dialplan_number')
            ->merge(
                Voicemails::where('domain_uuid', $domainUuid)->pluck('voicemail_id')
            )
            ->merge(
                Extensions::where('domain_uuid', $domainUuid)->pluck('extension')
            );

        return self::firstFreeAgentExtension($usedExtensions);
    }
}
