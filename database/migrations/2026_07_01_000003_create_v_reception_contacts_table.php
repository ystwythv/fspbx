<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Per end-customer memory (voxragtm#89) — one row per (tenant, phone number),
 * aggregating a caller's history so the reception agent recognises and greets
 * returning customers. Tenant-scoped and therefore shared across the team.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v_reception_contacts')) {
            Schema::create('v_reception_contacts', function (Blueprint $table) {
                $table->uuid('reception_contact_uuid')->primary()->default(DB::raw('uuid_generate_v4()'));
                $table->uuid('domain_uuid');
                $table->string('phone_number', 64);
                $table->string('name', 255)->nullable();
                $table->timestamp('first_seen_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->integer('total_calls')->default(0);
                $table->integer('total_bookings')->default(0);
                $table->text('notes')->nullable();
                $table->jsonb('preferences')->nullable();
                $table->timestamp('insert_date')->nullable();
                $table->uuid('insert_user')->nullable();
                $table->timestamp('update_date')->nullable();
                $table->uuid('update_user')->nullable();

                $table->unique(['domain_uuid', 'phone_number']);
                $table->index('domain_uuid');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v_reception_contacts');
    }
};
