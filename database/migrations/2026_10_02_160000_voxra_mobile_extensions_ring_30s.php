<?php

use App\Models\FusionCache;
use App\Services\ProvisionCompleteService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Voxra eSIM mobile extensions ring the owner's handset for 30s before the
 * AI picks up (was 20s, voxragtm#194). ProvisionCompleteService sets this on
 * every re-provision; this brings the existing extensions over now and clears
 * each one's directory cache so FreeSWITCH reads the new call_timeout.
 * Idempotent: only rows still below 30s change.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rows = DB::table('v_extensions as e')
            ->join('v_domains as d', 'd.domain_uuid', '=', 'e.domain_uuid')
            ->where('e.description', ProvisionCompleteService::EXTENSION_DESCRIPTION)
            ->select('e.extension_uuid', 'e.extension', 'e.call_timeout', 'd.domain_name')
            ->get();

        foreach ($rows as $row) {
            if ((int) $row->call_timeout >= ProvisionCompleteService::CALL_TIMEOUT) {
                continue;
            }
            DB::table('v_extensions')
                ->where('extension_uuid', $row->extension_uuid)
                ->update(['call_timeout' => (string) ProvisionCompleteService::CALL_TIMEOUT]);
            try {
                FusionCache::clear('directory:' . $row->extension . '@' . $row->domain_name);
            } catch (\Throwable) {
                // Cache clear is best-effort; the next re-provision clears it too.
            }
        }
    }

    public function down(): void
    {
        // Not reverted: re-provisioning sets whatever CALL_TIMEOUT is then.
    }
};
