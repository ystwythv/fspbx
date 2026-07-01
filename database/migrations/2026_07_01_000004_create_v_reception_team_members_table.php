<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

/**
 * Team-member identity (voxragtm#92): maps a phone/WhatsApp number to a tenant
 * member + role, so the agent knows who it's talking to (owner vs staff) for
 * personalisation, permissions and provenance on memory writes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('v_reception_team_members')) {
            Schema::create('v_reception_team_members', function (Blueprint $table) {
                $table->uuid('reception_team_member_uuid')->primary()->default(DB::raw('uuid_generate_v4()'));
                $table->uuid('domain_uuid');
                $table->string('phone_number', 64);
                $table->string('name', 255)->nullable();
                $table->string('role', 20)->default('member'); // owner|admin|member
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
        Schema::dropIfExists('v_reception_team_members');
    }
};
