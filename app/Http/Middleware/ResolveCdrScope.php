<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Enforces tenant vs global scope on CDR API routes.
 *
 * A PersonalAccessToken with `domain_uuid` set is a tenant token: it may only
 * access routes whose `{domain_uuid}` path parameter matches. A token with a
 * null `domain_uuid` is a global token: it may access any domain, but when
 * reaching a tenant-scoped route (one with `{domain_uuid}`) it must still
 * carry the `cdr:all-domains` ability or a user permission is enforced by
 * `user.authorize:cdr_api_read_all_domains` on the route.
 *
 * Global-only routes (no `{domain_uuid}` in the path) require the token to
 * carry the `cdr:all-domains` ability — tenant tokens are rejected with 403.
 */
class ResolveCdrScope
{
    public function handle(Request $request, Closure $next, string $mode = 'tenant')
    {
        $token = $this->currentToken($request);

        if ($token === null) {
            throw new ApiException(
                401,
                'authentication_error',
                'Unauthenticated.',
                'unauthenticated',
            );
        }

        $tokenDomain = $token->domain_uuid ? (string) $token->domain_uuid : null;
        $isGlobalToken = $tokenDomain === null;

        if ($mode === 'global') {
            if (! $isGlobalToken) {
                throw new ApiException(
                    403,
                    'invalid_request_error',
                    'This endpoint requires a global admin token.',
                    'forbidden_scope',
                );
            }

            return $next($request);
        }

        // Tenant-scoped route: URL must carry {domain_uuid}
        $pathDomain = (string) $request->route('domain_uuid');

        if ($pathDomain === '') {
            throw new ApiException(
                400,
                'invalid_request_error',
                'Missing domain_uuid on tenant-scoped route.',
                'invalid_request',
                'domain_uuid',
            );
        }

        if (! $isGlobalToken && $tokenDomain !== $pathDomain) {
            throw new ApiException(
                403,
                'invalid_request_error',
                'This token is not permitted to access that domain.',
                'forbidden_domain',
                'domain_uuid',
            );
        }

        // Expose the resolved domain for downstream consumers
        $request->attributes->set('cdr_domain_uuid', $pathDomain);
        $request->attributes->set('cdr_token_is_global', $isGlobalToken);

        return $next($request);
    }

    private function currentToken(Request $request): ?PersonalAccessToken
    {
        $bearer = $request->bearerToken();
        if (! $bearer) {
            return null;
        }

        return PersonalAccessToken::findToken($bearer);
    }
}
