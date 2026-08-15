<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Source URL -> Shopify CDN URL, per shop.
 *
 * Only used by the description-image re-hosting (see App\Migration\DescriptionImages),
 * and it is what makes that feature affordable at all. The images inside product
 * descriptions are not one per product: a size chart, a brand banner, a delivery
 * icon, all pasted into the same template and repeated across the catalogue.
 * Without this table a single shared graphic on a 20,000-product store would be
 * uploaded to Shopify twenty thousand times — and each upload is a mutation plus
 * a poll loop waiting for Shopify to finish processing it.
 *
 * Keyed by a HASH of the URL rather than the URL itself: WordPress produces long
 * URLs, MySQL's index key length is finite, and an exact-match lookup is all this
 * ever needs. The original is kept alongside for the times somebody has to work
 * out where a file came from.
 *
 * Scoped per shop because a Shopify file belongs to one store — the same source
 * image migrated for two merchants is genuinely two uploads.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('migration_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->char('source_hash', 64);
            $table->text('source_url');
            $table->text('shopify_url');
            $table->string('shopify_file_id')->nullable();
            $table->timestamps();

            // One upload per image per shop, and the lookup this is all for.
            $table->unique(['shop_id', 'source_hash'], 'migration_files_shop_source_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('migration_files');
    }
};
