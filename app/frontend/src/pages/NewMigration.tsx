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
import { api, type EntityCount } from "../api/client";
import { loadSettings } from "../settings";

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
  { label: "Customers", value: "customer", detail: "Profiles and addresses (passwords can't be migrated)" },
  { label: "Orders", value: "order", detail: "Historical orders with line items and totals" },
];

const WEIGHT_UNITS = [
  { label: "Kilograms (kg)", value: "KILOGRAMS" },
  { label: "Grams (g)", value: "GRAMS" },
  { label: "Pounds (lb)", value: "POUNDS" },
  { label: "Ounces (oz)", value: "OUNCES" },
];

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
  const [counts, setCounts] = useState<EntityCount[]>([]);
  const [planName, setPlanName] = useState<string | null>(null);
  const [notifyMe, setNotifyMe] = useState(prefs.emailNotifications);
  const [notifyEmail, setNotifyEmail] = useState("");
  const [loadingCounts, setLoadingCounts] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);

  useEffect(() => {
    api
      .preview()
      .then(({ counts, plan, shop_email }) => {
        setCounts(counts);
        setPlanName(plan?.name ?? null);
        if (shop_email) setNotifyEmail(shop_email);

        // Only pre-tick what the merchant can actually run — a checkbox that is
        // on by default but rejected on submit is worse than one that is off.
        setSelected(
          PREFERRED_DEFAULTS.filter((type) =>
            isSelectable(counts.find((c) => c.type === type)),
          ),
        );
      })
      .catch(() => undefined)
      .finally(() => setLoadingCounts(false));
  }, []);

  const gate = (type: string) => counts.find((c) => c.type === type);

  // Orders reference customers, so pull customers in automatically — but never
  // add an entity the plan blocks.
  const enforceDeps = (values: string[]) => {
    const next = [...values];
    if (next.includes("order") && !next.includes("customer") && isSelectable(gate("customer"))) {
      next.push("customer");
    }
    setSelected(next);
  };

  const migratableFor = (type: string) => gate(type)?.migratable ?? 0;
  const estimated = selected.reduce((sum, t) => sum + migratableFor(t), 0);

  const blockedByPlan = ENTITY_OPTIONS.filter((o) => isBlockedByPlan(gate(o.value)));
  const cappedByPlan = ENTITY_OPTIONS.filter((o) => {
    const entity = gate(o.value);
    return isSelectable(entity) && entity!.limit !== null && entity!.count > entity!.limit;
  });

  const helpTextFor = (option: (typeof ENTITY_OPTIONS)[number]) => {
    const entity = gate(option.value);
    if (!entity) return option.detail;

    const available = entity.count.toLocaleString();

    if (entity.locked) {
      return `Not included in your ${planName ?? "current"} plan — upgrade to migrate ${available}`;
    }
    if (entity.exhausted) {
      return `Plan limit reached — you've migrated all ${entity.limit?.toLocaleString()} your plan allows, and ${available} exist in WooCommerce`;
    }
    if (entity.count === 0) {
      return `None found in WooCommerce — ${option.detail}`;
    }
    if (entity.limit !== null && entity.count > entity.limit) {
      return `${entity.migratable.toLocaleString()} of ${available} will migrate on your ${planName ?? "current"} plan — upgrade for the rest`;
    }
    return `${available} available — ${option.detail}`;
  };

  const start = async () => {
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

        {!loadingCounts && blockedByPlan.length > 0 && (
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
                    helpText: helpTextFor(o),
                    // Off AND unselectable when the plan blocks it — the merchant
                    // is told why in the banner above rather than at submit time.
                    disabled: !isSelectable(gate(o.value)),
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
                  helpText="Large migrations can take hours — you don't have to keep this page open."
                />
                {notifyMe && (
                  <TextField
                    label="Send to"
                    type="email"
                    value={notifyEmail}
                    onChange={setNotifyEmail}
                    placeholder="you@example.com"
                    autoComplete="email"
                  />
                )}
              </BlockStack>
              <InlineStack align="end">
                <Button
                  variant="primary"
                  disabled={selected.length === 0 || loadingCounts}
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
