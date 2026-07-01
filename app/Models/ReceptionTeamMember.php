<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Team-member identity for the reception agent (voxragtm#92). */
class ReceptionTeamMember extends Model
{
    use \App\Models\Traits\TraitUuid;

    protected $table = 'v_reception_team_members';

    public $timestamps = false;

    protected $primaryKey = 'reception_team_member_uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'domain_uuid',
        'phone_number',
        'name',
        'role',
        'insert_date',
        'insert_user',
        'update_date',
        'update_user',
    ];

    public function isOwner(): bool
    {
        return in_array($this->role, ['owner', 'admin'], true);
    }
}
