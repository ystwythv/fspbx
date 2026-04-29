<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v_ai_agents', function (Blueprint $table) {
            if (!Schema::hasColumn('v_ai_agents', 'bind_dialplan_uuid')) {
                $table->uuid('bind_dialplan_uuid')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('v_ai_agents', function (Blueprint $table) {
            if (Schema::hasColumn('v_ai_agents', 'bind_dialplan_uuid')) {
                $table->dropColumn('bind_dialplan_uuid');
            }
        });
    }
};
