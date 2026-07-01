<?php

namespace Tests\Feature;

use App\Services\TelnyxNumberService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class TelnyxNumberServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.telnyx.api_key', 'KEY_test');
        config()->set('services.telnyx.base_url', 'https://api.telnyx.com');
    }

    public function test_it_requires_an_api_key(): void
    {
        config()->set('services.telnyx.api_key', '');
        $this->expectException(RuntimeException::class);
        new TelnyxNumberService();
    }

    public function test_search_maps_available_numbers_and_sends_bearer_token(): void
    {
        Http::fake([
            'api.telnyx.com/v2/available_phone_numbers*' => Http::response([
                'data' => [
                    [
                        'phone_number' => '+442071234567',
                        'region_information' => [
                            ['region_name' => 'GB'],
                            ['region_name' => 'London'],
                        ],
                        'cost_information' => ['upfront_cost' => '1.00', 'monthly_cost' => '1.00'],
                    ],
                ],
            ], 200),
        ]);

        $results = (new TelnyxNumberService())->searchAvailable([
            'country' => 'GB',
            'type' => 'local',
            'area_code' => '20',
            'limit' => 5,
        ]);

        $this->assertCount(1, $results);
        $this->assertSame('+442071234567', $results[0]['phone_number']);
        $this->assertSame('London', $results[0]['locality']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/available_phone_numbers')
                && $request->hasHeader('Authorization', 'Bearer KEY_test');
        });
    }

    public function test_create_order_posts_numbers_and_returns_status(): void
    {
        Http::fake([
            'api.telnyx.com/v2/number_orders' => Http::response([
                'data' => [
                    'id' => 'ord_123',
                    'status' => 'pending',
                    'phone_numbers' => [['phone_number' => '+442071234567']],
                ],
            ], 200),
        ]);

        $order = (new TelnyxNumberService())->createOrder(
            ['+442071234567'],
            'conn_1',
            'msg_1',
        );

        $this->assertSame('ord_123', $order['id']);
        $this->assertSame('pending', $order['status']);

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/v2/number_orders')
                && $request['connection_id'] === 'conn_1'
                && $request['messaging_profile_id'] === 'msg_1'
                && $request['phone_numbers'][0]['phone_number'] === '+442071234567';
        });
    }

    public function test_search_throws_on_api_error(): void
    {
        Http::fake([
            'api.telnyx.com/v2/available_phone_numbers*' => Http::response([
                'errors' => [['title' => 'Bad request', 'detail' => 'invalid filter']],
            ], 422),
        ]);

        $this->expectException(RuntimeException::class);
        (new TelnyxNumberService())->searchAvailable(['area_code' => 'xx']);
    }
}
