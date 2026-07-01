<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An episodic interaction summary in the tenant/contact timeline (voxragtm#91). */
class ReceptionInteraction extends Model
{
    use \App\Models\Traits\TraitUuid;

    protected $table = 'v_reception_interactions';

    public $timestamps = false;

    protected $primaryKey = 'reception_interaction_uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'domain_uuid',
        'reception_contact_uuid',
        'conversation_id',
        'channel',
        'summary',
        'outcome',
        'occurred_at',
        'insert_date',
        'insert_user',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
    ];

    public function contact()
    {
        return $this->belongsTo(ReceptionContact::class, 'reception_contact_uuid', 'reception_contact_uuid');
    }
}
