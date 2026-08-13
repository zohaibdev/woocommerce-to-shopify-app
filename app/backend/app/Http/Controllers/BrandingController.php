<?php

namespace App\Http\Controllers;

use App\Services\Branding;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the admin-uploaded email logo. Public and unauthenticated on purpose —
 * it is embedded in emails, so mail clients fetch it with no session.
 */
class BrandingController extends Controller
{
    public function logo(): Response
    {
        $file = Branding::logoFile();

        abort_unless($file, 404);

        [$path, $mime] = $file;

        return response()->file($path, [
            'Content-Type' => $mime,
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
