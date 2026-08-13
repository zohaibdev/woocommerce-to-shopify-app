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
    ];

    /**
     * @param  callable(array): array{0: ?string, 1: array}  $write  fn($input) => [shopifyId, errors]
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
            [$shopifyId, $errors] = $write($input);

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

        // Shopify usually sends a path: ["phone"], ["variants","0","price"].
        $path = $error['field'] ?? null;
        $root = is_array($path) ? ($path[0] ?? null) : (is_string($path) ? $path : null);

        if ($root !== null && in_array($root, $candidates, true)) {
            return $root;
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
