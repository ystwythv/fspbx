<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Sanctum\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Tenant self-service API token management.
 *
 * Reachable only via `cdr.scope:tenant` routes — the middleware guarantees
 * the token operating on these endpoints is bound to the URL's
 * `{domain_uuid}`. All token operations are further scoped to that domain
 * to prevent a tenant viewing or mutating another tenant's tokens.
 */
class TenantApiTokenController extends Controller
{
    /**
     * List tokens for this tenant.
     *
     * @group Tenant API Tokens
     * @authenticated
     */
    public function index(Request $request, string $domain_uuid)
    {
        $this->assertUuid($domain_uuid);

        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        $startingAfter = trim((string) $request->query('starting_after', ''));

        $query = PersonalAccessToken::query()
            ->where('domain_uuid', $domain_uuid)
            ->orderBy('id');

        if ($startingAfter !== '') {
            $query->where('id', '>', $startingAfter);
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json([
            'object' => 'list',
            'url' => '/api/v1/domains/' . $domain_uuid . '/api-tokens',
            'has_more' => $hasMore,
            'data' => $rows->map(fn ($t) => $this->summarize($t))->all(),
        ], 200);
    }

    /**
     * Create a tenant-scoped API token.
     *
     * Body: { name: string, expires_at?: ISO8601, abilities?: string[] }
     *
     * Tenant tokens are always bound to the domain in the URL — callers
     * cannot override that via the body.
     *
     * @group Tenant API Tokens
     * @authenticated
     */
    public function store(Request $request, string $domain_uuid)
    {
        $this->assertUuid($domain_uuid);

        $user = $request->user();
        if (! $user) {
            throw new ApiException(401, 'authentication_error', 'Unauthenticated.', 'unauthenticated');
        }

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            throw new ApiException(422, 'invalid_request_error', 'name is required.', 'parameter_missing', 'name');
        }

        if (! Domain::query()->where('domain_uuid', $domain_uuid)->exists()) {
            throw new ApiException(404, 'invalid_request_error', 'Domain not found.', 'resource_missing', 'domain_uuid');
        }

        $abilities = $this->normaliseAbilities($request->input('abilities'));

        $expiresAt = null;
        $expiresAtRaw = trim((string) $request->input('expires_at', ''));
        if ($expiresAtRaw !== '') {
            try {
                $expiresAt = Carbon::parse($expiresAtRaw);
            } catch (\Throwable $e) {
                throw new ApiException(422, 'invalid_request_error', 'Invalid expires_at.', 'invalid_request', 'expires_at');
            }
            if ($expiresAt->isPast()) {
                throw new ApiException(422, 'invalid_request_error', 'expires_at must be in the future.', 'invalid_request', 'expires_at');
            }
        }

        $newToken = $user->createToken($name, $abilities, $expiresAt);

        $token = $newToken->accessToken;
        $token->forceFill(['domain_uuid' => $domain_uuid])->save();

        return response()->json([
            'object' => 'api_token',
            'id' => (string) $token->id,
            'name' => $token->name,
            'type' => 'tenant',
            'domain_uuid' => $domain_uuid,
            'abilities' => $abilities,
            'expires_at' => $expiresAt?->toIso8601ZuluString(),
            'created_at' => $token->created_at?->toIso8601ZuluString(),
            'token' => $newToken->plainTextToken,
        ], 201);
    }

    /**
     * Revoke a tenant-scoped API token.
     *
     * @group Tenant API Tokens
     * @authenticated
     */
    public function destroy(Request $request, string $domain_uuid, string $token_id)
    {
        $this->assertUuid($domain_uuid);

        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $token_id)) {
            throw new ApiException(400, 'invalid_request_error', 'Invalid token id.', 'invalid_request', 'token_id');
        }

        $token = PersonalAccessToken::query()
            ->where('id', $token_id)
            ->where('domain_uuid', $domain_uuid)
            ->first();

        if (! $token) {
            throw new ApiException(404, 'invalid_request_error', 'Token not found.', 'resource_missing', 'token_id');
        }

        $token->delete();

        return response()->json([
            'object' => 'api_token',
            'id' => $token_id,
            'deleted' => true,
        ], 200);
    }

    private function assertUuid(string $value): void
    {
        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $value)) {
            throw new ApiException(400, 'invalid_request_error', 'Invalid domain_uuid.', 'invalid_request', 'domain_uuid');
        }
    }

    private function normaliseAbilities($abilities): array
    {
        $default = ['cdr:read'];

        if ($abilities === null || $abilities === '' || $abilities === []) {
            return $default;
        }

        if (is_string($abilities)) {
            $abilities = preg_split('/\s*,\s*/', $abilities, -1, PREG_SPLIT_NO_EMPTY);
        }

        if (! is_array($abilities)) {
            throw new ApiException(422, 'invalid_request_error', 'abilities must be an array of strings.', 'invalid_request', 'abilities');
        }

        $clean = array_values(array_unique(array_filter(array_map(fn ($a) => trim((string) $a), $abilities))));

        // Prevent tenant tokens from claiming the global-read ability
        $clean = array_values(array_filter($clean, fn ($a) => $a !== 'cdr:all-domains'));

        return $clean === [] ? $default : $clean;
    }

    private function summarize(PersonalAccessToken $t): array
    {
        return [
            'object' => 'api_token',
            'id' => (string) $t->id,
            'name' => $t->name,
            'type' => 'tenant',
            'domain_uuid' => $t->domain_uuid,
            'abilities' => $t->abilities ?? [],
            'last_used_at' => $t->last_used_at?->toIso8601ZuluString(),
            'expires_at' => $t->expires_at?->toIso8601ZuluString(),
            'created_at' => $t->created_at?->toIso8601ZuluString(),
        ];
    }
}
