<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Business memory (voxragtm#90): durable tenant facts/preferences the owner
 * tells Voxra over time ("we now charge £70 call-out"), shared across the team.
 * Every fact carries provenance (who said it) and a status so sensitive changes
 * (pricing/policy) by non-owners can wait for approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v_reception_memory')) {
            Schema::create('v_reception_memory', function (Blueprint $table) {
                $table->uuid('reception_memory_uuid')->primary()->default(DB::raw('uuid_generate_v4()'));
                $table->uuid('domain_uuid');
                $table->string('category', 40)->default('general'); // general|pricing|policy|scheduling|...
                $table->text('fact');
                $table->string('status', 20)->default('active');    // active|pending|archived
                $table->string('created_by_number', 64)->nullable();
                $table->string('created_by_name', 255)->nullable();
                $table->string('source', 20)->nullable();           // voice|whatsapp|web
                $table->timestamp('insert_date')->nullable();
                $table->uuid('insert_user')->nullable();
                $table->timestamp('update_date')->nullable();
                $table->uuid('update_user')->nullable();

                $table->index(['domain_uuid', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v_reception_memory');
    }
};
