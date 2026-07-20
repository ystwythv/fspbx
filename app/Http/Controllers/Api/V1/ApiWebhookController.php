<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ApiWebhook;
use App\Models\ApiWebhookDelivery;
use Illuminate\Http\Request;

/**
 * Tenant self-service management of CDR API webhooks (issue #9).
 * Routes are wrapped in cdr.scope:tenant + user.authorize:api_webhook_manage.
 */
class ApiWebhookController extends Controller
{
    /**
     * List webhooks for a domain, including delivery health.
     *
     * @group API Webhooks
     * @authenticated
     */
    public function index(Request $request, string $domain_uuid)
    {
        $this->assertUuid($domain_uuid, 'domain_uuid');

        $rows = ApiWebhook::query()
            ->where('domain_uuid', $domain_uuid)
            ->orderBy('created_at')
            ->get();

        return response()->json([
            'object' => 'list',
            'url' => '/api/v1/domains/' . $domain_uuid . '/webhooks',
            'has_more' => false,
            'data' => $rows->map(fn (ApiWebhook $w) => $this->summarize($w))->all(),
        ], 200);
    }

    /**
     * Create a webhook. The shared secret is returned once.
     *
     * Body: url (required, https), events (optional, defaults to
     * ["cdr.finalized"]), description (optional).
     *
     * @group API Webhooks
     * @authenticated
     */
    public function store(Request $request, string $domain_uuid)
    {
        $this->assertUuid($domain_uuid, 'domain_uuid');

        $url = trim((string) $request->input('url', ''));
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            throw new ApiException(422, 'invalid_request_error', 'A valid url is required.', 'parameter_missing', 'url');
        }
        if (! str_starts_with(strtolower($url), 'https://') && ! app()->isLocal()) {
            throw new ApiException(422, 'invalid_request_error', 'url must be https.', 'invalid_request', 'url');
        }

        $events = $this->normaliseEvents($request->input('events'));

        if (ApiWebhook::query()->where('domain_uuid', $domain_uuid)->count() >= 10) {
            throw new ApiException(422, 'invalid_request_error', 'Webhook limit reached for this domain (10).', 'invalid_request', 'url');
        }

        $secret = ApiWebhook::generateSecret();

        $webhook = ApiWebhook::create([
            'domain_uuid' => $domain_uuid,
            'url' => $url,
            'secret' => $secret,
            'events' => $events,
            'enabled' => true,
            'description' => trim((string) $request->input('description', '')) ?: null,
        ]);

        $summary = $this->summarize($webhook);
        // shown once; unrecoverable afterwards (rotate to get a new one)
        $summary['secret'] = $secret;

        return response()->json($summary, 201);
    }

    /**
     * Rotate a webhook's signing secret. The new secret is returned once.
     *
     * @group API Webhooks
     * @authenticated
     */
    public function rotateSecret(Request $request, string $domain_uuid, string $webhook_uuid)
    {
        $webhook = $this->findWebhook($domain_uuid, $webhook_uuid);

        $secret = ApiWebhook::generateSecret();
        $webhook->update(['secret' => $secret]);

        $summary = $this->summarize($webhook);
        $summary['secret'] = $secret;

        return response()->json($summary, 200);
    }

    /**
     * Delete a webhook.
     *
     * @group API Webhooks
     * @authenticated
     */
    public function destroy(Request $request, string $domain_uuid, string $webhook_uuid)
    {
        $webhook = $this->findWebhook($domain_uuid, $webhook_uuid);
        $webhook->delete();

        return response()->json([
            'object' => 'api_webhook',
            'id' => $webhook_uuid,
            'deleted' => true,
        ], 200);
    }

    /**
     * Recent delivery attempts for a webhook (newest first), so failing
     * endpoints are visible without queue access.
     *
     * @group API Webhooks
     * @authenticated
     */
    public function deliveries(Request $request, string $domain_uuid, string $webhook_uuid)
    {
        $webhook = $this->findWebhook($domain_uuid, $webhook_uuid);

        $limit = max(1, min(100, (int) $request->query('limit', 25)));

        $rows = ApiWebhookDelivery::query()
            ->where('webhook_uuid', $webhook->webhook_uuid)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'object' => 'list',
            'url' => '/api/v1/domains/' . $domain_uuid . '/webhooks/' . $webhook_uuid . '/deliveries',
            'has_more' => false,
            'data' => $rows->map(fn (ApiWebhookDelivery $d) => [
                'object' => 'webhook_delivery',
                'delivery_uuid' => (string) $d->delivery_uuid,
                'event_type' => $d->event_type,
                'resource_uuid' => (string) $d->resource_uuid,
                'status' => $d->status,
                'attempts' => $d->attempts,
                'last_error' => $d->last_error,
                'sent_at' => $d->sent_at?->toIso8601ZuluString(),
                'created_at' => $d->created_at?->toIso8601ZuluString(),
            ])->all(),
        ], 200);
    }

    private function findWebhook(string $domain_uuid, string $webhook_uuid): ApiWebhook
    {
        $this->assertUuid($domain_uuid, 'domain_uuid');
        $this->assertUuid($webhook_uuid, 'webhook_uuid');

        $webhook = ApiWebhook::query()
            ->where('domain_uuid', $domain_uuid)
            ->where('webhook_uuid', $webhook_uuid)
            ->first();

        if (! $webhook) {
            throw new ApiException(404, 'invalid_request_error', 'Webhook not found.', 'resource_missing', 'webhook_uuid');
        }

        return $webhook;
    }

    private function normaliseEvents($events): array
    {
        if ($events === null || $events === '' || $events === []) {
            return [ApiWebhook::EVENT_CDR_FINALIZED];
        }
        if (is_string($events)) {
            $events = preg_split('/\s*,\s*/', $events, -1, PREG_SPLIT_NO_EMPTY);
        }
        if (! is_array($events)) {
            throw new ApiException(422, 'invalid_request_error', 'events must be an array of strings.', 'invalid_request', 'events');
        }

        $clean = array_values(array_unique(array_map(fn ($e) => trim((string) $e), $events)));
        foreach ($clean as $event) {
            if (! in_array($event, ApiWebhook::SUPPORTED_EVENTS, true)) {
                throw new ApiException(422, 'invalid_request_error', "Unsupported event \"{$event}\".", 'invalid_request', 'events');
            }
        }

        return $clean;
    }

    private function summarize(ApiWebhook $w): array
    {
        return [
            'object' => 'api_webhook',
            'webhook_uuid' => (string) $w->webhook_uuid,
            'domain_uuid' => (string) $w->domain_uuid,
            'url' => $w->url,
            'events' => $w->events ?? [],
            'enabled' => (bool) $w->enabled,
            'description' => $w->description,
            'last_success_at' => $w->last_success_at?->toIso8601ZuluString(),
            'last_failure_at' => $w->last_failure_at?->toIso8601ZuluString(),
            'consecutive_failures' => (int) $w->consecutive_failures,
            'created_at' => $w->created_at?->toIso8601ZuluString(),
        ];
    }

    private function assertUuid(string $value, string $param): void
    {
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $value)) {
            throw new ApiException(400, 'invalid_request_error', "Invalid {$param}.", 'invalid_request', $param);
        }
    }
}
