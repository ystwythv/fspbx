<?php

namespace App\Console\Commands;

use App\Models\CDR;
use Illuminate\Support\Str;
use Illuminate\Console\Command;
use App\Jobs\SendRecordingWebhook;
use App\Models\RecordingWebhookDelivery;
use App\Services\RecordingWebhookConfigService;

class DispatchRecordingWebhooks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'webhooks:dispatch-recordings
        {--lookback-hours=24 : Only consider CDRs that started within this window}
        {--retry-failed : Re-dispatch deliveries that previously failed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Queue call recording webhooks for domains that have them enabled';

    public function handle(RecordingWebhookConfigService $configService)
    {
        $configs = $configService->getEnabledDomainConfigs();

        if (empty($configs)) {
            return Command::SUCCESS;
        }

        if ($this->option('retry-failed')) {
            $this->retryFailed(array_keys($configs));
        }

        $lookback = now()->subHours(max(1, (int) $this->option('lookback-hours')));
        $dispatched = 0;

        foreach ($configs as $domainUuid => $config) {
            $cdrs = CDR::query()
                ->where('domain_uuid', $domainUuid)
                ->whereNotNull('record_name')
                ->where('record_name', '!=', '')
                ->whereIn('direction', $config['directions'])
                ->where('start_stamp', '>=', $lookback)
                ->whereNotNull('end_stamp')
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('recording_webhook_deliveries')
                        ->whereColumn('recording_webhook_deliveries.domain_uuid', 'v_xml_cdr.domain_uuid')
                        ->whereColumn('recording_webhook_deliveries.record_name', 'v_xml_cdr.record_name');
                })
                ->orderBy('start_stamp')
                ->get([
                    'xml_cdr_uuid',
                    'domain_uuid',
                    'record_name',
                    'direction',
                    'billsec',
                    'start_stamp',
                ]);

            // Ring group / transfer legs share one recording file — send one
            // webhook per file, using the leg that carried the conversation
            $primaryLegs = $cdrs->groupBy('record_name')->map(function ($legs) {
                return $legs->sortByDesc('billsec')->first();
            });

            foreach ($primaryLegs as $cdr) {
                // insertOrIgnore + unique(domain_uuid, record_name) is the atomic
                // claim: whichever cluster node inserts the row sends the webhook
                $inserted = RecordingWebhookDelivery::insertOrIgnore([
                    'uuid' => (string) Str::uuid(),
                    'domain_uuid' => $cdr->domain_uuid,
                    'xml_cdr_uuid' => $cdr->xml_cdr_uuid,
                    'record_name' => $cdr->record_name,
                    'url' => $config['url'],
                    'status' => RecordingWebhookDelivery::STATUS_PENDING,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                if ($inserted === 1) {
                    $delivery = RecordingWebhookDelivery::where('domain_uuid', $cdr->domain_uuid)
                        ->where('record_name', $cdr->record_name)
                        ->first();

                    SendRecordingWebhook::dispatch($delivery->uuid);
                    $dispatched++;
                }
            }
        }

        if ($dispatched > 0) {
            $this->info("Dispatched {$dispatched} recording webhook(s)");
        }

        return Command::SUCCESS;
    }

    private function retryFailed(array $domainUuids): void
    {
        $failed = RecordingWebhookDelivery::whereIn('domain_uuid', $domainUuids)
            ->where('status', RecordingWebhookDelivery::STATUS_FAILED)
            ->get();

        foreach ($failed as $delivery) {
            $delivery->update([
                'status' => RecordingWebhookDelivery::STATUS_PENDING,
                'last_error' => null,
            ]);

            SendRecordingWebhook::dispatch($delivery->uuid);
            $this->info("Retrying failed delivery {$delivery->uuid}");
        }
    }
}
