<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per end-customer memory: a caller's aggregate history within a tenant,
 * shared across the team. (voxragtm#89)
 */
class ReceptionContact extends Model
{
    use \App\Models\Traits\TraitUuid;

    protected $table = 'v_reception_contacts';

    public $timestamps = false;

    protected $primaryKey = 'reception_contact_uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'domain_uuid',
        'phone_number',
        'name',
        'first_seen_at',
        'last_seen_at',
        'total_calls',
        'total_bookings',
        'notes',
        'preferences',
        'insert_date',
        'insert_user',
        'update_date',
        'update_user',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'total_calls' => 'integer',
        'total_bookings' => 'integer',
        'preferences' => 'array',
    ];
}
