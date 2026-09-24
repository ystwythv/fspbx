<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Services\TelnyxConvaiService;
use App\Services\Voxra\VoxraMediaPurgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * voxraweb → delete a tenant's call audio now (voxragtm#83), HMAC-signed like
 * provision-tenant:
 *  - scope=caller: one caller's PBX recordings + voicemails and Telnyx
 *    recordings/conversations (a data-subject erasure request);
 *  - scope=all: every item for the tenant (account erasure).
 * Age-based retention runs separately (voxra:purge-media, daily).
 */
class VoxraPurgeMediaController extends Controller
{
    public function purge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'tenant_id'          => 'required|string|max:64',
            'scope'              => 'required|in:caller,all',
            'caller_numbers'     => 'array|max:20',
            'caller_numbers.*'   => 'string|max:32',
            'conversation_ids'   => 'array|max:200',
            'conversation_ids.*' => 'string|max:128',
            'dry_run'            => 'nullable|boolean',
        ]);

        $telnyx = null;
        try {
            $telnyx = app(TelnyxConvaiService::class);
        } catch (\Throwable $e) {
            logger()->warning('voxra purge-media: Telnyx unavailable: ' . $e->getMessage());
        }

        $result = (new VoxraMediaPurgeService($telnyx))->purge([
            'scope' => $data['scope'],
            'tenant_id' => $data['tenant_id'],
            'caller_numbers' => $data['caller_numbers'] ?? [],
            'dry_run' => $request->boolean('dry_run', false),
            'limit' => 1000,
            'telnyx' => $telnyx !== null,
        ]);

        return response()->json([
            'ok' => ($result['counts']['errors'] ?? 0) === 0,
            'counts' => $result['counts'],
            'telnyx' => $telnyx !== null,
        ]);
    }
}
