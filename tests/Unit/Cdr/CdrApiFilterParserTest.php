<?php

namespace Tests\Unit\Cdr;

use App\Enums\Cdr\CallDirection;
use App\Enums\Cdr\CallStatus;
use App\Exceptions\ApiException;
use App\Services\Cdr\CdrApiFilterParser;
use Illuminate\Http\Request;
use Tests\TestCase;

class CdrApiFilterParserTest extends TestCase
{
    private CdrApiFilterParser $parser;

    protected function setUp(): void
    {
        $this->parser = new CdrApiFilterParser();
    }

    public function test_missing_dates_raises_parameter_missing(): void
    {
        $this->expectApi('parameter_missing', 'date_from', 422, function () {
            $this->parser->fromRequest($this->req([]));
        });
    }

    public function test_invalid_iso_dates_raise_invalid_request(): void
    {
        $this->expectApi('invalid_request', 'date_from', 422, function () {
            $this->parser->fromRequest($this->req([
                'date_from' => 'not-a-date',
                'date_to' => '2026-04-10T00:00:00Z',
            ]));
        });
    }

    public function test_inverted_range_raises(): void
    {
        $this->expectApi('invalid_request', 'date_to', 422, function () {
            $this->parser->fromRequest($this->req([
                'date_from' => '2026-04-10T00:00:00Z',
                'date_to' => '2026-04-01T00:00:00Z',
            ]));
        });
    }

    public function test_oversize_window_raises_window_too_large(): void
    {
        $this->expectApi('window_too_large', 'date_to', 422, function () {
            $this->parser->fromRequest($this->req([
                'date_from' => '2026-01-01T00:00:00Z',
                'date_to' => '2026-04-01T00:00:00Z', // ~90 days
            ]));
        });
    }

    public function test_oversize_age_raises_window_too_old(): void
    {
        $this->expectApi('window_too_old', 'date_from', 422, function () {
            $this->parser->fromRequest($this->req([
                // 2 years ago — blown past the 90-day retention cap
                'date_from' => gmdate('Y-m-d\TH:i:s\Z', time() - (365 * 2 * 86400)),
                'date_to' => gmdate('Y-m-d\TH:i:s\Z', time() - (365 * 2 * 86400) + 86400),
            ]));
        });
    }

    public function test_valid_window_and_filters_parse(): void
    {
        $fromIso = gmdate('Y-m-d\TH:i:s\Z', time() - 86400 * 7);
        $toIso = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);

        $filters = $this->parser->fromRequest($this->req([
            'date_from' => $fromIso,
            'date_to' => $toIso,
            'direction' => 'outbound',
            'status' => 'answered',
            'min_mos' => '3.5',
            'has_recording' => 'true',
            'caller_number' => '+44123',
        ]));

        $this->assertSame(CallDirection::Outbound, $filters->direction);
        $this->assertSame(CallStatus::Answered, $filters->status);
        $this->assertSame(3.5, $filters->minMos);
        $this->assertSame(true, $filters->hasRecording);
        $this->assertSame('+44123', $filters->callerNumber);
    }

    public function test_unknown_direction_raises(): void
    {
        $fromIso = gmdate('Y-m-d\TH:i:s\Z', time() - 86400 * 2);
        $toIso = gmdate('Y-m-d\TH:i:s\Z', time() - 86400);

        $this->expectApi('invalid_request', 'direction', 422, function () use ($fromIso, $toIso) {
            $this->parser->fromRequest($this->req([
                'date_from' => $fromIso,
                'date_to' => $toIso,
                'direction' => 'diagonal',
            ]));
        });
    }

    private function req(array $query): Request
    {
        return Request::create('/test', 'GET', $query);
    }

    private function expectApi(string $code, ?string $param, int $status, callable $fn): void
    {
        try {
            $fn();
            $this->fail("Expected ApiException with code={$code} to be thrown.");
        } catch (ApiException $e) {
            $this->assertSame($status, $e->status);
            $this->assertSame($code, $e->error_code);
            if ($param !== null) {
                $this->assertSame($param, $e->param);
            }
        }
    }
}
