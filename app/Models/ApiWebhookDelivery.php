<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ApiWebhookDelivery extends Model
{
    use \App\Models\Traits\TraitUuid;

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $table = 'v_api_webhook_deliveries';

    protected $primaryKey = 'delivery_uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'webhook_uuid',
        'domain_uuid',
        'event_type',
        'resource_uuid',
        'status',
        'attempts',
        'last_error',
        'sent_at',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'sent_at' => 'datetime',
    ];

    public function webhook()
    {
        return $this->belongsTo(ApiWebhook::class, 'webhook_uuid', 'webhook_uuid');
    }
}
