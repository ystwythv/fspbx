<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * FusionPBX per-extension settings (v_extension_settings). Used here for
 * directory "variable"-type rows such as wakeword_enabled, which directory.lua
 * emits into the extension directory and user_data reads at call time.
 */
class ExtensionSetting extends Model
{
    protected $table = 'v_extension_settings';
    protected $primaryKey = 'extension_setting_uuid';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;

    protected $fillable = [
        'extension_setting_uuid',
        'extension_uuid',
        'domain_uuid',
        'extension_setting_type',
        'extension_setting_name',
        'extension_setting_value',
        'extension_setting_enabled',
        'extension_setting_description',
        'insert_date',
        'insert_user',
    ];

    protected static function booted()
    {
        static::creating(function ($model) {
            if (empty($model->extension_setting_uuid)) {
                $model->extension_setting_uuid = (string) Str::uuid();
            }
        });
    }

    public function extension()
    {
        return $this->belongsTo(Extensions::class, 'extension_uuid', 'extension_uuid');
    }
}
