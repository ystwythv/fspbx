<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallRate extends Model
{
    use \App\Models\Traits\TraitUuid;

    protected $table = 'v_call_rates';

    protected $primaryKey = 'rate_uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'tariff_uuid',
        'destination_prefix',
        'rate_per_minute',
        'setup_fee',
        'min_duration_sec',
        'billing_increment_sec',
        'effective_from',
        'effective_to',
    ];

    protected $casts = [
        'rate_per_minute' => 'decimal:6',
        'setup_fee' => 'decimal:6',
        'min_duration_sec' => 'integer',
        'billing_increment_sec' => 'integer',
        'effective_from' => 'datetime',
        'effective_to' => 'datetime',
    ];

    public function tariff()
    {
        return $this->belongsTo(CallTariff::class, 'tariff_uuid', 'tariff_uuid');
    }
}
