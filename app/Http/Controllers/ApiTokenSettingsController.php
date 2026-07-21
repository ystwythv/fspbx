<?php

namespace App\Http\Controllers;

use App\Models\Sanctum\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

/**
 * Settings → API Tokens (issue #11): tenant self-service UI over the
 * domain-bound Sanctum tokens introduced by the CDR API (PR #6). Web
 * (session) counterpart of Api\V1\TenantApiTokenController.
 */
class ApiTokenSettingsController extends Controller
{
    protected $viewName = 'ApiTokens';

    public function index()
    {
        if (! userCheckPermission('api_token_self_manage')) {
            return redirect('/');
        }

        return Inertia::render($this->viewName, [
            'data' => function () {
                return $this->getData();
            },
            'routes' => [
                'current_page' => route('api-tokens.index'),
                'store' => route('api-tokens.store'),
            ],
        ]);
    }

    public function getData()
    {
        return PersonalAccessToken::query()
            ->where('domain_uuid', session('domain_uuid'))
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (PersonalAccessToken $token) => [
                'id' => (string) $token->id,
                'name' => $token->name,
                'abilities' => $token->abilities ?? [],
                'created_at_formatted' => $token->created_at?->format('Y-m-d H:i'),
                'last_used_at_formatted' => $token->last_used_at?->format('Y-m-d H:i') ?? 'Never',
                'expires_at_formatted' => $token->expires_at?->format('Y-m-d H:i') ?? 'Never',
                'destroy_route' => route('api-tokens.destroy', $token->id),
            ]);
    }

    public function store(Request $request)
    {
        if (! userCheckPermission('api_token_self_manage')) {
            return response()->json(['errors' => ['server' => ['Permission denied']]], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:100',
            'expires_days' => 'nullable|integer|min:1|max:3650',
        ]);

        $expiresAt = ! empty($validated['expires_days'])
            ? Carbon::now()->addDays((int) $validated['expires_days'])
            : null;

        // domain-bound tenant token; cdr:all-domains is never grantable here
        $newToken = $request->user()->createToken($validated['name'], ['cdr:read'], $expiresAt);
        $newToken->accessToken->forceFill(['domain_uuid' => session('domain_uuid')])->save();

        return response()->json([
            'messages' => ['success' => ['Token created. Copy it now — it will not be shown again.']],
            'token' => $newToken->plainTextToken,
        ]);
    }

    public function destroy(Request $request, string $token_id)
    {
        if (! userCheckPermission('api_token_self_manage')) {
            return redirect()->back()->with('error', ['server' => ['Permission denied']]);
        }

        $token = PersonalAccessToken::query()
            ->where('id', $token_id)
            ->where('domain_uuid', session('domain_uuid'))
            ->first();

        if (! $token) {
            return redirect()->back()->with('error', ['server' => ['Token not found']]);
        }

        $token->delete();

        return redirect()->back()->with('message', ['server' => ['Token revoked']]);
    }
}
