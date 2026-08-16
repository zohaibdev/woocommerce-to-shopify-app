# Store Shift — Shopify App Store Listing Content

Copy-paste ready. Character counts are shown as `(n/limit)` so you can see the
headroom before Shopify truncates anything.

---

# 1. Basic app information

### App name (30 max)
```
Store Shift
```
`(11/30)`

**Backup options if taken:**
```
Store Shift ‑ Woo Migration
Store Shift Migration
```

### App icon
1200 × 1200 px, no text, no screenshots, transparent or solid background.
Suggested: the `⇄` arrows mark on the deep-navy → teal gradient already used in
the app (`#2d3561` → `#0f7b6c`).

### App URL
```
https://shiftshop.tech/app/api/auth
```

### Preferred app category
```
Store management  →  Migration / Data import
```

### Languages
```
English
```

---

# 2. App store listing content

### App introduction (100 max)
> One sentence, shown right under the name. No feature lists, no "the best".

```
Move your WooCommerce store to Shopify — products, orders, content and more, without duplicates.
```
`(96/100)`

**Alternatives:**
```
Migrate your WooCommerce products, customers, orders and content into Shopify safely.
```
`(85/100)`
```
Bring your whole WooCommerce store to Shopify — catalog, customers, orders and content.
```
`(87/100)`

---

### App details (500 max)
> What it does and who it's for. Plain language, no marketing fluff.

```
Store Shift moves your WooCommerce store into Shopify — no spreadsheets.

Connect your API keys, pick what to migrate, and it runs in the background — close the tab and come back later.

Products with variants, prices, SKUs, barcodes, inventory and images; categories as collections; customers with addresses; orders with tax and discounts; plus coupons, blog posts and reviews, keeping your SEO copy and URL slugs.

Every record is mapped, so re-running never duplicates anything.
```
`(481/500)`

---

### Feature list (5 features, 80 chars each)

```
1. Products with variants, prices, SKUs, barcodes, inventory, weight and images
```
`(76/80)`
```
2. Categories become collections and WooCommerce coupons become discount codes
```
`(75/80)`
```
3. Blogs, blog posts and product reviews arrive with their SEO and URLs
```
`(68/80)`
```
4. Runs in the background — close the browser and your migration keeps going
```
`(73/80)`
```
5. Safe to re-run: every record is mapped, so nothing is ever duplicated
```
`(69/80)`

> Metafields and brands dropped out of the top 5 — they are a paid-plan detail
> rather than a reason to install, and content migration is the bigger
> differentiator now. They are still covered in App details and the FAQ.

---

### Demo store URL (optional)
Leave blank unless you set up a public demo. If you do, use a development store
with sample migrated data.

---

### Screenshots
Minimum 3, 1600 × 900 px, taken inside the Shopify admin (embedded).

| # | Screen | Caption (shown under the image) |
|---|---|---|
| 1 | Dashboard with stat tiles + progress | `See your whole catalog at a glance before you migrate` |
| 2 | New migration wizard, data selected | `Choose exactly what to migrate — and preview the counts` |
| 3 | Migration in progress, progress bars | `Migrations run in the background with live progress` |
| 4 | Migration details + failed items | `Every failure is explained and can be retried on its own` |
| 5 | Pricing page | `Start free, upgrade only when you need more` |

> Tip: take these at 1280px wide inside the admin, then scale to 1600×900.
> Do not include browser chrome or your cursor.

---

### Onboarding / integration
```
1. Install Store Shift from the Shopify App Store.
2. In WooCommerce, go to Settings → Advanced → REST API and create a key with Read access.
3. Paste your store URL, consumer key and consumer secret into Store Shift, then click Test connection.
4. Choose the data you want to migrate and start. Progress updates live, and you can close the tab.
```

---

### Support

| Field | Value |
|---|---|
| Support email | `support@webnday.com` *(set this up before submitting)* |
| Support / help URL | `https://shiftshop.tech/contact/` |
| Privacy policy URL | `https://shiftshop.tech/privacy/` ✅ live |
| Terms of service URL | `https://shiftshop.tech/terms/` ✅ live |
| Refund policy URL | `https://shiftshop.tech/refund/` ✅ live |
| Documentation URL | `https://shiftshop.tech/developers/` ✅ live |
| FAQ URL | `https://shiftshop.tech/#faq` |

---

# 3. Pricing details

> These must match the plans configured in the app — Shopify checks the listing
> against what the Billing API actually offers. The live source of truth is the
> `plans` table, editable at `/app/admin/plans`; re-seeding no longer overwrites
> what an admin has set there, so **check the admin panel before pasting these.**

**Plan 1 — Starter**
```
Free
- 10 products
- 10 customers
- Categories, variants and images
- Background migrations
```
> Starter deliberately excludes orders (`orders: 0`), metafields and brands, and
> all three content features (`coupons`, `blogs`, `reviews` = 0). The wizard
> disables each one and says which plan covers it.

