<?php

use Illuminate\Support\Str;
use App\Models\DefaultSettings;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (!Schema::hasTable('recording_webhook_deliveries')) {
            Schema::create('recording_webhook_deliveries', function (Blueprint $table) {
                $table->uuid('uuid')->primary();
                $table->uuid('domain_uuid')->index();
                $table->uuid('xml_cdr_uuid');
                $table->string('record_name', 255);
                $table->text('url');
                $table->string('status', 20)->default('pending');
                $table->integer('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamps();

                // One delivery per recording file per domain — also the atomic
                // claim that stops both cluster nodes dispatching the same webhook
                $table->unique(['domain_uuid', 'record_name']);
            });
        }

        $this->seedDefaultSettings();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recording_webhook_deliveries');

        DefaultSettings::where('default_setting_category', 'recording_webhook')->delete();
        DefaultSettings::where('default_setting_category', 'scheduled_jobs')
            ->where('default_setting_subcategory', 'recording_webhooks')
            ->delete();
    }

    private function seedDefaultSettings(): void
    {
        $settings = [
            [
                'default_setting_category'      => 'scheduled_jobs',
                'default_setting_subcategory'   => 'recording_webhooks',
                'default_setting_name'          => 'boolean',
                'default_setting_value'         => 'true',
                'default_setting_enabled'       => true,
                'default_setting_description'   => 'Dispatch call recording webhooks for enabled domains every minute.',
            ],
            [
                'default_setting_category'      => 'recording_webhook',
                'default_setting_subcategory'   => 'enabled',
                'default_setting_name'          => 'boolean',
                'default_setting_value'         => 'false',
                'default_setting_enabled'       => true,
                'default_setting_description'   => 'Send a webhook when a call recording becomes available. Enable per domain in Domain Settings.',
            ],
            [
                'default_setting_category'      => 'recording_webhook',
                'default_setting_subcategory'   => 'url',
                'default_setting_name'          => 'text',
                'default_setting_value'         => '',
                'default_setting_enabled'       => true,
                'default_setting_description'   => 'Destination URL that receives the recording webhook POST.',
            ],
            [
                'default_setting_category'      => 'recording_webhook',
                'default_setting_subcategory'   => 'secret',
                'default_setting_name'          => 'text',
                'default_setting_value'         => '',
                'default_setting_enabled'       => true,
                'default_setting_description'   => 'Shared secret used to HMAC-SHA256 sign the webhook payload (X-Voxra-Signature).',
            ],
            [
                'default_setting_category'      => 'recording_webhook',
                'default_setting_subcategory'   => 'url_ttl',
                'default_setting_name'          => 'numeric',
                'default_setting_value'         => '3600',
                'default_setting_enabled'       => true,
                'default_setting_description'   => 'How long (seconds) the recording links in the payload stay valid.',
            ],
            [
                'default_setting_category'      => 'recording_webhook',
                'default_setting_subcategory'   => 'directions',
                'default_setting_name'          => 'text',
                'default_setting_value'         => 'inbound,outbound,local',
                'default_setting_enabled'       => true,
                'default_setting_description'   => 'Comma-separated call directions that trigger the webhook.',
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
