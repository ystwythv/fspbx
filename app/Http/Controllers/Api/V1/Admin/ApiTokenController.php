<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Domain;
use App\Models\Sanctum\PersonalAccessToken;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class ApiTokenController extends Controller
{
    /**
     * List API tokens.
     *
     * @group Admin API Tokens
     * @authenticated
     */
    public function index(Request $request)
    {
        $this->assertUser($request);

        $limit = max(1, min(100, (int) $request->query('limit', 25)));
        $startingAfter = trim((string) $request->query('starting_after', ''));

        $query = PersonalAccessToken::query()->orderBy('id');
        if ($startingAfter !== '') {
            $query->where('id', '>', $startingAfter);
        }

        $rows = $query->limit($limit + 1)->get();
        $hasMore = $rows->count() > $limit;
        $rows = $rows->take($limit);

        return response()->json([
            'object' => 'list',
            'url' => '/api/v1/admin/api-tokens',
            'has_more' => $hasMore,
            'data' => $rows->map(fn ($t) => $this->summarize($t))->all(),
        ], 200);
    }

    /**
     * Create an API token.
     *
     * Body:
     * - name (required)
     * - type: "global" or "tenant" (required)
     * - domain_uuid: required when type="tenant", forbidden when type="global"
     * - expires_at: optional ISO 8601
     * - abilities: optional string[]
     *
     * The plain-text token is returned once in `token`; it is unrecoverable
     * afterwards.
     *
     * @group Admin API Tokens
     * @authenticated
     */
    public function store(Request $request)
    {
        $user = $this->assertUser($request);

        $name = trim((string) $request->input('name', ''));
        $type = strtolower(trim((string) $request->input('type', '')));
        $domainUuid = trim((string) $request->input('domain_uuid', ''));
        $expiresAtRaw = trim((string) $request->input('expires_at', ''));
        $abilities = $request->input('abilities');

        if ($name === '') {
            throw new ApiException(422, 'invalid_request_error', 'name is required.', 'parameter_missing', 'name');
        }

        if (! in_array($type, ['global', 'tenant'], true)) {
            throw new ApiException(422, 'invalid_request_error', 'type must be "global" or "tenant".', 'invalid_request', 'type');
        }

        if ($type === 'tenant') {
            if ($domainUuid === '' || ! preg_match('/^[0-9a-fA-F-]{36}$/', $domainUuid)) {
                throw new ApiException(422, 'invalid_request_error', 'domain_uuid is required for tenant tokens.', 'parameter_missing', 'domain_uuid');
            }
            if (! Domain::query()->where('domain_uuid', $domainUuid)->exists()) {
                throw new ApiException(404, 'invalid_request_error', 'Domain not found.', 'resource_missing', 'domain_uuid');
            }
        } elseif ($domainUuid !== '') {
            throw new ApiException(422, 'invalid_request_error', 'domain_uuid must not be set for global tokens.', 'invalid_request', 'domain_uuid');
        }

        $abilitiesArray = $this->normaliseAbilities($abilities, $type);

        $expiresAt = null;
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

        $newToken = $user->createToken($name, $abilitiesArray, $expiresAt);

        $token = $newToken->accessToken;
        if ($type === 'tenant') {
            $token->forceFill(['domain_uuid' => $domainUuid])->save();
        }

        return response()->json([
            'object' => 'api_token',
            'id' => (string) $token->id,
            'name' => $token->name,
            'type' => $type,
            'domain_uuid' => $type === 'tenant' ? $domainUuid : null,
            'abilities' => $abilitiesArray,
            'expires_at' => $expiresAt?->toIso8601ZuluString(),
            'created_at' => $token->created_at?->toIso8601ZuluString(),
            'token' => $newToken->plainTextToken,
        ], 201);
    }

    /**
     * Revoke an API token.
     *
     * @group Admin API Tokens
     * @authenticated
     */
    public function destroy(Request $request, string $token_id)
    {
        $this->assertUser($request);

        if (! preg_match('/^[0-9a-fA-F-]{36}$/', $token_id)) {
            throw new ApiException(400, 'invalid_request_error', 'Invalid token id.', 'invalid_request', 'token_id');
        }

        $token = PersonalAccessToken::query()->where('id', $token_id)->first();
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

    private function assertUser(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            throw new ApiException(401, 'authentication_error', 'Unauthenticated.', 'unauthenticated');
        }
        return $user;
    }

    private function normaliseAbilities($abilities, string $type): array
    {
        $default = $type === 'global' ? ['cdr:read', 'cdr:all-domains'] : ['cdr:read'];

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
        return $clean === [] ? $default : $clean;
    }

    private function summarize(PersonalAccessToken $t): array
    {
        $type = $t->domain_uuid ? 'tenant' : 'global';
        return [
            'object' => 'api_token',
            'id' => (string) $t->id,
            'name' => $t->name,
            'type' => $type,
            'domain_uuid' => $t->domain_uuid,
            'abilities' => $t->abilities ?? [],
            'last_used_at' => $t->last_used_at?->toIso8601ZuluString(),
            'expires_at' => $t->expires_at?->toIso8601ZuluString(),
            'created_at' => $t->created_at?->toIso8601ZuluString(),
        ];
    }
}
