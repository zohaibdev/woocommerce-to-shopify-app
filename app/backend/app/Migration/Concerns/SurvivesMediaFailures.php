<?php

namespace App\Migration\Concerns;

/**
 * SPECIFICATION.md §6.4: "Image failure never fails the product."
 *
 * Shopify downloads media from the source URL itself, so any image that has been
 * moved, deleted or is behind auth comes back as a userError on the whole
 * mutation — and used to fail the entire product or collection with it. A store
 * whose /uploads folder is gone would migrate literally nothing.
 *
 * Migrators use this to recognise a media-only error and retry once without the
 * media, so the record lands and the merchant gets a warning naming what was
 * dropped.
 */
trait SurvivesMediaFailures
{
    /**
     * True when every reported error is about media Shopify could not fetch.
     * Deliberately strict: a mixed error set still fails the item, because the
     * non-media problem would otherwise be silently swallowed.
     *
     * @param  array<int, array{field?: mixed, message?: string}>  $errors
     */
    protected function isMediaOnlyError(array $errors): bool
    {
        if (empty($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            if (! $this->looksLikeMediaError((string) ($error['message'] ?? ''))) {
                return false;
            }
        }

        return true;
    }

    private function looksLikeMediaError(string $message): bool
    {
        $patterns = [
            'image upload failed',
            'failed to download',
            'file not found',
            'could not be processed',
            'invalid image',
            'invalid url for image',
            'media could not',
            'unsupported image',
        ];

        $message = mb_strtolower($message);

        foreach ($patterns as $pattern) {
            if (str_contains($message, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /** Short, merchant-readable summary of the media that had to be dropped. */
    protected function mediaWarning(array $errors): string
    {
        $first = (string) ($errors[0]['message'] ?? 'an image could not be downloaded');
        $extra = count($errors) > 1 ? ' (+'.(count($errors) - 1).' more)' : '';

        return "Migrated without images — {$first}{$extra}";
    }
}
