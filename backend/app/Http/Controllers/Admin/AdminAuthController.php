<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

class AdminAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        // Throttle brute-force attempts per email+IP.
        $key = 'admin-login:' . strtolower($data['email']) . '|' . $request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return response()->json([
                'error' => 'Too many attempts. Try again in ' . RateLimiter::availableIn($key) . 's.',
            ], 429);
        }

        $admin = AdminUser::where('email', $data['email'])->first();

        if (! $admin || ! $admin->checkPassword($data['password'])) {
            RateLimiter::hit($key, 300);

            return response()->json(['error' => 'Invalid credentials'], 401);
        }

        RateLimiter::clear($key);

        return response()->json([
            'token' => $admin->issueToken(),
            'admin' => ['id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email],
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $admin = $request->attributes->get('admin');

        return response()->json([
            'id' => $admin->id, 'name' => $admin->name, 'email' => $admin->email,
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $admin = $request->attributes->get('admin');
        $admin->forceFill(['api_token_hash' => null])->save();

        return response()->json(['ok' => true]);
    }
}
