import { useEffect, useState } from "react";
import {
  Page,
  Card,
  ChoiceList,
  Button,
  BlockStack,
  Text,
  Banner,
  InlineStack,
  Divider,
  TextField,
  Select,
  SkeletonBodyText,
  SkeletonDisplayText,
  Badge,
  Checkbox,
} from "@shopify/polaris";
import { useNavigate } from "react-router-dom";
import { api, type EntityCount, type PlanPool } from "../api/client";
import { formatDuration } from "../duration";
import { loadSettings } from "../settings";
import SecurityPluginWarning from "../components/SecurityPluginWarning";

// Variants, options and images are migrated INLINE with each product, so they
// are not separate choices — listing them created no-op jobs and inflated the
// estimate. The help text makes that explicit instead.
const ENTITY_OPTIONS = [
  {
    label: "Products",
    value: "product",
    detail: "Includes variants, options, prices, SKUs, inventory, weight, images and metafields",
  },
  { label: "Categories", value: "category", detail: "Become Shopify collections" },
  { label: "Customers", value: "customer", detail: "Profiles, billing and shipping addresses (passwords can't be migrated)" },
  { label: "Orders", value: "order", detail: "Historical orders with line items, tax, discounts and totals" },
  { label: "Coupons", value: "coupon", detail: "Become Shopify discount codes" },
  { label: "Blogs", value: "blog", detail: "Your post categories become Shopify blogs" },
  { label: "Blog posts", value: "article", detail: "Posts with their original publish dates and images" },
  {
    label: "Product reviews",
    value: "review",
    // Said here rather than after the fact: Shopify has no built-in reviews, so
    // a merchant who ticks this and then sees no stars on their storefront would
    // reasonably think the migration failed.
    detail: "Saved on each product as data — needs a review app or theme block to display",
  },
];

const WEIGHT_UNITS = [
  { label: "Kilograms (kg)", value: "KILOGRAMS" },
  { label: "Grams (g)", value: "GRAMS" },
  { label: "Pounds (lb)", value: "POUNDS" },
  { label: "Ounces (oz)", value: "OUNCES" },
];

/**
 * Shopify downloads every image itself and the product write waits for it, so
 * image size is the biggest single lever a merchant has over how long a run
 * takes. WordPress has already generated the smaller copies, so "standard" costs
 * nothing but the pixels a storefront never displays anyway.
 */
const IMAGE_SIZES = [
  { label: "Standard — up to 1024px (recommended)", value: "standard" },
  { label: "Large — up to 1536px", value: "large" },
  { label: "Original — full-size files", value: "full" },
];

const IMAGE_SIZE_HELP: Record<string, string> = {
  standard: "Uses the resized copies WordPress already made. Fastest, and large enough for most storefronts.",
  large: "Higher quality than standard. Migration will take somewhat longer.",
  full: "Highest quality, and the slowest option by a wide margin.",
};

const PREFERRED_DEFAULTS = ["product", "category"];

/** Nothing to migrate, or the plan won't allow it. */
function isSelectable(entity?: EntityCount): boolean {
  return !!entity && !entity.locked && !entity.exhausted && entity.count > 0;
}

/** True when the plan is the reason this entity can't be chosen. */
function isBlockedByPlan(entity?: EntityCount): boolean {
  return !!entity && (entity.locked || entity.exhausted);
}

