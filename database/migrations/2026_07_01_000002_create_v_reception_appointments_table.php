<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Appointments the reception agent books in-call (voxragtm#29). This is Voxra's
 * own booking store; pushing to an external calendar (Google/Outlook, vertical
 * tools) is a later integration (voxragtm#66) that reads from here.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v_reception_appointments')) {
            Schema::create('v_reception_appointments', function (Blueprint $table) {
                $table->uuid('reception_appointment_uuid')->primary()->default(DB::raw('uuid_generate_v4()'));
                $table->uuid('domain_uuid');
                $table->uuid('reception_lead_uuid')->nullable();
                $table->string('conversation_id', 255)->nullable();
                $table->string('customer_name', 255)->nullable();
                $table->string('customer_number', 64)->nullable();
                $table->string('service', 255)->nullable();
                $table->timestamp('starts_at');
                $table->timestamp('ends_at')->nullable();
                $table->decimal('deposit_amount', 10, 2)->nullable();
                $table->string('status', 20)->default('booked'); // booked|cancelled|completed|no_show
                $table->text('notes')->nullable();
                $table->timestamp('insert_date')->nullable();
                $table->uuid('insert_user')->nullable();
                $table->timestamp('update_date')->nullable();
                $table->uuid('update_user')->nullable();

                $table->index('domain_uuid');
                $table->index(['domain_uuid', 'starts_at']);
                $table->index('reception_lead_uuid');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v_reception_appointments');
    }
};
