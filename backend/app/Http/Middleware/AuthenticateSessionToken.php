<?php

namespace App\Http\Middleware;

use App\Services\SessionToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates embedded-app API calls via a Shopify App Bridge session token
 * (Authorization: Bearer <jwt>). On success the verified shop domain is placed
 * on the request for CurrentShop to resolve.
 *
 * Local-dev fallback: when APP_ENV=local and no bearer token is present, the
 * X-Shop-Domain header is accepted so the app is testable before embedding.
 */
class AuthenticateSessionToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearer = $request->bearerToken();

        if ($bearer) {
            $shop = SessionToken::shopDomain($bearer);
            if (! $shop) {
                return response()->json(['error' => 'Invalid session token'], 401);
            }
            $request->attributes->set('shop_domain', $shop);

            return $next($request);
        }

        // Development/testing fallback only.
        if (app()->environment('local', 'testing') && $request->header('X-Shop-Domain')) {
            $request->attributes->set('shop_domain', $request->header('X-Shop-Domain'));

            return $next($request);
        }

        return response()->json(['error' => 'Missing session token'], 401);
    }
}
