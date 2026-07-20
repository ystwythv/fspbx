<?php

namespace App\Console\Commands;

use App\Jobs\DeliverCdrWebhook;
use App\Models\ApiWebhook;
use App\Models\ApiWebhookDelivery;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Scans finalized CDRs and queues cdr.finalized webhook deliveries
 * (issue #9). Same scan-and-claim model as webhooks:dispatch-recordings:
 * the unique index on v_api_webhook_deliveries makes the claim atomic, so
 * both cluster nodes can run this every minute without double-sending.
 */
class DispatchCdrWebhooks extends Command
{
    protected $signature = 'webhooks:dispatch-cdr-events
        {--lookback-hours=24 : How far back to scan for unclaimed CDRs}
        {--retry-failed : Re-queue deliveries that exhausted their retries}';

    protected $description = 'Queue cdr.finalized webhooks for domains with API webhook subscriptions';

    public function handle(): int
    {
        if ($this->option('retry-failed')) {
            return $this->retryFailed();
        }

        $webhooks = ApiWebhook::query()->where('enabled', true)->get()
            ->filter(fn (ApiWebhook $w) => $w->subscribesTo(ApiWebhook::EVENT_CDR_FINALIZED));

        if ($webhooks->isEmpty()) {
            return self::SUCCESS;
        }

        $lookback = now()->subHours(max(1, (int) $this->option('lookback-hours')))->getTimestamp();
        $queued = 0;

        foreach ($webhooks as $webhook) {
            // finalized primary legs only — mirrors CdrQueryService::baseQuery
            // exclusions so webhook consumers see the same calls as the API
            $cdrs = DB::table('v_xml_cdr')
                ->select(['xml_cdr_uuid', 'domain_uuid'])
                ->where('domain_uuid', $webhook->domain_uuid)
                ->whereNotNull('end_epoch')
                ->where('end_epoch', '>=', $lookback)
                ->where(function ($q) {
                    $q->whereNull('hangup_cause')->orWhere('hangup_cause', '!=', 'LOSE_RACE');
                })
                ->whereNull('cc_member_session_uuid')
                ->whereNull('originating_leg_uuid')
                ->whereNotExists(function ($q) use ($webhook) {
                    $q->selectRaw('1')
                        ->from('v_api_webhook_deliveries')
                        ->whereColumn('v_api_webhook_deliveries.resource_uuid', 'v_xml_cdr.xml_cdr_uuid')
                        ->where('v_api_webhook_deliveries.webhook_uuid', $webhook->webhook_uuid)
                        ->where('v_api_webhook_deliveries.event_type', ApiWebhook::EVENT_CDR_FINALIZED);
                })
                ->orderBy('end_epoch')
                ->limit(1000)
                ->get();

            foreach ($cdrs as $cdr) {
                $deliveryUuid = (string) Str::uuid();

                $claimed = DB::table('v_api_webhook_deliveries')->insertOrIgnore([
                    'delivery_uuid' => $deliveryUuid,
                    'webhook_uuid' => $webhook->webhook_uuid,
                    'domain_uuid' => $cdr->domain_uuid,
                    'event_type' => ApiWebhook::EVENT_CDR_FINALIZED,
                    'resource_uuid' => $cdr->xml_cdr_uuid,
                    'status' => ApiWebhookDelivery::STATUS_PENDING,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($claimed) {
                    DeliverCdrWebhook::dispatch($deliveryUuid);
                    $queued++;
                }
            }
        }

        if ($queued > 0) {
            $this->info("Queued {$queued} cdr.finalized deliveries.");
        }

        return self::SUCCESS;
    }

    private function retryFailed(): int
    {
        $failed = ApiWebhookDelivery::query()
            ->where('status', ApiWebhookDelivery::STATUS_FAILED)
            ->limit(500)
            ->get();

        foreach ($failed as $delivery) {
            $delivery->update([
                'status' => ApiWebhookDelivery::STATUS_PENDING,
                'last_error' => null,
            ]);
            DeliverCdrWebhook::dispatch($delivery->delivery_uuid);
        }

        $this->info("Re-queued {$failed->count()} failed deliveries.");

        return self::SUCCESS;
    }
}
