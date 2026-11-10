<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\TelnyxConvaiService;
use App\Services\Voxra\VoxraMediaPurgeService;
use App\Services\Voxra\VoxraTenantDeleteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * voxraweb → delete a closed customer's PBX domain once its numbers are
 * released (VoxraTenantDeleteService), HMAC-signed like provision-tenant.
 *   200 {ok: true, domains: [...], media: {...}}       deleted (or nothing there)
 *   409 {ok: false, error: "numbers_remain", numbers}  a number still routes here
 *   502 {ok: false, error: "media_purge_failed"}       media not cleared; retry
 */
class VoxraDeleteTenantController extends Controller
{
    public function delete(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id' => 'required|uuid',
            'dry_run' => 'nullable|boolean',
        ]);

        $telnyx = null;
        try {
            $telnyx = app(TelnyxConvaiService::class);
        } catch (\Throwable $e) {
            logger()->warning('voxra delete-tenant: Telnyx unavailable: ' . $e->getMessage());
        }
        if ($telnyx === null) {
            // Without Telnyx the media purge can't reach the AI recordings,
            // and the domain is how it finds them: don't delete yet.
            return response()->json(['ok' => false, 'error' => 'telnyx_unavailable'], 503);
        }

        $result = (new VoxraTenantDeleteService(new VoxraMediaPurgeService($telnyx)))
            ->delete($data['tenant_id'], $request->boolean('dry_run', false));
        $status = $result['status'];
        unset($result['status']);

        return response()->json($result, $status);
    }
}
