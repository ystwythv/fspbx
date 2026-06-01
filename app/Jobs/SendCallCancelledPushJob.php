<?php

namespace App\Jobs;

use App\Models\Domain;
use App\Models\Extensions;
use App\Services\ApnsPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Send a "call cancelled" background push to the iOS apps that were woken by
 * an incoming-call VoIP push but did not win the call (a sibling ring-group
 * member or the SIM answered, or the caller hung up). Without this the losing
 * apps ring as a phantom until their own client-side watchdog fires, because
 * — having registered after the bridge was built — they never receive an
 * INVITE and so never receive a SIP CANCEL.
 *
 * Fired by FreeSWITCH's cancel_push.lua (call_cancelled webhook) from the
 * inbound leg's api_on_answer / api_hangup_hook. The push carries the original
 * call_uuid; the app dismisses CallKit only if that call is still
 * ringing-from-push, so notifying every pushed member is safe.
 */
class SendCallCancelledPushJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 2;
    public $timeout = 20;
    public $backoff = [3];

    public function __construct(
        private array $data,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(ApnsPushService $apns): void
    {
        $callUuid = $this->data['call_uuid'] ?? '';
        $domainName = $this->data['domain_name'] ?? null;
        $extensions = $this->data['extensions'] ?? [];

        if ($callUuid === '' || !$domainName || !is_array($extensions) || empty($extensions)) {
            Log::warning('[CallCancelledPush] Invalid payload', $this->data);
            return;
        }

        $domain = Domain::where('domain_name', $domainName)->first();
        if (!$domain) {
            Log::warning('[CallCancelledPush] Domain not found', ['domain_name' => $domainName]);
            return;
        }

        foreach ($extensions as $extNumber) {
            $extension = Extensions::where('domain_uuid', $domain->domain_uuid)
                ->where('extension', (string) $extNumber)
                ->first();
            if (!$extension) {
                continue;
            }

            // Cancel travels on the alert (background) channel only. A VoIP
            // token can't carry it (wrong APNs topic) and a VoIP cancel would
            // re-trigger CallKit's "must report a call" rule — so if no alert
            // token is registered we skip and let the app's watchdog clean up.
            $deviceToken = $extension->apns_alert_token;
            if (!$deviceToken) {
                Log::info('[CallCancelledPush] No alert token; skipping (client watchdog will clear)', [
                    'extension' => $extNumber,
                ]);
                continue;
            }

            $apns->sendCallCancelledPush($deviceToken, $callUuid);
        }
    }
}
