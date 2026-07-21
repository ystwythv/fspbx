<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Per-domain CDR API webhooks (issue #9): subscription rows, a delivery
 * ledger for retry visibility, the api_webhook_manage permission and its
 * group grants, and the scheduler gate setting.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('v_api_webhooks')) {
            Schema::create('v_api_webhooks', function (Blueprint $table) {
                $table->uuid('webhook_uuid')->primary();
                $table->uuid('domain_uuid')->index();
                $table->string('url', 2048);
                $table->string('secret', 128);
                $table->jsonb('events')->default('["cdr.finalized"]');
                $table->boolean('enabled')->default(true);
                $table->string('description')->nullable();
                // delivery health surfaced in the API/UI so silently-flapping
                // endpoints are visible without tailing the queue worker
                $table->timestampTz('last_success_at')->nullable();
                $table->timestampTz('last_failure_at')->nullable();
                $table->integer('consecutive_failures')->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('v_api_webhook_deliveries')) {
            Schema::create('v_api_webhook_deliveries', function (Blueprint $table) {
                $table->uuid('delivery_uuid')->primary();
                $table->uuid('webhook_uuid')->index();
                $table->uuid('domain_uuid')->index();
                $table->string('event_type', 64);
                $table->uuid('resource_uuid');
                $table->string('status', 16)->default('pending');
                $table->integer('attempts')->default(0);
                $table->text('last_error')->nullable();
                $table->timestampTz('sent_at')->nullable();
                $table->timestamps();

                // doubles as the cluster-safe claim: insertOrIgnore means one
                // node wins the dispatch for a given webhook + event + CDR
                $table->unique(['webhook_uuid', 'event_type', 'resource_uuid']);
            });
        }

        $this->seedPermission();
        $this->seedDefaultSettings();
    }

    public function down(): void
    {
        Schema::dropIfExists('v_api_webhook_deliveries');
        Schema::dropIfExists('v_api_webhooks');

        DB::table('v_group_permissions')->where('permission_name', 'api_webhook_manage')->delete();
        DB::table('v_permissions')->where('permission_name', 'api_webhook_manage')->delete();
        DB::table('v_default_settings')
            ->where('default_setting_category', 'scheduled_jobs')
            ->where('default_setting_subcategory', 'cdr_webhooks')
            ->delete();
    }

    private function seedPermission(): void
    {
        $now = date('Y-m-d H:i:s');

        $exists = DB::table('v_permissions')
            ->where('permission_name', 'api_webhook_manage')
            ->exists();

        if (! $exists) {
            DB::table('v_permissions')->insert([
                'permission_uuid' => (string) Str::uuid(),
                'application_name' => 'CDR API',
                'permission_name' => 'api_webhook_manage',
                'insert_date' => $now,
            ]);
        }

        foreach (['superadmin', 'admin'] as $groupName) {
            $groups = DB::table('v_groups')->where('group_name', $groupName)->get(['group_uuid', 'group_name']);
            foreach ($groups as $group) {
                $granted = DB::table('v_group_permissions')
                    ->where('group_uuid', $group->group_uuid)
                    ->where('permission_name', 'api_webhook_manage')
                    ->exists();
                if ($granted) {
                    continue;
                }
                DB::table('v_group_permissions')->insert([
                    'group_permission_uuid' => (string) Str::uuid(),
                    'group_uuid' => $group->group_uuid,
                    'group_name' => $group->group_name,
                    'permission_name' => 'api_webhook_manage',
                    'permission_protected' => 'true',
                    'permission_assigned' => 'true',
                    'insert_date' => $now,
                ]);
            }
        }
    }

    private function seedDefaultSettings(): void
    {
        $exists = DB::table('v_default_settings')
            ->where('default_setting_category', 'scheduled_jobs')
            ->where('default_setting_subcategory', 'cdr_webhooks')
            ->exists();

        if (! $exists) {
            DB::table('v_default_settings')->insert([
                'default_setting_uuid' => (string) Str::uuid(),
                'default_setting_category' => 'scheduled_jobs',
                'default_setting_subcategory' => 'cdr_webhooks',
                'default_setting_name' => 'boolean',
                'default_setting_value' => 'true',
                'default_setting_enabled' => true,
                'default_setting_description' => 'Dispatch cdr.finalized webhooks for subscribed domains every minute.',
            ]);
        }
    }
};
