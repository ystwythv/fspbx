<?php

namespace App\Services;

use RuntimeException;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;

/**
 * Thin client for the Telnyx phone-number provisioning APIs — searching the
 * inventory of available UK numbers and placing number orders.
 *
 * This is the "get a number" half of Voxra provisioning (voxragtm#19). It is
 * deliberately separate from {@see \App\Services\PhoneNumberService}, which
 * builds the in-PBX routing/destination config once a number exists. Wiring an
 * ordered number into a tenant's dialplan/destinations is a follow-up
 * (voxragtm#23) and must go through CallRoutingOptionsService per repo
 * conventions.
 *
 * Mirrors {@see \App\Services\TelnyxConvaiService}: same config keys, bearer
 * auth, JSON headers and retry policy.
 */
class TelnyxNumberService
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey  = (string) config('services.telnyx.api_key', '');
        $this->baseUrl = rtrim((string) config('services.telnyx.base_url', 'https://api.telnyx.com'), '/');
        $this->timeout = (int) config('services.telnyx.timeout', 60);

        if ($this->apiKey === '') {
            throw new RuntimeException('Telnyx API key is not configured. Please set TELNYX_API_KEY in your environment file.');
        }
    }

    /**
     * Search the available-number inventory.
     * GET /v2/available_phone_numbers
     *
     * @param  array{country?:string,type?:string,area_code?:string,features?:array<int,string>,limit?:int}  $opts
     * @return array<int,array{phone_number:string,region:?string,locality:?string,upfront_cost:?string,monthly_cost:?string}>
     */
    public function searchAvailable(array $opts = []): array
    {
        $filter = array_filter([
            'country_code'             => strtoupper((string) ($opts['country'] ?? 'GB')),
            'phone_number_type'        => (string) ($opts['type'] ?? 'local'),
            'national_destination_code' => $opts['area_code'] ?? null,
            'features'                 => $opts['features'] ?? ['voice', 'sms'],
            'limit'                    => (int) ($opts['limit'] ?? 10),
        ], fn ($v) => $v !== null && $v !== '');

        $response = $this->http()->get('v2/available_phone_numbers', ['filter' => $filter]);

        if (!$response->successful()) {
            logger('Telnyx available-number search error: ' . $response->body());
            throw new RuntimeException('Failed to search Telnyx numbers: ' . $this->errorDetail($response));
        }

        return collect($response->json('data') ?? [])
            ->map(fn (array $n) => [
                'phone_number' => $n['phone_number'] ?? '',
                'region'       => $n['region_information'][0]['region_name'] ?? null,
                'locality'     => $n['region_information'][1]['region_name'] ?? null,
                'upfront_cost' => $n['cost_information']['upfront_cost'] ?? null,
                'monthly_cost' => $n['cost_information']['monthly_cost'] ?? null,
            ])
            ->filter(fn (array $n) => $n['phone_number'] !== '')
            ->values()
            ->all();
    }

    /**
     * Place an order for one or more numbers.
     * POST /v2/number_orders
     *
     * @param  array<int,string>  $phoneNumbers  E.164 numbers, e.g. ['+442071234567']
     * @return array{id:?string,status:?string,phone_numbers:array<int,mixed>}
     */
    public function createOrder(array $phoneNumbers, ?string $connectionId = null, ?string $messagingProfileId = null, ?string $requirementGroupId = null): array
    {
        if ($phoneNumbers === []) {
            throw new RuntimeException('createOrder requires at least one phone number.');
        }

        $body = array_filter([
            'phone_numbers'        => array_map(fn (string $n) => array_filter([
                'phone_number'         => $n,
                'requirement_group_id' => $requirementGroupId,
            ], fn ($v) => $v !== null), array_values($phoneNumbers)),
            'connection_id'        => $connectionId,
            'messaging_profile_id' => $messagingProfileId,
        ], fn ($v) => $v !== null);

        $response = $this->http()->post('v2/number_orders', $body);

        if (!$response->successful()) {
            logger('Telnyx number-order error: ' . $response->body());
            throw new RuntimeException('Failed to create Telnyx number order: ' . $this->errorDetail($response));
        }

        $data = $response->json('data') ?? [];

        return [
            'id'            => $data['id'] ?? null,
            'status'        => $data['status'] ?? null,
            'phone_numbers' => $data['phone_numbers'] ?? [],
        ];
    }

    /**
     * Fetch the current state of a number order (orders settle asynchronously).
     * GET /v2/number_orders/{id}
     */
    public function getOrder(string $orderId): array
    {
        $response = $this->http()->get("v2/number_orders/{$orderId}");

        if (!$response->successful()) {
            throw new RuntimeException('Failed to fetch Telnyx number order: ' . $this->errorDetail($response));
        }

        return $response->json('data') ?? [];
    }

    private function errorDetail(\Illuminate\Http\Client\Response $response): string
    {
        $errors = $response->json('errors');
        if (is_array($errors) && !empty($errors)) {
            return collect($errors)
                ->map(fn ($e) => trim(($e['title'] ?? '') . ': ' . ($e['detail'] ?? '')))
                ->implode('; ');
        }

        return $response->body();
    }

    private function http(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl($this->baseUrl . '/')
            ->timeout($this->timeout)
            ->withToken($this->apiKey)
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ])
            ->retry(
                3,
                500,
                function ($exception) {
                    if ($exception instanceof ConnectionException) {
                        return true;
                    }
                    $response = method_exists($exception, 'response') ? $exception->response() : null;
                    $status = $response?->status();
                    return in_array($status, [429, 500, 502, 503, 504], true);
                },
                throw: false
            );
    }
}
