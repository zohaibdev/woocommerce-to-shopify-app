<?php

namespace App\Migration\Concerns;

use App\Migration\MigrationItemResult;

/**
 * One bad optional field must never cost you the whole record.
 *
 * WooCommerce data is messy in ways Shopify refuses: a deleted image URL, a phone
 * in local format, a tag that is too long, a metafield with a value Shopify won't
 * take. Each of those used to fail the entire product/customer/order.
 *
 * This retries the write, shedding the offending OPTIONAL field each round, until
 * it either lands or hits something it is not allowed to drop. The merchant gets
 * the record plus a warning naming exactly what was left behind — which is the
 * whole point: they can see what is missing instead of losing the row.
 *
 * Required fields are never shed, so a genuinely broken record still fails loudly.
 */
trait ShedsRejectedFields
{
    /**
     * Keywords in a Shopify error message mapped to the input keys they implicate,
     * for the errors that arrive with no `field` path at all (media errors do).
     */
    private const MESSAGE_HINTS = [
        'image' => ['files', 'image'],
        'media' => ['files', 'image'],
        'failed to download' => ['files', 'image'],
        'file not found' => ['files', 'image'],
        'phone' => ['phone'],
        'tag' => ['tags'],
        'metafield' => ['metafields'],
        'address' => ['addresses', 'shippingAddress', 'billingAddress'],
        'transaction' => ['transactions'],
        'vendor' => ['vendor'],
        'collection' => ['collections'],
        // "Handle has already been taken" is the single most common rejection on
        // a store that already has products, and it arrived with a field path of
        // ["input","handle"] — root "input", which matches no optional key — so
        // the handle could never be shed and the whole PRODUCT failed over a URL.
        'handle' => ['handle'],
        'seo' => ['seo'],
        'discount' => ['discountCode'],
        'description' => ['descriptionHtml'],
    ];

    /**
     * @param  callable(array): (array{0: ?string, 1: array}|MigrationItemResult)  $write
     *                        fn($input) => [shopifyId, errors], or a finished verdict
     * @param  array<string>  $optional  input keys safe to drop, in the caller's own judgement
     * @param  callable(string): void|null  $onShed  told each field name as it is dropped, so a
     *                                              migrator can learn from a repeating rejection
     */
    protected function writeSheddingBadFields(
        callable $write,
        array $input,
        array $optional,
        ?callable $onShed = null,
        int $maxRounds = 3,
    ): MigrationItemResult {
        $dropped = [];
        $errors = [];

        for ($round = 0; $round <= $maxRounds; $round++) {
            $outcome = $write($input);

            // A write may answer with a finished verdict instead of an
            // [id, errors] pair, for a failure that has nothing to do with the
            // record's fields: an expired token, a missing scope, Shopify
            // refusing the query outright. Dropping the description will not
            // help with any of those, and trying it three times turns one clear
            // refusal into four wasted API calls per record.
            if ($outcome instanceof MigrationItemResult) {
                return $outcome;
            }

            [$shopifyId, $errors] = $outcome;

            if ($shopifyId && empty($errors)) {
                return empty($dropped)
                    ? MigrationItemResult::success($shopifyId)
                    : MigrationItemResult::successWithWarning($shopifyId, $this->describeDropped($dropped));
            }

            if (empty($errors)) {
                return MigrationItemResult::failed('No id returned by Shopify');
            }

            $shed = $this->droppableFields($errors, $optional, $input);

            // Nothing we are allowed to drop — this is a real failure.
            if (empty($shed)) {
                break;
            }

            foreach ($shed as $field => $message) {
                unset($input[$field]);
                $dropped[$field] = $message;

                if ($onShed) {
                    $onShed($field);
                }
            }
        }

        return MigrationItemResult::failed(json_encode($errors));
    }

    /**
     * Which optional fields these errors point at. Returns [] if ANY error cannot
     * be attributed to a droppable field — partial shedding would hide a real bug.
     *
     * @return array<string, string> field => the message that condemned it
     */
    private function droppableFields(array $errors, array $optional, array $input): array
    {
        $shed = [];

        foreach ($errors as $error) {
            $field = $this->fieldForError($error, $optional, $input);

            if ($field === null) {
                return [];
            }

            $shed[$field] ??= (string) ($error['message'] ?? 'rejected by Shopify');
        }

        return $shed;
    }

    private function fieldForError(array $error, array $optional, array $input): ?string
    {
        $candidates = array_values(array_intersect($optional, array_keys($input)));

        // Shopify sends a path: ["phone"], ["variants","0","price"] — but it also
        // prefixes it with the MUTATION ARGUMENT on several mutations, so the same
        // rejection arrives as ["input","handle"] from productSet and ["handle"]
        // from collectionCreate. Reading only the first segment meant the argument
        // name shadowed the real field and nothing could be shed at all.
        //
        // Scanning every segment is safe because a segment only counts when it
        // matches a key we were explicitly told is optional for this migrator.
        $path = $error['field'] ?? null;
        $segments = is_array($path) ? $path : (is_string($path) ? [$path] : []);

        foreach ($segments as $segment) {
            if (is_string($segment) && in_array($segment, $candidates, true)) {
                return $segment;
            }
        }

        // Media errors (and some others) carry no field — read the message.
        $message = mb_strtolower((string) ($error['message'] ?? ''));

        foreach (self::MESSAGE_HINTS as $keyword => $fields) {
            if (! str_contains($message, $keyword)) {
                continue;
            }

            foreach ($fields as $candidate) {
                if (in_array($candidate, $candidates, true)) {
                    return $candidate;
                }
            }
        }

        return null;
    }

    /** Merchant-readable summary of what had to be left out. */
    private function describeDropped(array $dropped): string
    {
        $parts = [];

        foreach ($dropped as $field => $message) {
            $parts[] = self::label($field).' skipped — '.rtrim($message, '.');
        }

        return 'Migrated with issues: '.implode('; ', $parts);
    }

    private static function label(string $field): string
    {
        return match ($field) {
            'files' => 'Images',
            'image' => 'Image',
            'metafields' => 'Custom fields',
            'collections' => 'Collection links',
            'addresses' => 'Addresses',
            'shippingAddress' => 'Shipping address',
            'billingAddress' => 'Billing address',
            'transactions' => 'Payment record',
            'shippingLines' => 'Shipping line',
            'descriptionHtml' => 'Description',
            'processedAt' => 'Original order date',
            default => ucfirst($field),
        };
    }
}
