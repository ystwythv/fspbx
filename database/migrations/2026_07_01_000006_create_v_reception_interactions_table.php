<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Episodic memory (voxragtm#91): a durable timeline of interactions per tenant,
 * linked to the contact — the summaries behind a customer's history.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v_reception_interactions')) {
            Schema::create('v_reception_interactions', function (Blueprint $table) {
                $table->uuid('reception_interaction_uuid')->primary()->default(DB::raw('uuid_generate_v4()'));
                $table->uuid('domain_uuid');
                $table->uuid('reception_contact_uuid')->nullable();
                $table->string('conversation_id', 255)->nullable();
                $table->string('channel', 20)->default('voice'); // voice|whatsapp|web
                $table->text('summary');
                $table->string('outcome', 40)->nullable();        // booked|message|spam|transferred|...
                $table->timestamp('occurred_at')->nullable();
                $table->timestamp('insert_date')->nullable();
                $table->uuid('insert_user')->nullable();

                $table->index(['domain_uuid', 'reception_contact_uuid']);
                $table->index(['domain_uuid', 'occurred_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('v_reception_interactions');
    }
};
