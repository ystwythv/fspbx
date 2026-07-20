<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rating engine schema (issue #8): versioned per-prefix tariffs and the
 * cost columns on v_xml_cdr that the CDR API already exposes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('v_call_tariffs')) {
            Schema::create('v_call_tariffs', function (Blueprint $table) {
                $table->uuid('tariff_uuid')->primary();
                // null domain_uuid = global default tariff
                $table->uuid('domain_uuid')->nullable()->index();
                $table->string('tariff_name');
                $table->char('currency', 3)->default('GBP');
                $table->string('description')->nullable();
                $table->boolean('enabled')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('v_call_rates')) {
            Schema::create('v_call_rates', function (Blueprint $table) {
                $table->uuid('rate_uuid')->primary();
                $table->uuid('tariff_uuid')->index();
                $table->string('destination_prefix', 24);
                $table->decimal('rate_per_minute', 12, 6);
                $table->decimal('setup_fee', 12, 6)->default(0);
                $table->integer('min_duration_sec')->default(0);
                $table->integer('billing_increment_sec')->default(1);
                // versioning: a rate applies to calls started inside its
                // effective window; superseding a rate means closing the old
                // window and inserting a new row, so historical CDRs re-rate
                // identically
                $table->timestampTz('effective_from')->nullable();
                $table->timestampTz('effective_to')->nullable();
                $table->timestamps();

                $table->index(['tariff_uuid', 'destination_prefix']);
                $table->foreign('tariff_uuid')
                    ->references('tariff_uuid')->on('v_call_tariffs')
                    ->cascadeOnDelete();
            });
        }

        Schema::table('v_xml_cdr', function (Blueprint $table) {
            if (! Schema::hasColumn('v_xml_cdr', 'call_cost')) {
                $table->decimal('call_cost', 10, 4)->nullable();
            }
            if (! Schema::hasColumn('v_xml_cdr', 'call_cost_currency')) {
                $table->char('call_cost_currency', 3)->nullable();
            }
            if (! Schema::hasColumn('v_xml_cdr', 'call_cost_rate_uuid')) {
                $table->uuid('call_cost_rate_uuid')->nullable();
            }
        });

        $this->seedDefaultSettings();
    }

    public function down(): void
    {
        Schema::table('v_xml_cdr', function (Blueprint $table) {
            foreach (['call_cost', 'call_cost_currency', 'call_cost_rate_uuid'] as $column) {
                if (Schema::hasColumn('v_xml_cdr', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('v_call_rates');
        Schema::dropIfExists('v_call_tariffs');

        DB::table('default_settings')
            ->where('default_setting_category', 'scheduled_jobs')
            ->where('default_setting_subcategory', 'cdr_rating')
            ->delete();
    }

    private function seedDefaultSettings(): void
    {
        $exists = DB::table('default_settings')
            ->where('default_setting_category', 'scheduled_jobs')
            ->where('default_setting_subcategory', 'cdr_rating')
            ->exists();

        if (! $exists) {
            DB::table('default_settings')->insert([
                'default_setting_uuid' => (string) \Illuminate\Support\Str::uuid(),
                'default_setting_category' => 'scheduled_jobs',
                'default_setting_subcategory' => 'cdr_rating',
                'default_setting_name' => 'boolean',
                'default_setting_value' => 'false',
                'default_setting_enabled' => true,
                'default_setting_description' => 'Rate recent outbound CDRs against v_call_tariffs every five minutes',
            ]);
        }
    }
};
