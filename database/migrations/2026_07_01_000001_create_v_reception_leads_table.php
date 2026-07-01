<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Leads captured by the reception agent while qualifying a caller (voxragtm#28):
 * name, postcode, job and urgency, scoped to the tenant domain and tied to the
 * conversation. Repeat callers are recognised by (domain_uuid, caller_number).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v_reception_leads')) {
            Schema::create('v_reception_leads', function (Blueprint $table) {
                $table->uuid('reception_lead_uuid')->primary()->default(DB::raw('uuid_generate_v4()'));
                $table->uuid('domain_uuid');
                $table->string('conversation_id', 255)->nullable();
                $table->string('caller_number', 64)->nullable();
                $table->string('name', 255)->nullable();
                $table->string('postcode', 16)->nullable();
                $table->text('job_description')->nullable();
                $table->string('urgency', 20)->nullable(); // emergency|urgent|routine
                $table->text('notes')->nullable();
                $table->string('status', 20)->default('new'); // new|qualified|booked|spam
                $table->timestamp('insert_date')->nullable();
                $table->uuid('insert_user')->nullable();
                $table->timestamp('update_date')->nullable();
                $table->uuid('update_user')->nullable();

                $table->index('domain_uuid');
                $table->index(['domain_uuid', 'caller_number']);
                $table->index(['domain_uuid', 'conversation_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v_reception_leads');
    }
};
