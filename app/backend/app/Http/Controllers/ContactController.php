<?php

namespace App\Http\Controllers;

use App\Mail\ContactMessage;
use App\Models\AdminUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Public contact form on the marketing site. Unauthenticated by necessity — the
 * sender has no Shopify session — so it is rate limited at the route and carries
 * a honeypot field.
 */
class ContactController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:190'],
            'shop' => ['sometimes', 'nullable', 'string', 'max:190'],
            'subject' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
            // Honeypot: a real person never sees or fills this.
            'website' => ['sometimes', 'nullable', 'string', 'max:0'],
        ]);

        foreach ($this->recipients() as $to) {
            try {
                Mail::to($to)->send(new ContactMessage($data));
            } catch (Throwable $e) {
                Log::warning('Contact form could not be delivered', ['error' => $e->getMessage()]);

                return response()->json([
                    'error' => 'We could not send your message right now. Please email us directly.',
                ], 503);
            }
        }

        return response()->json(['ok' => true]);
    }

    /** @return array<int, string> */
    private function recipients(): array
    {
        if ($configured = config('services.support.admin_email')) {
            return array_filter(array_map('trim', explode(',', (string) $configured)));
        }

        $admins = AdminUser::where('is_super', true)->pluck('email')->filter()->unique()->values()->all();

        return $admins ?: array_filter([config('mail.from.address')]);
    }
}
