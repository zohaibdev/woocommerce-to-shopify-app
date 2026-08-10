<?php

namespace App\Http\Middleware;

use App\Models\AdminUser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates super-admin API calls via a bearer token issued at login.
 * Entirely separate from the merchant (Shopify session token) auth.
 */
class AuthenticateAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = AdminUser::findByToken($request->bearerToken());

        if (! $admin) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        $request->attributes->set('admin', $admin);

        return $next($request);
    }
}
