<?php

namespace App\Console\Commands;

use App\Services\Rating\CallRatingService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Rates outbound CDRs against v_call_tariffs (issue #8). Runs from the
 * scheduler every five minutes (scheduled_jobs/cdr_rating setting) and
 * doubles as the historical backfill via --from/--to.
 */
class RateCdrCalls extends Command
{
    protected $signature = 'cdr:rate
        {--from= : Rate calls started at/after this time (ISO 8601)}
        {--to= : Rate calls started before this time (ISO 8601)}
        {--recent=2 : With no --from, look back this many hours}
        {--domain= : Limit to one domain_uuid}
        {--rerate : Re-rate calls that already have a cost}
        {--chunk=500 : Rows per chunk}';

    protected $description = 'Compute call_cost for outbound CDRs from the tariff tables';

    public function handle(CallRatingService $rating): int
    {
        try {
            $from = $this->option('from')
                ? Carbon::parse($this->option('from'))
                : Carbon::now('UTC')->subHours(max(1, (int) $this->option('recent')));
            $to = $this->option('to') ? Carbon::parse($this->option('to')) : Carbon::now('UTC');
        } catch (\Throwable $e) {
            $this->error('Could not parse --from/--to: ' . $e->getMessage());
            return self::FAILURE;
        }

        $query = DB::table('v_xml_cdr')
            ->select([
                'xml_cdr_uuid', 'domain_uuid', 'destination_number',
                'start_epoch', 'answer_epoch', 'billsec',
            ])
            ->where('direction', 'outbound')
            ->whereNotNull('end_epoch')
            ->whereBetween('start_epoch', [$from->getTimestamp(), $to->getTimestamp()]);

        if (! $this->option('rerate')) {
            $query->whereNull('call_cost');
        }
        if ($this->option('domain')) {
            $query->where('domain_uuid', $this->option('domain'));
        }

        $rated = 0;
        $skipped = 0;
        $chunk = max(50, (int) $this->option('chunk'));

        // keyset pagination: offset chunking would skip rows as call_cost
        // fills in and rows drop out of the whereNull filter
        $query->chunkById($chunk, function ($rows) use ($rating, &$rated, &$skipped) {
            foreach ($rows as $cdr) {
                $result = $rating->rate($cdr);
                if ($result === null) {
                    $skipped++;
                    continue;
                }

                DB::table('v_xml_cdr')
                    ->where('xml_cdr_uuid', $cdr->xml_cdr_uuid)
                    ->update([
                        'call_cost' => $result['cost'],
                        'call_cost_currency' => $result['currency'],
                        'call_cost_rate_uuid' => $result['rate_uuid'],
                    ]);
                $rated++;
            }
        }, 'xml_cdr_uuid');

        $this->info("Rated {$rated} calls, skipped {$skipped} (no tariff/prefix match) between {$from->toIso8601ZuluString()} and {$to->toIso8601ZuluString()}.");

        return self::SUCCESS;
    }
}