export default function NewMigration() {
  const navigate = useNavigate();
  const [step, setStep] = useState<1 | 2>(1);
  const prefs = loadSettings();
  const [name, setName] = useState("");
  const [selected, setSelected] = useState<string[]>([]);
  const [customTags, setCustomTags] = useState(prefs.defaultTags);
  const [defaultVendor, setDefaultVendor] = useState(prefs.defaultVendor);
  const [weightUnit, setWeightUnit] = useState(prefs.weightUnit);
  const [imageSize, setImageSize] = useState(prefs.imageSize);
  const [rehostImages, setRehostImages] = useState(false);
  const [counts, setCounts] = useState<EntityCount[]>([]);
  const [planName, setPlanName] = useState<string | null>(null);
  const [pool, setPool] = useState<PlanPool | null>(null);
  const [unavailable, setUnavailable] = useState<string[]>([]);
  const [notifyMe, setNotifyMe] = useState(prefs.emailNotifications);
  const [notifyEmail, setNotifyEmail] = useState("");
  const [emailDeliveryConfigured, setEmailDeliveryConfigured] = useState(true);
  const [sourceError, setSourceError] = useState<string | null>(null);
  const [loadingCounts, setLoadingCounts] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);

  useEffect(() => {
    api
      .preview()
      .then(({
        counts, plan, pool, shop_email, email_delivery_configured,
        active_migration_id, source_error, unavailable_entities,
      }) => {
        // One run at a time — the API refuses a second with a 409, so there is
        // nothing to be gained by letting the merchant fill the form in first.
        if (active_migration_id) {
          navigate(`/migrations/${active_migration_id}`, { replace: true });

          return;
        }

        setCounts(counts);
        setSourceError(source_error ?? null);
        setUnavailable(unavailable_entities ?? []);
        setPlanName(plan?.name ?? null);
        setPool(pool ?? null);
        setEmailDeliveryConfigured(email_delivery_configured !== false);
        if (shop_email) setNotifyEmail(shop_email);

        // Ticking "email me" on an installation with no mail server produces a
        // merchant waiting for something that was never going to be sent.
        if (email_delivery_configured === false) setNotifyMe(false);

        // Only pre-tick what the merchant can actually run — a checkbox that is
        // on by default but rejected on submit is worse than one that is off.
        setSelected(
          PREFERRED_DEFAULTS.filter((type) =>
            isSelectable(counts.find((c) => c.type === type)),
          ),
        );
      })
      // The wizard is unusable without counts; saying so beats an empty form
      // whose checkboxes are all mysteriously disabled.
      .catch((e) => setError((e as Error).message))
      .finally(() => setLoadingCounts(false));
  }, [navigate]);

  const gate = (type: string) => counts.find((c) => c.type === type);

  // Orders reference customers, so pull customers in automatically — but never
  // add an entity the plan blocks.
  // Dependencies the backend also enforces (MigrationController::IMPLIED_ENTITIES).
  // Mirrored here so the merchant SEES what will run before they start, instead of
  // the server quietly adding entities the summary never mentioned.
  //
  // blog -> article is the one that is not a hard dependency but is always what
  // the merchant means: picking "Blogs" on its own creates empty blogs in Shopify
  // with none of the posts in them, which reads as the feature being broken.
  const IMPLIED: Record<string, string> = {
    order: "customer",
    review: "product",
    article: "blog",
    blog: "article",
  };

  const enforceDeps = (values: string[]) => {
    const next = [...values];

    for (const [selected, required] of Object.entries(IMPLIED)) {
      if (next.includes(selected) && !next.includes(required) && isSelectable(gate(required))) {
        next.push(required);
      }
    }

    setSelected(next);
  };

  /**
   * Reviews are stored as a metafield ON a product, so without products there is
   * nothing for them to attach to — every review would be recorded "the product
   * was not migrated" and the merchant would have spent a run finding that out.
   *
   * Normally ticking Reviews ticks Products too (IMPLIED above). The gap is a
   * plan that does not include products at all: the implication is deliberately
   * skipped there rather than refusing the run over an entity the merchant never
   * chose, which used to leave Reviews selectable and useless. The backend
   * refuses the same combination, so this is the explanation rather than the
   * enforcement.
   */
  const reviewsNeedProducts = !isSelectable(gate("product"));

  const migratableFor = (type: string) => gate(type)?.migratable ?? 0;
  const estimated = selected.reduce((sum, t) => sum + migratableFor(t), 0);

  // Summed from only what the merchant ticked, rather than the preview's
  // all-entities figure — a summary that quotes a time for entities they left
  // out is worse than no time at all.
  const estimatedSeconds = selected.reduce(
    (sum, t) => sum + (gate(t)?.estimated_seconds ?? 0),
    0,
  );

  const blockedByPlan = ENTITY_OPTIONS.filter((o) => isBlockedByPlan(gate(o.value)));

  // "Capped" is anything the plan will not migrate in full, whether that is this
  // entity's own limit or the shared budget being spent by another entity first.
  const cappedByPlan = ENTITY_OPTIONS.filter((o) => {
    const entity = gate(o.value);
    return isSelectable(entity) && entity!.migratable < entity!.count;
  });

  const helpTextFor = (option: (typeof ENTITY_OPTIONS)[number]) => {
    const entity = gate(option.value);
    if (!entity) return option.detail;

    const available = entity.count.toLocaleString();

    if (entity.locked) {
      return `Not included in your ${planName ?? "current"} plan — upgrade to migrate ${available}`;
    }
    if (entity.count === 0) {
      // Blogs, blog posts and reviews come from endpoints many WordPress hosts
      // hide behind a security plugin. "None found" then reads as "you have
      // none", which sends the merchant looking for the wrong problem.
      return unavailable.includes(option.value)
        ? "Your host is not exposing this WordPress endpoint, so it can't be read"
        : `None found in WooCommerce — ${option.detail}`;
    }
    // Re-running after a partial or failed run is the common case, and a merchant
    // who cannot see what already landed assumes they are paying for it twice.
    // They are not: the plan counts DISTINCT records, so anything already
    // migrated is free to migrate again.
    if (entity.used > 0) {
      const already = `${entity.used.toLocaleString()} already migrated (re-running these is free)`;

      return entity.migratable < entity.count
        ? `${already} · ${entity.migratable.toLocaleString()} of ${available} will migrate on your ${planName ?? "current"} plan`
        : `${already} · ${available} available`;
    }
    // A pooled entity's number is decided by the shared budget and by what the
    // other entities took first, which this row cannot explain on its own — so
    // use the sentence the server built with the whole picture in view.
    if (entity.pooled && entity.reason) {
      return entity.reason;
    }
    if (entity.exhausted) {
      return `Plan limit reached — you've migrated all ${entity.limit?.toLocaleString()} your plan allows, and ${available} exist in WooCommerce`;
    }
    if (entity.migratable < entity.count) {
      return `${entity.migratable.toLocaleString()} of ${available} will migrate on your ${planName ?? "current"} plan — upgrade for the rest`;
    }
    return `${available} available — ${option.detail}`;
  };

  /**
   * "Email me when it's done" is worth nothing without a valid address, and the
   * backend cannot tell an empty field from a merchant who did not want one — so
   * a blank box used to submit happily and simply never send anything.
   */
  const notifyEmailError =
    notifyMe && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(notifyEmail.trim())
      ? "Enter the email address the completion notice should go to."
      : undefined;

  const start = async () => {
    if (notifyEmailError) {
      setStep(1);
      setError(notifyEmailError);

      return;
    }

    setStarting(true);
    setError(null);
    try {
      const tags = customTags.split(",").map((t) => t.trim()).filter(Boolean);
      const migration = await api.createMigration({
        entities: selected,
        customTags: tags,
        defaultVendor: defaultVendor.trim(),
        name: name.trim(),
        weightUnit,
        imageSize,
        rehostDescriptionImages: rehostImages,
        notifyEmail: notifyMe ? notifyEmail.trim() : "",
      });
      navigate(`/migrations/${migration.id}`);
    } catch (e) {
      setError((e as Error).message);
      setStarting(false);
    }
  };

  return (
    <Page title="New migration" backAction={{ content: "Migrations", url: "/migrations" }}>
      <BlockStack gap="400">
        {error && (
          <Banner tone="critical" title="Could not start migration">
            {error}
          </Banner>
        )}

        {/*
          Every entity showing zero is indistinguishable from an empty store
          until you say why. Expired WooCommerce API keys answer 401 to
          everything, and that is what this is nearly always reporting.
        */}
        {!loadingCounts && sourceError && (
          <Banner
            tone="critical"
            title="Your WooCommerce store could not be read"
            action={{ content: "Check connection", onAction: () => navigate("/woocommerce") }}
          >
            <Text as="p">{sourceError}</Text>
          </Banner>
        )}

        {/*
          A shared budget cannot be understood from any single entity's row: the
          merchant needs to see the one number and what they have left of it,
          otherwise "200 of 800 products" looks like an arbitrary cap rather than
          the consequence of what customers took first.
        */}
        {!loadingCounts && !sourceError && pool && (
          <Banner
            tone={pool.remaining === 0 ? "warning" : "info"}
            title={
              pool.remaining === 0
                ? `You've used your whole ${planName ?? "current"} plan allowance`
                : `${pool.remaining.toLocaleString()} of ${pool.limit.toLocaleString()} records left on your ${planName ?? "current"} plan`
            }
            action={{ content: "View plans", onAction: () => navigate("/pricing") }}
            secondaryAction={{
              content: "Contact support",
              onAction: () => navigate("/support"),
            }}
          >
            <BlockStack gap="100">
              <Text as="p">
                Your plan covers {pool.limit.toLocaleString()} records in total across products,
                customers and orders — not {pool.limit.toLocaleString()} of each. You've migrated{" "}
                {pool.used.toLocaleString()} so far. Re-migrating something you have already
                migrated does not use any more of it.
              </Text>
              <Text as="p" tone="subdued" variant="bodySm">
                Need more than {pool.limit.toLocaleString()}? Talk to support about an Enterprise
                plan — it is arranged directly with us rather than through Shopify billing.
              </Text>
            </BlockStack>
          </Banner>
        )}

        {/*
          wp/v2 (blogs, posts, pages) is locked down on a great many WordPress
          hosts. That is not a broken store and must not read as one — but a
          merchant staring at "0 pages" on a site with twenty of them deserves to
          know it is their host, not their data.
        */}
        {!loadingCounts && !sourceError && unavailable.length > 0 && (
          <Banner tone="info" title="Some content could not be read from WordPress">
            <Text as="p">
              Your host does not expose the WordPress endpoints for{" "}
              {unavailable
                .map((t) => ENTITY_OPTIONS.find((o) => o.value === t)?.label.toLowerCase() ?? t)
                .join(", ")}
              , so those cannot be migrated. Everything else on this page is unaffected. This is
              usually a security plugin or a firewall blocking <code>/wp-json/wp/v2/</code>.
            </Text>
          </Banner>
        )}

        {/* Before the run, not in the failure list afterwards — by then the
            merchant has already lost hours to it. */}
        {!loadingCounts && <SecurityPluginWarning />}

        {/*
          Shown as soon as orders are ticked, not buried in the run's log after
          the fact — by then the merchant's inbox already has a thousand emails
          in it and the only remaining question is why we did not say so.

          Two different notifications, and merchants conflate them: the CUSTOMER
          order confirmation is suppressed by the app (orderCreate is sent with
          sendReceipt: false), but Shopify's own "New order" staff notification
          is a store setting with no Admin API surface at all. We cannot turn it
          off for them, so the honest thing is to name the exact setting.
        */}
        {selected.includes("order") && (
          <Banner tone="warning" title="Turn off your “New order” staff notification first">
            <BlockStack gap="100">
              <Text as="p">
                Your customers will <Text as="span" fontWeight="semibold">not</Text> be emailed —
                order confirmations are suppressed for every migrated order.
              </Text>
              <Text as="p">
                But Shopify emails <Text as="span" fontWeight="semibold">you</Text> a “New order”
                staff notification for each one, and no app can change that setting. Before
                starting, go to Shopify admin → Settings → Notifications → Staff notifications and
                turn off “New order”. Turn it back on when the migration finishes.
              </Text>
            </BlockStack>
          </Banner>
        )}

        {!loadingCounts && !sourceError && blockedByPlan.length > 0 && (
          <Banner
            tone="warning"
            title={`Your ${planName ?? "current"} plan doesn't cover everything in this store`}
            action={{ content: "View plans", onAction: () => navigate("/pricing") }}
          >
            <BlockStack gap="100">
              {blockedByPlan.map((o) => (
                <Text as="p" key={o.value}>
                  <Text as="span" fontWeight="semibold">
                    {o.label}
                  </Text>
                  {" — "}
                  {gate(o.value)?.reason}
                </Text>
              ))}
            </BlockStack>
          </Banner>
        )}

        {!loadingCounts && blockedByPlan.length === 0 && cappedByPlan.length > 0 && (
          <Banner
            tone="info"
            title="Some data exceeds your plan limit"
            action={{ content: "View plans", onAction: () => navigate("/pricing") }}
          >
            <BlockStack gap="100">
              {cappedByPlan.map((o) => (
                <Text as="p" key={o.value}>
                  <Text as="span" fontWeight="semibold">
                    {o.label}
                  </Text>
                  {" — "}
                  {gate(o.value)?.reason}
                </Text>
              ))}
            </BlockStack>
          </Banner>
        )}

        {step === 1 && (
          <Card>
            <BlockStack gap="400">
              <TextField
                label="Migration name (optional)"
                value={name}
                onChange={setName}
                placeholder="e.g. Full store import"
                autoComplete="off"
              />
              <Divider />
              <InlineStack align="space-between" blockAlign="center">
                <Text as="h2" variant="headingMd">
                  Select data to migrate
                </Text>
                {planName && <Badge>{`${planName} plan`}</Badge>}
              </InlineStack>

              {loadingCounts ? (
                <BlockStack gap="300">
                  <SkeletonDisplayText size="small" />
                  <SkeletonBodyText lines={6} />
                  <Text as="span" tone="subdued">
                    Reading your WooCommerce store…
                  </Text>
                </BlockStack>
              ) : (
                <ChoiceList
                  allowMultiple
                  title=""
                  titleHidden
                  choices={ENTITY_OPTIONS.map((o) => ({
                    label: o.label,
                    value: o.value,
                    helpText:
                      o.value === "review" && reviewsNeedProducts
                        ? "Reviews are saved onto the products they belong to, so Products must be migrated as well"
                        : helpTextFor(o),
                    // Off AND unselectable when the plan blocks it — the merchant
                    // is told why in the banner above rather than at submit time.
                    disabled:
                      !isSelectable(gate(o.value)) ||
                      (o.value === "review" && reviewsNeedProducts),
                  }))}
                  selected={selected}
                  onChange={enforceDeps}
                />
              )}

              <Divider />
              <Select
                label="Weight unit in WooCommerce"
                options={WEIGHT_UNITS}
                value={weightUnit}
                onChange={setWeightUnit}
                helpText="WooCommerce doesn't report its unit, so pick the one your store uses — product weights are imported with it."
              />
              <BlockStack gap="200">
                <Select
                  label="Image size"
                  options={IMAGE_SIZES}
                  value={imageSize}
                  onChange={setImageSize}
                  helpText={IMAGE_SIZE_HELP[imageSize]}
                />
                {imageSize === "full" && (
                  <Banner tone="warning">
                    <Text as="p" variant="bodySm">
                      Shopify downloads every image from your store before a product is saved, so
                      full-size files can make this migration several times slower — and much slower
                      still if your images are large camera originals. Choose Standard unless you
                      specifically need the original files.
                    </Text>
                  </Banner>
                )}
              </BlockStack>

              <Checkbox
                label="Also copy images inside descriptions to Shopify"
                checked={rehostImages}
                onChange={setRehostImages}
                helpText="Product and blog descriptions often contain their own images — size charts, close-ups — which stay hosted on your WooCommerce store. Turn this on to copy them to Shopify as well, so they keep working after you take the old store offline. It makes the migration slower, and you only need it if you plan to shut WooCommerce down."
              />

              <TextField
                label="Custom tags (optional)"
                value={customTags}
                onChange={setCustomTags}
                placeholder="e.g. migrated, from-woo"
                helpText="Comma-separated. Added to every migrated product and customer."
                autoComplete="off"
              />
              <TextField
                label="Default vendor (optional)"
                value={defaultVendor}
                onChange={setDefaultVendor}
                placeholder="e.g. My Store"
                helpText="Vendor for products with no brand in WooCommerce. Blank = Shopify uses the shop name."
                autoComplete="off"
              />
              <Divider />
              <BlockStack gap="200">
                <Checkbox
                  label="Email me when this migration finishes"
                  checked={notifyMe}
                  onChange={setNotifyMe}
                  disabled={!emailDeliveryConfigured}
                  helpText={
                    emailDeliveryConfigured
                      ? "Large migrations can take hours — you don't have to keep this page open."
                      : "Email notifications are not available on this installation."
                  }
                />
                {!emailDeliveryConfigured && (
                  <Banner tone="info">
                    <Text as="p" variant="bodySm">
                      This app has no email server configured, so no completion notice can be sent.
                      Your migration will run normally — the progress page keeps updating on its own,
                      and closing it does not stop the run.
                    </Text>
                  </Banner>
                )}
                {notifyMe && emailDeliveryConfigured && (
                  <TextField
                    label="Send to"
                    type="email"
                    value={notifyEmail}
                    onChange={setNotifyEmail}
                    placeholder="you@example.com"
                    autoComplete="email"
                    error={notifyEmail.trim() !== "" ? notifyEmailError : undefined}
                  />
                )}
              </BlockStack>
              <InlineStack align="end">
                <Button
                  variant="primary"
                  disabled={selected.length === 0 || loadingCounts || !!notifyEmailError}
                  onClick={() => setStep(2)}
                >
                  Continue
                </Button>
              </InlineStack>
            </BlockStack>
          </Card>
        )}

        {step === 2 && (
          <Card>
            <BlockStack gap="300">
              <Text as="h2" variant="headingMd">
                {name.trim() ? `Summary — ${name.trim()}` : "Migration summary"}
              </Text>
              <Divider />
              {selected.map((t) => {
                const entity = gate(t);
                const capped = entity && entity.limit !== null && entity.count > entity.limit;

                return (
                  <InlineStack key={t} align="space-between">
                    <Text as="span">{ENTITY_OPTIONS.find((o) => o.value === t)?.label ?? t}</Text>
                    <Text as="span" tone={capped ? "caution" : "subdued"}>
                      {capped
                        ? `${migratableFor(t).toLocaleString()} of ${entity!.count.toLocaleString()}`
                        : migratableFor(t).toLocaleString()}
                    </Text>
                  </InlineStack>
                );
              })}
              <Divider />
              <InlineStack align="space-between">
                <Text as="span" fontWeight="bold">
                  Estimated items
                </Text>
                <Text as="span" fontWeight="bold">
                  {estimated.toLocaleString()}
                </Text>
              </InlineStack>
              <InlineStack align="space-between">
                <Text as="span" fontWeight="bold">
                  Estimated time
                </Text>
                <Text as="span" fontWeight="bold">
                  {estimatedSeconds > 0 ? formatDuration(estimatedSeconds) : "—"}
                </Text>
              </InlineStack>
              {estimatedSeconds > 0 && (
                <Text as="p" tone="subdued" variant="bodySm">
                  A rough figure based on how many records you have. Most of the time goes on
                  Shopify downloading your images, so a store with large photos takes longer —
                  choosing a smaller image size above is the quickest way to speed this up. Once the
                  migration starts you&rsquo;ll see a live estimate from its actual pace. You can
                  close this page at any time; the migration keeps running.
                </Text>
              )}
              <InlineStack align="space-between">
                <Button onClick={() => setStep(1)}>Back</Button>
                <Button variant="primary" loading={starting} onClick={start}>
                  Start migration
                </Button>
              </InlineStack>
            </BlockStack>
          </Card>
        )}
      </BlockStack>
    </Page>
  );
}
