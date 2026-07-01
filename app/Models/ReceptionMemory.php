<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A durable business fact/preference for a tenant (voxragtm#90). */
class ReceptionMemory extends Model
{
    use \App\Models\Traits\TraitUuid;

    protected $table = 'v_reception_memory';

    public $timestamps = false;

    protected $primaryKey = 'reception_memory_uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'domain_uuid',
        'category',
        'fact',
        'status',
        'created_by_number',
        'created_by_name',
        'source',
        'insert_date',
        'insert_user',
        'update_date',
        'update_user',
    ];

    /** Categories where a change by a non-owner should wait for approval. */
    public const SENSITIVE = ['pricing', 'policy'];
}
