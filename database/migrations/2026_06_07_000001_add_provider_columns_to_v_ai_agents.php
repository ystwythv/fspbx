<?php

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
        Schema::table('v_ai_agents', function (Blueprint $table) {
            if (!Schema::hasColumn('v_ai_agents', 'provider')) {
                $table->string('provider', 20)->default('elevenlabs');
            }
            if (!Schema::hasColumn('v_ai_agents', 'model')) {
                $table->string('model', 100)->nullable();
            }
            if (!Schema::hasColumn('v_ai_agents', 'telnyx_assistant_id')) {
                $table->string('telnyx_assistant_id', 255)->nullable();
            }
            if (!Schema::hasColumn('v_ai_agents', 'telnyx_uac_connection_id')) {
                $table->string('telnyx_uac_connection_id', 255)->nullable();
            }
            if (!Schema::hasColumn('v_ai_agents', 'telnyx_attach_extension_uuid')) {
                $table->uuid('telnyx_attach_extension_uuid')->nullable();
            }
            if (!Schema::hasColumn('v_ai_agents', 'telnyx_attach_extension')) {
                $table->string('telnyx_attach_extension', 20)->nullable();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v_ai_agents', function (Blueprint $table) {
            $table->dropColumn([
                'provider',
                'model',
                'telnyx_assistant_id',
                'telnyx_uac_connection_id',
                'telnyx_attach_extension_uuid',
                'telnyx_attach_extension',
            ]);
        });
    }
};