**Plan 2 — Basic — $19/month**
```
- 2,000 products
- 2,000 customers
- 1,000 orders
- Coupons, blogs, blog posts and reviews
- Metafields and brands
- Email support
```

**Plan 3 — Pro — $49/month**
```
- 20,000 records in total, shared across products, customers and orders
- Coupons, blogs, blog posts and reviews
- Metafields and brands
- Priority support
```
> ⚠️ **Do not write this as "20,000 products, 20,000 customers, 20,000 orders".**
> Pro is metered by a single shared budget (`limits.total`), so it is 20,000
> records spent however the merchant likes — not 20,000 of each. Re-migrating a
> record that was already migrated does not spend the budget again. Advertising
> three separate caps would be a promise the app deliberately does not keep.

**Plan 4 — Enterprise — Contact us**
```
- Unlimited products, customers and orders
- Coupons, blogs, blog posts and reviews
- Metafields and brands
- Priority support
```
> ⚠️ Enterprise is `contact_only` — `POST /api/billing/subscribe` **422s** on it and
> the app shows "Contact us" instead of an Upgrade button. Listing it at a
> purchasable `$149/month` describes a checkout that does not exist, which is the
> kind of mismatch app review rejects. Either list it as a contact-sales tier (as
> above), or clear `contact_only` in `/app/admin/plans` before submitting and
> confirm the Shopify charge screen actually appears. **Decide this before you
> paste the pricing section.**

> **Allowances reset every billing period.** Each plan's caps are per month,
> counted from the merchant's own subscription date rather than the 1st. Say so
> in the listing — "2,000 products per month" reads very differently from
> "2,000 products", and the second one is what merchants will assume if the
> pricing copy leaves it out.

**Pricing summary line:**
```
Free plan available. Paid plans from $19/month, billed through Shopify.
```

---

# 4. App discovery content

### App card subtitle (62 max)
> Shown on search/browse cards. Benefit-led, not a feature list.

```
Migrate WooCommerce to Shopify without duplicates
```
`(49/62)`

**Alternatives:**
```
Import your WooCommerce catalog into Shopify safely
```
`(51/62)`
```
WooCommerce to Shopify migration, done in the background
```
`(56/62)`

---

### Search terms (5 max, one per field)
```
woocommerce
migration
import products
store migration
data import
```

> Don't use competitor or brand names — Shopify rejects those.

---

### SEO title (60 max)
```
Store Shift — WooCommerce to Shopify Migration
```
`(46/60)`

### SEO description (160 max)
```
Migrate products, variants, images, collections, customers, orders, coupons and blog posts from WooCommerce to Shopify. Never creates duplicates.
```
`(145/160)`

---

# 5. Install requirements

### Install requirements
```
A WooCommerce store (WordPress 5.6+ with WooCommerce 5.0+) and REST API credentials with Read access.
```

### Integration requirements (optional)
```
WooCommerce REST API v3 must be reachable over HTTPS from the internet. Stores behind a firewall, password protection or an IP allowlist cannot be reached.

Blogs and blog posts additionally need the WordPress REST API (/wp-json/wp/v2/) to be public. Many security plugins block it — when that happens, everything else still migrates and the app says which content it could not read.
```

> That second paragraph is worth keeping: `wp/v2` being locked down is common,
> and the app now treats it as an info banner rather than a failure. Saying so up
> front turns a support ticket into an expectation.

---

# 6. Tracking information

Leave Google Analytics / Google Tag Manager blank unless you actually use them —
an invalid ID will fail review.

```
Google Analytics ID:  (leave blank)
Google Tag Manager:   (leave blank)
```

---

# 7. Contact information

| Field | Value |
|---|---|
| Emergency developer email | `zohaibiqbal.iqbal@gmail.com` |
| App submission contact | `zohaibiqbal.iqbal@gmail.com` |

> Shopify uses these to reach you about outages and review issues. Use an inbox
> you check daily.

---

# 8. App testing information ⚠️ most important section

> This is where most first submissions fail. The reviewer must be able to run a
> full migration without asking you anything.

### Test store / login instructions
```
Store Shift needs a WooCommerce store as the data source. We have prepared a test
store with sample products, variants, images, categories, customers, orders,
coupons and blog posts.

WooCommerce test store: https://YOUR-TEST-STORE.com
Consumer key:    ck_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx
Consumer secret: cs_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx

Steps to test:
1. Install the app on your development store.
2. Open the app. On the WooCommerce page, paste the store URL, consumer key and
   consumer secret above, then click "Test connection". It will show the
   WooCommerce and WordPress versions once connected.
3. Go to Migrations → New migration. Select Products and Categories.
   The wizard shows how many records are available in the source store.
4. Click Start migration. Progress updates every few seconds; the migration runs
   on a background queue, so you can close the tab and return.
5. When it finishes, open Shopify Products — the migrated products, variants,
   images and collections will be there, each keeping its original URL slug.
6. Re-run the same migration to confirm nothing is duplicated: existing products
   are updated in place.
7. Optional: start a second migration with Coupons and Blogs to see them arrive
   under Shopify Discounts and Online Store → Blog posts.

Notes for the reviewer:
- The free Starter plan is limited to 10 products and 10 customers, and does not
  include orders. To test orders or larger volumes, use the test charge on the
  Basic plan (test mode is enabled, so no real money is charged).
- Shopify has no built-in product reviews, so migrated reviews are stored on each
  product as a "woocommerce.reviews" metafield rather than shown on the
  storefront. The app states this in the wizard before the merchant selects it.
- Blogs and blog posts are read from the WordPress REST API
  (/wp-json/wp/v2/). If a store blocks it, the wizard says so in an info banner
  and everything else still migrates.
- Failed items are listed with the exact reason under the migration details page
  and can be retried individually.
```

