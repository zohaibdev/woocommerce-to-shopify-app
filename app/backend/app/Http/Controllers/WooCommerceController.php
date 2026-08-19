<?php

namespace App\Http\Controllers;

use App\Migration\MigratorRegistry;
use App\Models\MigrationEntity;
use App\Models\WoocommerceConnection;
use App\Services\Exceptions\WooCommerceUnavailableException;
use App\Services\MailSettings;
use App\Services\ShopifyPlan;
use App\Services\WooCommerceClient;
use App\Support\CurrentShop;
use App\Support\MigrationEstimate;
use App\Support\PlanAllowance;
use App\Support\PlanPool;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WooCommerceController extends Controller
{
    /**
     * Entities whose failure means the STORE is unreadable rather than one
     * optional endpoint being unavailable. These all come from `wc/v3`, which the
     * consumer key authenticates — if they fail, the credentials or the store are
     * the problem and the merchant needs to know before starting anything.
     */
    private const CORE_ENTITIES = ['product', 'category', 'customer', 'order', 'coupon'];

    /** Save credentials and test the connection. */
    public function connect(Request $request): JsonResponse
    {
        // A merchant typing "mystore.com" is not making a mistake — they are
        // giving us the store. Rejecting it with "must be a valid URL" for a
        // missing scheme made the form feel broken, so it is normalised before
        // validation rather than bounced back.
        $request->merge(['store_url' => self::normalizeUrl($request->input('store_url'))]);

        $shop = CurrentShop::resolve($request);
        $existing = $shop->woocommerceConnection;

        $data = $request->validate([
            'store_url' => ['required', 'url'],
            // Optional ONLY when we already hold a key/secret. Requiring them
            // meant a merchant who wanted to correct a typo in their store URL
            // had to go back to WordPress and generate a whole new key pair,
            // because the app never shows the secret it already has.
            'consumer_key' => [$existing ? 'nullable' : 'required', 'string'],
            'consumer_secret' => [$existing ? 'nullable' : 'required', 'string'],
        ]);

        $storeUrl = rtrim($data['store_url'], '/');
        // `??` as well as `?:` — a nullable field the client omitted entirely is
        // absent from the validated data, not present-and-empty.
        $key = ($data['consumer_key'] ?? null) ?: $existing?->consumer_key;
        $secret = ($data['consumer_secret'] ?? null) ?: $existing?->consumer_secret;

        abort_if(! $key || ! $secret, 422, 'Enter the consumer key and secret.');

        // TEST BEFORE SAVING.
        //
        // This used to write the credentials first and test afterwards, so one
        // typo permanently destroyed a working connection: the old key was gone,
        // the new one did not work, and the only way back was to generate a fresh
        // pair in WordPress. Nothing is persisted now until the store answers.
        $candidate = new WoocommerceConnection([
            'store_url' => $storeUrl,
            'consumer_key' => $key,
            'consumer_secret' => $secret,
        ]);

        $result = (new WooCommerceClient($candidate))->test();

        if (! $result['ok'] && $existing) {
            return response()->json(
                $this->present($existing) + [
                    'error' => $result['error'],
                    // Said explicitly, because "it failed" and "it failed and I
                    // also threw away what was working" are very different things
                    // to a merchant staring at this screen.
                    'kept_existing' => true,
                ],
                422,
            );
        }

        $connection = WoocommerceConnection::updateOrCreate(
            ['shop_id' => $shop->id],
            [
                'store_url' => $storeUrl,
                'consumer_key' => $key,
                'consumer_secret' => $secret,
                'status' => $result['ok'] ? 'connected' : 'error',
                'wc_version' => $result['wc_version'],
                'wp_version' => $result['wp_version'],
                'last_tested_at' => now(),
            ],
        );

        return response()->json(
            $this->present($connection->refresh()) + ['error' => $result['error']],
            $result['ok'] ? 200 : 422,
        );
    }

    /** Accept "mystore.com" as readily as "https://mystore.com/". */
    private static function normalizeUrl(mixed $url): string
    {
        $url = trim((string) $url);

        return $url !== '' && ! preg_match('~^https?://~i', $url) ? 'https://'.$url : $url;
    }

    /**
     * The stored connection, exactly as last recorded. No network call.
     *
     * The page used to load itself by running a live `test()`, which meant two
     * problems at once: opening it cost up to two minutes on a store that had
     * gone away, and a failed test answers 422 — so the client threw it away and
     * rendered a blank "connect your store" form at a merchant whose store was
     * connected. They could see neither their own store URL nor the fact that
     * their credentials had stopped working.
     */
    public function connection(Request $request): JsonResponse
    {
        $shop = CurrentShop::resolve($request);
        $connection = $shop->woocommerceConnection;

        if (! $connection) {
            // The literal `null`, spelled out. `response()->json(null)` does NOT
            // send it: Symfony's JsonResponse does `$data ??= new \ArrayObject()`,
            // so a null payload goes out as `{}`. The client read that empty
            // object as a real connection, took `store_url` off it (undefined) and
            // crashed the whole page — meaning every merchant who had not yet
            // connected a store, i.e. exactly the people this form exists for,
            // could never reach it.
            return new JsonResponse('null', 200, [], 0, true);
        }

        return response()->json($this->present($connection));
    }

    /** Re-test the existing connection. */
    public function test(Request $request): JsonResponse
    {
        $shop = CurrentShop::resolve($request);
        $connection = $shop->woocommerceConnection;

        abort_unless($connection, 404, 'No WooCommerce connection');

        return $this->runTest($connection);
    }

    /** Available counts per entity, for the preview/wizard. */
    public function preview(Request $request): JsonResponse
    {
        $shop = CurrentShop::resolve($request);
        $connection = $shop->woocommerceConnection;
        abort_unless($connection, 404, 'No WooCommerce connection');

        $registry = app(MigratorRegistry::class);
        $plan = $shop->currentPlan();

        // Why every count came back as zero, when that is what happened. A store
        // whose API keys have expired answers 401 to everything, and reporting
        // that as "0 products, 0 customers" sent merchants looking for a bug in
        // their catalogue instead of at their credentials.
        $sourceError = null;

        // Only entities that actually perform work. Variants, images and
        // attributes migrate INLINE with products, so counting them separately
        // double-counted the estimate and created pointless batch jobs.
        $display = ['product', 'category', 'customer', 'order', 'coupon', 'blog', 'article', 'review'];
        $available = [];
        $unreadable = [];

        // Core entities that answered, and core entities that were refused. Both
        // are needed: "could not read ONE thing" and "could not read ANYTHING"
        // are different problems with different fixes, and only the second is a
        // connection problem.
        $coreRead = 0;
        $blocked = [];

        foreach ($display as $type) {
            try {
                $available[$type] = $registry->make($type)->count($connection);

                if (in_array($type, self::CORE_ENTITIES, true)) {
                    $coreRead++;
                }
            } catch (\Throwable $e) {
                $available[$type] = 0;

                // Whatever the merchant is shown, the operator gets the real one.
                Log::warning('WooCommerce count failed during preview', [
                    'shop_id' => $shop->id,
                    'entity' => $type,
                    'status' => $e instanceof WooCommerceUnavailableException ? $e->status : null,
                    'message' => $e->getMessage(),
                ]);

                // Only a CORE entity failing can mean the store itself is
                // unreadable.
                //
                // Blogs, posts and pages come from `wp/v2`, which a great many
                // hosts lock down or hide behind a security plugin — and those
                // answer 404, which count() correctly reports as a failure. Left
                // undistinguished, a perfectly healthy store whose products and
                // orders counted fine got a red "your store could not be read"
                // banner because its blog endpoint was private.
                if (in_array($type, self::CORE_ENTITIES, true)) {
                    $blocked[] = [
                        'type' => $type,
                        'status' => $e instanceof WooCommerceUnavailableException ? $e->status : null,
                        'reason' => $e->getMessage(),
                    ];
                } else {
                    $unreadable[] = $type;
                }
            }
        }

        // THE STORE IS ONLY "UNREADABLE" WHEN NOTHING CORE COULD BE READ.
        //
        // This used to fire on the FIRST core entity that threw, so a store whose
        // products, categories and customers all counted perfectly was shown a red
        // "Your WooCommerce store could not be read — check that the API key and
        // secret are still valid" because one endpoint answered 403. A 403 on one
        // resource while four others answer is not a credentials problem by
        // definition: the same key just read four things.
        //
        // Reported by merchants with a migration RUNNING AT THE TIME, which is the
        // clearest possible disproof of the message they were being shown — hence
        // the second guard below.
        $activeMigrationId = MigrationController::activeMigrationId($shop);

        if ($coreRead === 0 && $blocked !== []) {
            $sourceError = $blocked[0]['reason'];
        }

        // A run that has actually MIGRATED something is live proof the credentials
        // work and the store answers, so nothing here may claim otherwise however
        // a background count went. The refusals are still reported, just not as a
        // connection failure.
        //
        // Progress, not merely status. A migration sitting at "processing" proves
        // nothing on its own — a run whose API keys expired underneath it is stuck
        // in exactly that state, and suppressing the explanation there would be
        // the same bug pointing the other way.
        if ($sourceError !== null && $activeMigrationId !== null) {
            $hasProgress = MigrationEntity::where('migration_id', $activeMigrationId)
                ->where('processed_items', '>', 0)
                ->exists();

            if ($hasProgress) {
                Log::info('Suppressed a source_error contradicted by a migration in progress', [
                    'shop_id' => $shop->id,
                    'migration_id' => $activeMigrationId,
                    'suppressed' => $sourceError,
                ]);

                $sourceError = null;
            }
        }

        // The shared record budget (see App\Support\PlanPool), spent in DEPENDENCY
        // order — the same order StartMigrationJob will spend it in. Claiming in
        // display order instead would hand the budget to a different entity here
        // than at run time, and the wizard would promise numbers the run then
        // contradicted.
        //
        // This assumes the merchant migrates everything. Selecting fewer entities
        // only ever frees budget up, so the preview under-promises rather than
        // over-promises, which is the safe direction to be wrong in.
        $pool = PlanPool::for($shop);

        // Snapshot BEFORE the claims below spend it. To a merchant "remaining"
        // means how much of their budget is still unspent right now — reporting
        // the state after a hypothetical full allocation would show them a number
        // that only exists because we just pretended to migrate everything.
        $poolSnapshot = $pool->isActive() ? $pool->toArray() : null;

        $verdicts = [];

        foreach ($registry->order($display) as $type) {
            $verdicts[$type] = PlanAllowance::forEntity($shop, $type, $available[$type], $pool);
        }

        // How hard this shop's own Shopify budget lets us push. Needed before the
        // counts, because each one carries its own share of the time estimate.
        $shopifyPlan = app(ShopifyPlan::class);
        $workers = $shopifyPlan->concurrencyFor($shop);
        // The order ceiling varies by tier now, so the wizard has to price the
        // estimate against THIS shop's tier rather than a default one.
        $tier = $shopifyPlan->tier($shop);

        // Ship the plan verdict with the count so the wizard can disable what the
        // merchant cannot migrate instead of failing after they start.
        //
        // `estimated_seconds` is per entity as well as in total, because the
        // wizard's summary has to answer for whatever the merchant ticked — and
        // this is computed before they have ticked anything.
        $counts = array_map(
            fn (string $type) => [
                'type' => $type,
                'count' => $available[$type],
                'estimated_seconds' => MigrationEstimate::secondsFor(
                    $type,
                    (int) ($verdicts[$type]['migratable'] ?? 0),
                    $workers,
                    $tier,
                ),
            ] + $verdicts[$type],
            $display,
        );

        // Roughly how long everything would take, so the merchant can decide
        // whether to wait or come back tomorrow. Built from what will ACTUALLY be
        // migrated — the plan-capped `migratable`, not the raw store counts.
        $estimate = MigrationEstimate::forPlan(
            array_column($counts, 'migratable', 'type'),
            $workers,
            $tier,
        );

        return response()->json([
            'counts' => $counts,
            'plan' => $plan ? ['code' => $plan->code, 'name' => $plan->name] : null,
            // {seconds, basis, workers}, or null when there is nothing to migrate.
            // `basis: plan` says out loud that this is modelled rather than
            // measured — the run's own live estimate replaces it within a minute.
            'estimate' => $estimate,
            // One shared allowance across products, customers and orders, or null
            // when this plan meters each entity on its own.
            'pool' => $poolSnapshot,
            // Prefills the "email me when it's done" field in the wizard.
            'shop_email' => $shop->email,
            // Whether that checkbox can deliver anything at all. Offering it on an
            // installation with no SMTP configured produces a merchant who waits
            // for an email that was never going to arrive.
            'email_delivery_configured' => MailSettings::canDeliver(),
            // The wizard refuses to start a second run while one is in flight, so
            // it needs to know before the merchant fills the whole form in.
            'active_migration_id' => $activeMigrationId,
            // Set ONLY when nothing core could be read at all — see above. A
            // single refused endpoint goes in `blocked_entities` instead, because
            // it is not a connection failure and must not be dressed as one.
            'source_error' => $sourceError,
            // Core entities this store would not count, while others counted
            // fine. Overwhelmingly a security plugin, WAF or rate limiter
            // refusing one path — so the wizard names the endpoint rather than
            // blaming credentials that are demonstrably working.
            'blocked_entities' => $blocked,
            // Optional endpoints this store does not expose (usually wp/v2 behind
            // a security plugin). Not an error — the merchant just cannot pick
            // those, and deserves to be told why rather than seeing a blank zero.
            'unavailable_entities' => $unreadable,
        ]);
    }

    private function runTest(WoocommerceConnection $connection): JsonResponse
    {
        $result = (new WooCommerceClient($connection))->test();

        $connection->update([
            'status' => $result['ok'] ? 'connected' : 'error',
            'wc_version' => $result['wc_version'],
            'wp_version' => $result['wp_version'],
            'last_tested_at' => now(),
        ]);

        // The 422 is what tells the client the credentials did not work; the body
        // still carries the connection AND the reason, so the page can say what
        // went wrong instead of silently rendering an empty form.
        return response()->json(
            $this->present($connection->refresh()) + ['error' => $result['error']],
            $result['ok'] ? 200 : 422,
        );
    }

    /** @return array<string, mixed> the connection, with the secret masked */
    private function present(WoocommerceConnection $connection): array
    {
        return [
            'store_url' => $connection->store_url,
            'consumer_key_masked' => $connection->consumer_key_masked,
            'status' => $connection->status,
            'wc_version' => $connection->wc_version,
            'wp_version' => $connection->wp_version,
            'last_tested_at' => $connection->last_tested_at,
        ];
    }
}
