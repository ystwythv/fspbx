<?php

namespace App\Jobs;

use App\Models\Extensions;
use App\Services\ApnsPushService;
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

    public function handle(ApnsPushService $apns): void
    {
        $extensionUuid = $this->data['extension_uuid'] ?? null;
        $callerIdName = $this->data['caller_id_name'] ?? 'Unknown';
        $callerIdNumber = $this->data['caller_id_number'] ?? '';
        $callUuid = $this->data['call_uuid'] ?? '';

        if (!$extensionUuid) {
            Log::warning('[IncomingCallPush] Missing extension_uuid', $this->data);
            return;
        }

        $extension = Extensions::where('extension_uuid', $extensionUuid)->first();
        if (!$extension || !$extension->apns_voip_token) {
            Log::info('[IncomingCallPush] No push token for extension', [
                'extension_uuid' => $extensionUuid,
            ]);
            return;
        }

        $success = $apns->sendIncomingCallPush(
            $extension->apns_voip_token,
            $callerIdName,
            $callerIdNumber,
            $callUuid,
        );

        if (!$success) {
            Log::warning('[IncomingCallPush] Failed to send push', [
                'extension_uuid' => $extensionUuid,
            ]);
        }
    }
}
