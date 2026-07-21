<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CallTariff extends Model
{
    use \App\Models\Traits\TraitUuid;

    protected $table = 'v_call_tariffs';

    protected $primaryKey = 'tariff_uuid';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'domain_uuid',
        'tariff_name',
        'currency',
        'description',
        'enabled',
    ];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    public function rates()
    {
        return $this->hasMany(CallRate::class, 'tariff_uuid', 'tariff_uuid');
    }

    public function domain()
    {
        return $this->belongsTo(Domain::class, 'domain_uuid', 'domain_uuid');
    }
}
