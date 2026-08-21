<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Branding;
use App\Services\MailSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Super-admin application settings. Currently SMTP, which used to live only in
 * .env — unreachable on shared hosting without SSH.
 *
 * The SMTP password is never returned to the client; the response only reports
 * whether one is set.
 */
class AdminSettingsController extends Controller
{
    public function mail(): JsonResponse
    {
        return response()->json(MailSettings::current());
    }

    public function updateMail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:255'],
            // Blank means "keep the stored password" so the admin can edit the
            // host without re-typing the credential.
            'password' => ['nullable', 'string', 'max:255'],
            'scheme' => ['required', 'in:smtp,smtps'],
            'from_address' => ['required', 'email', 'max:255'],
            'from_name' => ['required', 'string', 'max:255'],
        ]);

        return response()->json(MailSettings::save($data));
    }

    /** Current email branding (logo + the name shown in emails). */
    public function branding(): JsonResponse
    {
        return response()->json(Branding::current());
    }

    /**
     * Replace the logo used at the top of every email. Stored outside the web
     * root and streamed back by a public route, so no symlink or writable
     * assets folder is needed on shared hosting.
     */
    public function updateBranding(Request $request): JsonResponse
    {
        $request->validate([
            // Email clients don't render SVG — raster only.
            'logo' => ['sometimes', 'file', 'mimes:png,jpg,jpeg,gif', 'max:512'],
            'name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'remove_logo' => ['sometimes', 'boolean'],
        ]);

        if ($request->boolean('remove_logo')) {
            Branding::removeLogo();
        } elseif ($request->hasFile('logo')) {
            Branding::storeLogo($request->file('logo'));
        }

        if ($request->has('name')) {
            Branding::setName((string) $request->input('name'));
        }

        return response()->json(Branding::current());
    }

    /** Send a test message using the SAVED settings. */
    public function testMail(Request $request): JsonResponse
    {
        $data = $request->validate([
            'to' => ['required', 'email'],
        ]);

        if (! MailSettings::current()['configured']) {
            return response()->json(['error' => 'Save your SMTP settings before sending a test.'], 422);
        }

        try {
            Mail::raw(
                "This is a test message from Shop Shift.\n\nIf you received it, your SMTP settings are working.",
                fn ($message) => $message->to($data['to'])->subject('Shop Shift SMTP test'),
            );
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['sent_to' => $data['to']]);
    }
}
