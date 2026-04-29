<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v_ai_agents', function (Blueprint $table) {
            if (!Schema::hasColumn('v_ai_agents', 'mode')) {
                $table->string('mode', 16)->default('direct');
            }
            if (!Schema::hasColumn('v_ai_agents', 'feature_code')) {
                $table->string('feature_code', 8)->nullable();
            }
        });

        if (!Schema::hasColumn('v_ai_agents', 'tools_enabled')) {
            DB::statement("ALTER TABLE v_ai_agents ADD COLUMN tools_enabled jsonb NOT NULL DEFAULT '{}'::jsonb");
        }
    }

    public function down(): void
    {
        Schema::table('v_ai_agents', function (Blueprint $table) {
            if (Schema::hasColumn('v_ai_agents', 'mode')) {
                $table->dropColumn('mode');
            }
            if (Schema::hasColumn('v_ai_agents', 'feature_code')) {
                $table->dropColumn('feature_code');
            }
            if (Schema::hasColumn('v_ai_agents', 'tools_enabled')) {
                $table->dropColumn('tools_enabled');
            }
        });
    }
};
