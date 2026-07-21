<?php

namespace App\Services\Rating;

use App\Models\CallRate;
use App\Models\CallTariff;
use Illuminate\Support\Carbon;

/**
 * Computes per-call cost from v_call_tariffs / v_call_rates (issue #8).
 *
 * Tariff selection: an enabled tariff bound to the call's domain wins;
 * otherwise the enabled global tariff (domain_uuid null) applies. Rate
 * selection is longest-prefix match on the dialled number among rates
 * whose effective window contains the call start time.
 */
class CallRatingService
{
    /**
     * Rate a CDR row. Returns null when the call is unratable (no tariff,
     * no matching prefix, or no destination); otherwise:
     * ['cost' => float, 'currency' => string, 'rate_uuid' => string]
     *
     * @param object $cdr any object exposing domain_uuid, destination_number,
     *                    start_epoch, answer_epoch, billsec
     */
    public function rate(object $cdr): ?array
    {
        $destination = $this->normalizeNumber((string) ($cdr->destination_number ?? ''));
        if ($destination === '') {
            return null;
        }

        $tariff = $this->tariffForDomain((string) $cdr->domain_uuid);
        if (! $tariff) {
            return null;
        }

        $startedAt = ! empty($cdr->start_epoch)
            ? Carbon::createFromTimestampUTC((int) $cdr->start_epoch)
            : Carbon::now('UTC');

        $rate = $this->matchRate($tariff->tariff_uuid, $destination, $startedAt);
        if (! $rate) {
            return null;
        }

        return [
            'cost' => $this->computeCost($rate, $cdr),
            'currency' => $tariff->currency,
            'rate_uuid' => (string) $rate->rate_uuid,
        ];
    }

    public function tariffForDomain(string $domainUuid): ?CallTariff
    {
        return CallTariff::query()
            ->where('enabled', true)
            ->where(function ($q) use ($domainUuid) {
                $q->where('domain_uuid', $domainUuid)->orWhereNull('domain_uuid');
            })
            ->orderByRaw('domain_uuid IS NULL')   // domain-specific first
            ->first();
    }

    public function matchRate(string $tariffUuid, string $destination, Carbon $startedAt): ?CallRate
    {
        $prefixes = [];
        $max = min(strlen($destination), 18);
        for ($i = 1; $i <= $max; $i++) {
            $prefixes[] = substr($destination, 0, $i);
        }

        return CallRate::query()
            ->where('tariff_uuid', $tariffUuid)
            ->whereIn('destination_prefix', $prefixes)
            ->where(function ($q) use ($startedAt) {
                $q->whereNull('effective_from')->orWhere('effective_from', '<=', $startedAt);
            })
            ->where(function ($q) use ($startedAt) {
                $q->whereNull('effective_to')->orWhere('effective_to', '>', $startedAt);
            })
            ->orderByRaw('length(destination_prefix) DESC')
            ->first();
    }

    /**
     * Unanswered calls cost zero. Answered calls pay the setup fee plus the
     * per-minute rate over billsec, raised to min_duration_sec and rounded
     * up to the billing increment.
     */
    public function computeCost(CallRate $rate, object $cdr): float
    {
        $billsec = (int) ($cdr->billsec ?? 0);
        $answered = ! empty($cdr->answer_epoch) && $billsec > 0;
        if (! $answered) {
            return 0.0;
        }

        $billable = max($billsec, $rate->min_duration_sec);
        $increment = max(1, $rate->billing_increment_sec);
        $billable = (int) (ceil($billable / $increment) * $increment);

        $cost = (float) $rate->setup_fee + ((float) $rate->rate_per_minute * $billable / 60);

        return round($cost, 4);
    }

    /**
     * Strip dial-string noise so prefix matching sees digits in E.164-ish
     * form: "+443300577577" and "00443300577577" both become "443300577577".
     * National numbers are left as dialled (tariffs can carry national
     * prefixes alongside international ones).
     */
    public function normalizeNumber(string $number): string
    {
        $number = preg_replace('/[^0-9+]/', '', $number) ?? '';
        if (str_starts_with($number, '+')) {
            return substr($number, 1);
        }
        if (str_starts_with($number, '00')) {
            return substr($number, 2);
        }
        return $number;
    }
}
