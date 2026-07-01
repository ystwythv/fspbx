<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An appointment booked in-call by the reception agent (voxragtm#29).
 */
class ReceptionAppointment extends Model
{
    use \App\Models\Traits\TraitUuid;

    protected $table = 'v_reception_appointments';

    public $timestamps = false;

    protected $primaryKey = 'reception_appointment_uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'domain_uuid',
        'reception_lead_uuid',
        'conversation_id',
        'customer_name',
        'customer_number',
        'service',
        'starts_at',
        'ends_at',
        'deposit_amount',
        'status',
        'notes',
        'insert_date',
        'insert_user',
        'update_date',
        'update_user',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'deposit_amount' => 'decimal:2',
    ];

    public function lead()
    {
        return $this->belongsTo(ReceptionLead::class, 'reception_lead_uuid', 'reception_lead_uuid');
    }
}