### Billing test
```
Billing uses the Shopify Billing API with test charges enabled during review, so
no real charges are created. Select any paid plan on the Plans & pricing page to
see the standard Shopify approval screen.
```

---

# ✅ Before you hit submit

| Item | Why it matters |
|---|---|
| Privacy policy page is live | ✅ Done — `/privacy/`, alongside `/terms/`, `/refund/`, `/contact/`, `/developers/`. |
| Support email works | Reviewer may email it. `support@webnday.com` still unverified. |
| Protected Customer Data approved | Without it, customers **and** orders fail with ACCESS_DENIED. |
| `SHOPIFY_BILLING_TEST=false` before going live | Otherwise real merchants are never charged. |
| Pricing section matches the `plans` table | Especially Pro's shared budget and Enterprise's contact-only flag — see §3. |
| Queue worker cron is running | The app now starts its own worker off normal traffic, but a cron every minute is still far faster. Confirm `proc_open` is not disabled — the admin Overview says which case you are in. |
| `backend/.htaccess` deny-all is in place | `/app/backend/composer.json` was publicly readable. |
| A real WooCommerce test store with credentials | The reviewer cannot test the app without one. |
| GDPR webhooks respond 200 | Already implemented at `/app/api/webhooks/…` |
| Scopes include `write_content` + `write_discounts` | Needed for blogs/posts/coupons. Adding them forces every installed merchant to re-authorize on next load. |

---

## What Store Shift does *not* migrate

> Not a listing field — this is for the FAQ, the `/developers/` page and the
> reviewer notes. Every item here is something a merchant WILL hit, and finding
> out afterwards is what turns into a refund request and a one-star review.

| Not migrated | Why |
|---|---|
| Customer passwords | WooCommerce and Shopify use incompatible hashes. Customers set a new password on first login. |
| Static pages (About, Shipping, Returns) | Deliberately not offered. Shopify's own page editor handles the handful a store has in minutes, and a page import that only ever saw *published* content was more confusing than useful. |
| Product reviews on the storefront | Shopify has no native reviews API. Reviews are preserved as a `woocommerce.reviews` metafield; a review app or theme block is needed to display them. |
| Blog comments | Shopify's Admin API cannot create a comment with its original author or date. They are kept on the article as a `comments` metafield. |
| Draft and private WordPress content | The WooCommerce consumer key does not authenticate `wp/v2`, so only published posts are visible. |
| Order line items linked to products | Migrated orders use custom line items, so they do not reference the migrated variants. Deliberate: sending the SKU makes Shopify auto-create "Import placeholder" draft products. |
| Images inside product descriptions | `descriptionHtml` passes through as-is, so `<img>` tags still point at the WooCommerce URL and break when the old store goes offline. |
| Coupon restrictions Shopify cannot express | Email restrictions, excluded products/categories, "exclude sale items" and per-item usage caps. The code migrates **without** them and the record carries a warning saying so. |

---

## Still to do before submitting

1. **Create `support@webnday.com`.** Every public page, both policies
   and this listing point at it, and the reviewer may email it.

2. **A public WooCommerce test store** — the reviewer has no way to test
   otherwise. A cheap shared-host WordPress with the WooCommerce sample data
   import is enough.

3. **Protected Customer Data approval** — customers and orders fail until it is
   granted.

4. **Decide what Enterprise is.** It is currently `contact_only`, so the listing
   cannot advertise it as a $149/month plan a merchant can buy — see §3. Either
   list it as contact-sales, or clear the flag and verify the Shopify charge
   screen appears.

5. **Re-check §3 against `/app/admin/plans`.** Plan limits are DB-driven and an
   admin can change them without a deploy, so the listing can drift out of sync
   with what merchants are actually sold.

6. **Set `coupons` / `blogs` / `reviews` on the existing plans.** These keys are
   new, and an ABSENT key means unlimited so that plans created before they
   existed keep working. That is the safe default, but it also means the live
   database still gives free Starter shops unlimited coupons, blogs and reviews
   until someone sets them — in `/app/admin/plans`, or by re-seeding with
   `PLAN_SEED_OVERWRITE=true` (which resets every other limit too, so prefer the
   admin panel).

> Legal entity across all public pages: **Webnday Pvt. Ltd**, with Store Shift as
> its product. No registered address or named jurisdiction is published.
