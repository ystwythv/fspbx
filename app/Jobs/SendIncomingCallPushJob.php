<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\Extensions;
use App\Services\ApnsPushService;
use App\Services\CrmLookupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SendIncomingCallPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;
    public $timeout = 30;
    public $backoff = [5, 10];

    public function __construct(
        private array $data,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(ApnsPushService $apns, CrmLookupService $crm): void
    {
        $extensionUuid = $this->data['extension_uuid'] ?? null;
        $extensionNumber = $this->data['extension_number'] ?? null;
        $domainName = $this->data['domain_name'] ?? null;
        $callerIdName = $this->data['caller_id_name'] ?? 'Unknown';
        $callerIdNumber = $this->data['caller_id_number'] ?? '';
        $callUuid = $this->data['call_uuid'] ?? '';
        $didPrefix = $this->data['did_prefix'] ?? '';
        $didE164 = $this->data['did_e164'] ?? '';
        // ring_target may be passed in by the dialplan (push_wake.lua) once
        // directory.lua exposes it; fall back to the extension column.
        $ringTargetHint = $this->data['ring_target'] ?? null;

        $extension = null;
        if ($extensionUuid) {
            $extension = Extensions::where('extension_uuid', $extensionUuid)->first();
        }
        if (!$extension && $extensionNumber && $domainName) {
            $domain = Domain::where('domain_name', $domainName)->first();
            if ($domain) {
                $extension = Extensions::where('domain_uuid', $domain->domain_uuid)
                    ->where('extension', $extensionNumber)
                    ->first();
            }
        }
        if (!$extension) {
            Log::warning('[IncomingCallPush] Extension not found', $this->data);
            return;
        }
        if (!$extension->apns_voip_token) {
            Log::info('[IncomingCallPush] No push token for extension', [
                'extension_uuid' => $extension->extension_uuid,
            ]);
            return;
        }

        // Best-effort CRM enrichment. Returns null on miss / timeout — never
        // blocks the push beyond IQCRM_LOOKUP_TIMEOUT (default 1.5s).
        $enrichment = $callerIdNumber !== ''
            ? $crm->lookupByPhone($callerIdNumber, $domainName)
            : null;

        $ringTarget = in_array($ringTargetHint, ['app', 'fmc', 'both'], true)
            ? $ringTargetHint
            : (in_array($extension->ring_target, ['app', 'fmc', 'both'], true) ? $extension->ring_target : 'both');

        // Phase 1: always send VoIP push regardless of ring_target. Once the
        // iOS app gains a regular-APNs handler we'll switch to 'alert' when
        // ring_target = 'fmc' so the iPhone enriches caller-ID without ringing.
        $pushType = 'voip';

        $success = $apns->sendIncomingCallPush(
            $extension->apns_voip_token,
            $callerIdName,
            $callerIdNumber,
            $callUuid,
            $didPrefix,
            $didE164,
            $enrichment,
            $ringTarget,
            $pushType,
        );

        if (!$success) {
            Log::warning('[IncomingCallPush] Failed to send push', [
                'extension_uuid' => $extensionUuid,
            ]);
        }
    }
}
