<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A lead captured by the reception agent while qualifying a caller (voxragtm#28).
 */
class ReceptionLead extends Model
{
    use \App\Models\Traits\TraitUuid;

    protected $table = 'v_reception_leads';

    public $timestamps = false;

    protected $primaryKey = 'reception_lead_uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'domain_uuid',
        'conversation_id',
        'caller_number',
        'name',
        'postcode',
        'job_description',
        'urgency',
        'notes',
        'status',
        'insert_date',
        'insert_user',
        'update_date',
        'update_user',
    ];

    public function appointments()
    {
        return $this->hasMany(ReceptionAppointment::class, 'reception_lead_uuid', 'reception_lead_uuid');
    }
}
