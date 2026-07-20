<?php

namespace App\Http\Controllers;

use App\Models\ApiWebhook;
use App\Models\ApiWebhookDelivery;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Settings → Webhooks (issue #9): tenant self-service UI over
 * v_api_webhooks. Web (session) counterpart of Api\V1\ApiWebhookController.
 */
class ApiWebhookSettingsController extends Controller
{
    protected $viewName = 'ApiWebhooks';

    public function index()
    {
        if (! userCheckPermission('api_webhook_manage')) {
            return redirect('/');
        }

        return Inertia::render($this->viewName, [
            'data' => function () {
                return $this->getData();
            },
            'routes' => [
                'current_page' => route('api-webhooks.index'),
                'store' => route('api-webhooks.store'),
            ],
        ]);
    }

    public function getData()
    {
        return ApiWebhook::query()
            ->where('domain_uuid', session('domain_uuid'))
            ->orderBy('created_at')
            ->get()
            ->map(fn (ApiWebhook $webhook) => [
                'webhook_uuid' => (string) $webhook->webhook_uuid,
                'url' => $webhook->url,
                'events' => $webhook->events ?? [],
                'enabled' => (bool) $webhook->enabled,
                'description' => $webhook->description,
                'last_success_at_formatted' => $webhook->last_success_at?->format('Y-m-d H:i') ?? 'Never',
                'last_failure_at_formatted' => $webhook->last_failure_at?->format('Y-m-d H:i') ?? 'Never',
                'consecutive_failures' => (int) $webhook->consecutive_failures,
                'created_at_formatted' => $webhook->created_at?->format('Y-m-d H:i'),
                'destroy_route' => route('api-webhooks.destroy', $webhook->webhook_uuid),
                'rotate_route' => route('api-webhooks.rotate', $webhook->webhook_uuid),
                'deliveries_route' => route('api-webhooks.deliveries', $webhook->webhook_uuid),
            ]);
    }

    public function store(Request $request)
    {
        if (! userCheckPermission('api_webhook_manage')) {
            return response()->json(['errors' => ['server' => ['Permission denied']]], 403);
        }

        $validated = $request->validate([
            'url' => 'required|url|starts_with:https://|max:2048',
            'description' => 'nullable|string|max:255',
        ]);

        if (ApiWebhook::where('domain_uuid', session('domain_uuid'))->count() >= 10) {
            return response()->json(['errors' => ['url' => ['Webhook limit reached for this domain (10)']]], 422);
        }

        $secret = ApiWebhook::generateSecret();

        ApiWebhook::create([
            'domain_uuid' => session('domain_uuid'),
            'url' => $validated['url'],
            'secret' => $secret,
            'events' => [ApiWebhook::EVENT_CDR_FINALIZED],
            'enabled' => true,
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'messages' => ['success' => ['Webhook created. Copy the signing secret now — it will not be shown again.']],
            'secret' => $secret,
        ]);
    }

    public function rotateSecret(Request $request, string $webhook_uuid)
    {
        if (! userCheckPermission('api_webhook_manage')) {
            return response()->json(['errors' => ['server' => ['Permission denied']]], 403);
        }

        $webhook = $this->findWebhook($webhook_uuid);
        if (! $webhook) {
            return response()->json(['errors' => ['server' => ['Webhook not found']]], 404);
        }

        $secret = ApiWebhook::generateSecret();
        $webhook->update(['secret' => $secret]);

        return response()->json([
            'messages' => ['success' => ['Secret rotated. Update your receiver — the old secret no longer works.']],
            'secret' => $secret,
        ]);
    }

    public function deliveries(Request $request, string $webhook_uuid)
    {
        if (! userCheckPermission('api_webhook_manage')) {
            return response()->json(['errors' => ['server' => ['Permission denied']]], 403);
        }

        $webhook = $this->findWebhook($webhook_uuid);
        if (! $webhook) {
            return response()->json(['errors' => ['server' => ['Webhook not found']]], 404);
        }

        $rows = ApiWebhookDelivery::query()
            ->where('webhook_uuid', $webhook->webhook_uuid)
            ->orderByDesc('created_at')
            ->limit(25)
            ->get()
            ->map(fn (ApiWebhookDelivery $d) => [
                'delivery_uuid' => (string) $d->delivery_uuid,
                'event_type' => $d->event_type,
                'status' => $d->status,
                'attempts' => $d->attempts,
                'last_error' => $d->last_error,
                'sent_at_formatted' => $d->sent_at?->format('Y-m-d H:i:s') ?? '—',
                'created_at_formatted' => $d->created_at?->format('Y-m-d H:i:s'),
            ]);

        return response()->json(['data' => $rows]);
    }

    public function destroy(Request $request, string $webhook_uuid)
    {
        if (! userCheckPermission('api_webhook_manage')) {
            return redirect()->back()->with('error', ['server' => ['Permission denied']]);
        }

        $webhook = $this->findWebhook($webhook_uuid);
        if (! $webhook) {
            return redirect()->back()->with('error', ['server' => ['Webhook not found']]);
        }

        $webhook->delete();

        return redirect()->back()->with('message', ['server' => ['Webhook deleted']]);
    }

    private function findWebhook(string $webhook_uuid): ?ApiWebhook
    {
        return ApiWebhook::query()
            ->where('domain_uuid', session('domain_uuid'))
            ->where('webhook_uuid', $webhook_uuid)
            ->first();
    }
}
