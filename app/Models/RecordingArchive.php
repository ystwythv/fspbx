<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One row per recording file queued for S3 archive. The unique
 * (domain_uuid, record_name) index is the atomic cross-node claim — record_name
 * is the ORIGINAL local filename; object_key holds where it ended up.
 */
class RecordingArchive extends Model
{
    use \App\Models\Traits\TraitUuid;

    const STATUS_PENDING = 'pending';
    const STATUS_ARCHIVED = 'archived';
    const STATUS_FAILED = 'failed';
    const STATUS_SKIPPED = 'skipped';

    protected $table = 'recording_archives';

    protected $primaryKey = 'uuid';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'domain_uuid',
        'xml_cdr_uuid',
        'record_name',
        'status',
        'attempts',
        'last_error',
        'bucket',
        'object_key',
        'archived_at',
    ];

    protected $casts = [
        'archived_at' => 'datetime',
    ];

    public function cdr()
    {
        return $this->belongsTo(CDR::class, 'xml_cdr_uuid', 'xml_cdr_uuid');
    }

    public function domain()
    {
        return $this->belongsTo(Domain::class, 'domain_uuid', 'domain_uuid');
    }
}
