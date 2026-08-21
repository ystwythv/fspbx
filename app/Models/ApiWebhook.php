<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiWebhook extends Model
{
    use \App\Models\Traits\TraitUuid;

    public const EVENT_CDR_FINALIZED = 'cdr.finalized';

    public const EVENT_VOICEMAIL_FINALIZED = 'voicemail.finalized';

    public const SUPPORTED_EVENTS = [
        self::EVENT_CDR_FINALIZED,
        self::EVENT_VOICEMAIL_FINALIZED,
    ];

    protected $table = 'v_api_webhooks';

    protected $primaryKey = 'webhook_uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'domain_uuid',
        'url',
        'secret',
        'events',
        'enabled',
        'description',
        'last_success_at',
        'last_failure_at',
        'consecutive_failures',
    ];

    protected $casts = [
        'events' => 'array',
        'enabled' => 'boolean',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'consecutive_failures' => 'integer',
    ];

    protected $hidden = [
        'secret',
    ];

    public function domain()
    {
        return $this->belongsTo(Domain::class, 'domain_uuid', 'domain_uuid');
    }

    public function deliveries()
    {
        return $this->hasMany(ApiWebhookDelivery::class, 'webhook_uuid', 'webhook_uuid');
    }

    public function subscribesTo(string $eventType): bool
    {
        return in_array($eventType, $this->events ?? [], true);
    }

    public static function generateSecret(): string
    {
        return 'whsec_' . bin2hex(random_bytes(24));
    }
}
