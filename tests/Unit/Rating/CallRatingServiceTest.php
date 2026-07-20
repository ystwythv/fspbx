<?php

namespace Tests\Unit\Rating;

use App\Models\CallRate;
use App\Services\Rating\CallRatingService;
use PHPUnit\Framework\TestCase;

class CallRatingServiceTest extends TestCase
{
    private CallRatingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new CallRatingService();
    }

    private function rate(array $attributes = []): CallRate
    {
        $rate = new CallRate();
        $rate->forceFill(array_merge([
            'rate_per_minute' => '0.012000',
            'setup_fee' => '0.000000',
            'min_duration_sec' => 0,
            'billing_increment_sec' => 1,
        ], $attributes));

        return $rate;
    }

    private function cdr(array $attributes = []): object
    {
        return (object) array_merge([
            'answer_epoch' => 1750000000,
            'billsec' => 60,
        ], $attributes);
    }

    public function test_unanswered_call_costs_zero(): void
    {
        $cost = $this->service->computeCost($this->rate(['setup_fee' => '0.05']), $this->cdr([
            'answer_epoch' => null,
            'billsec' => 0,
        ]));

        $this->assertSame(0.0, $cost);
    }

    public function test_per_second_billing(): void
    {
        // 90s at 0.012/min = 0.018
        $cost = $this->service->computeCost($this->rate(), $this->cdr(['billsec' => 90]));

        $this->assertSame(0.018, $cost);
    }

    public function test_setup_fee_added_once(): void
    {
        $cost = $this->service->computeCost(
            $this->rate(['setup_fee' => '0.030000']),
            $this->cdr(['billsec' => 60])
        );

        $this->assertSame(0.042, $cost);
    }

    public function test_min_duration_raises_short_calls(): void
    {
        // 5s call billed as 30s minimum: 0.012 * 30/60 = 0.006
        $cost = $this->service->computeCost(
            $this->rate(['min_duration_sec' => 30]),
            $this->cdr(['billsec' => 5])
        );

        $this->assertSame(0.006, $cost);
    }

    public function test_billing_increment_rounds_up(): void
    {
        // 61s in 60s increments bills 120s: 0.012 * 2 = 0.024
        $cost = $this->service->computeCost(
            $this->rate(['billing_increment_sec' => 60]),
            $this->cdr(['billsec' => 61])
        );

        $this->assertSame(0.024, $cost);
    }

    public function test_normalize_number_strips_plus_and_idd(): void
    {
        $this->assertSame('443300577577', $this->service->normalizeNumber('+443300577577'));
        $this->assertSame('443300577577', $this->service->normalizeNumber('00443300577577'));
        $this->assertSame('01970623111', $this->service->normalizeNumber('01970 623111'));
        $this->assertSame('', $this->service->normalizeNumber('anonymous'));
    }
}
