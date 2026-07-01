<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Migrations\Migration;

/**
 * Reception/customer data moved to Supabase (voxragtm#88 data-location rule).
 * Drop the tables that briefly lived on the PBX; the agent's data tools now run
 * in the voxraweb BFF and write Supabase.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('v_reception_appointments');
        Schema::dropIfExists('v_reception_leads');
    }

    public function down(): void
    {
        // no-op: these tables are intentionally gone (data lives in Supabase).
    }
};
