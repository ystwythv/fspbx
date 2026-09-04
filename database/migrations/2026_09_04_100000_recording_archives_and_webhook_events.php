<?php

use Illuminate\Support\Str;
use App\Models\DefaultSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Per-domain S3 archive (issues #105/#106) and recording.archived webhook
 * event with storage metadata (#107).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('recording_archives')) {
            Schema::create('recording_archives', function (Blueprint $table) {
                $table->uuid('uuid')->primary();
                $table->uuid('domain_uuid')->index();
                $table->uuid('xml_cdr_uuid');
                $table->string('record_name', 255);
                $table->string('status', 20)->default('pending');
                $table->integer('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->string('bucket', 255)->nullable();
                $table->string('object_key', 1024)->nullable();
                $table->timestamp('archived_at')->nullable();
                $table->timestamps();

                // One archive per recording file per domain — the atomic claim
                // that stops both cluster nodes queueing the same upload
                $table->unique(['domain_uuid', 'record_name']);
                $table->index(['status', 'created_at']);
            });
        }

        Schema::table('recording_webhook_deliveries', function (Blueprint $table) {
            if (!Schema::hasColumn('recording_webhook_deliveries', 'event')) {
                $table->string('event', 40)->default('recording.available')->after('record_name');
            }
            if (!Schema::hasColumn('recording_webhook_deliveries', 'storage_type')) {
                $table->string('storage_type', 10)->nullable()->after('event');
            }
        });

        // Widen the claim to (domain, file, event) so recording.archived can
        // follow recording.available for the same file
        Schema::table('recording_webhook_deliveries', function (Blueprint $table) {
            $table->dropUnique('recording_webhook_deliveries_domain_uuid_record_name_unique');
            $table->unique(['domain_uuid', 'record_name', 'event'], 'recording_webhook_deliveries_domain_file_event_unique');
        });

        // Everything sent before this migration went out as a local URL
        // (S3 archiving was never enabled in production); mark it so the
        // archived-event pass has a correct baseline.
        DB::table('recording_webhook_deliveries')
            ->whereNull('storage_type')
            ->where('status', 'sent')
            ->update(['storage_type' => 'local']);

        $this->seedDefaultSettings();
    }

    public function down(): void
    {
        Schema::dropIfExists('recording_archives');

        Schema::table('recording_webhook_deliveries', function (Blueprint $table) {
            $table->dropUnique('recording_webhook_deliveries_domain_file_event_unique');
            $table->unique(['domain_uuid', 'record_name']);
            $table->dropColumn(['event', 'storage_type']);
        });

        DefaultSettings::where('default_setting_category', 's3_storage')
            ->where('default_setting_subcategory', 'enabled')
            ->delete();
        DefaultSettings::where('default_setting_category', 'scheduled_jobs')
            ->where('default_setting_subcategory', 'recording_archive')
            ->delete();
        DefaultSettings::where('default_setting_category', 'recording_webhook')
            ->where('default_setting_subcategory', 'events')
            ->delete();
    }

    private function seedDefaultSettings(): void
    {
        $settings = [
            [
                'default_setting_category'      => 's3_storage',
                'default_setting_subcategory'   => 'enabled',
                'default_setting_name'          => 'boolean',
                'default_setting_value'         => 'false',
                'default_setting_enabled'       => true,
                'default_setting_description'   => 'Archive call recordings to S3-compatible object storage. Override per domain in Domain Settings (category s3_storage) to send a tenant\'s recordings to their own bucket.',
            ],
            [
                'default_setting_category'      => 'scheduled_jobs',
                'default_setting_subcategory'   => 'recording_archive',
                'default_setting_name'          => 'boolean',
                'default_setting_value'         => 'true',
                'default_setting_enabled'       => true,
                'default_setting_description'   => 'Queue an S3 archive job for each new call recording in archive-enabled domains every minute.',
            ],
            [
                'default_setting_category'      => 'recording_webhook',
                'default_setting_subcategory'   => 'events',
                'default_setting_name'          => 'text',
                'default_setting_value'         => 'recording.available',
                'default_setting_enabled'       => true,
                'default_setting_description'   => 'Comma-separated recording webhook events to send: recording.available, recording.archived.',
            ],
        ];

        foreach ($settings as $setting) {
            $exists = DefaultSettings::where('default_setting_category', $setting['default_setting_category'])
                ->where('default_setting_subcategory', $setting['default_setting_subcategory'])
                ->where('default_setting_name', $setting['default_setting_name'])
                ->exists();

            if (!$exists) {
                DefaultSettings::create(array_merge($setting, [
                    'default_setting_uuid' => (string) Str::uuid(),
                ]));
            }
        }
    }
};
