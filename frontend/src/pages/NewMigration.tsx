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
  SkeletonBodyText,
  SkeletonDisplayText,
} from "@shopify/polaris";
import { useNavigate } from "react-router-dom";
import { api, type EntityCount } from "../api/client";
import { loadSettings } from "../settings";

const ENTITY_OPTIONS = [
  { label: "Products", value: "product" },
  { label: "Categories", value: "category" },
  { label: "Attributes", value: "attribute" },
  { label: "Product variations (requires attributes)", value: "variation" },
  { label: "Images / media", value: "media" },
  { label: "Customers", value: "customer" },
  { label: "Orders", value: "order" },
];

export default function NewMigration() {
  const navigate = useNavigate();
  const [step, setStep] = useState<1 | 2>(1);
  const prefs = loadSettings();
  const [name, setName] = useState("");
  const [selected, setSelected] = useState<string[]>(["product", "category"]);
  const [customTags, setCustomTags] = useState(prefs.defaultTags);
  const [defaultVendor, setDefaultVendor] = useState(prefs.defaultVendor);
  const [counts, setCounts] = useState<EntityCount[]>([]);
  const [loadingCounts, setLoadingCounts] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [starting, setStarting] = useState(false);

  useEffect(() => {
    api
      .preview()
      .then(({ counts }) => setCounts(counts))
      .catch(() => undefined)
      .finally(() => setLoadingCounts(false));
  }, []);

  // Dependency rule: variations require attributes + products.
  const enforceDeps = (values: string[]) => {
    const next = [...values];
    if (next.includes("variation")) {
      if (!next.includes("attribute")) next.push("attribute");
      if (!next.includes("product")) next.push("product");
    }
    if (next.includes("media") && !next.includes("product")) next.push("product");
    setSelected(next);
  };

  const countFor = (type: string) => counts.find((c) => c.type === type)?.count ?? 0;
  const estimated = selected.reduce((sum, t) => sum + countFor(t), 0);

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
              <Text as="h2" variant="headingMd">
                Select data to migrate
              </Text>

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
                    ...o,
                    helpText: `${countFor(o.value).toLocaleString()} available`,
                  }))}
                  selected={selected}
                  onChange={enforceDeps}
                />
              )}

              <Divider />
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
              {selected.map((t) => (
                <InlineStack key={t} align="space-between">
                  <Text as="span">{ENTITY_OPTIONS.find((o) => o.value === t)?.label ?? t}</Text>
                  <Text as="span" tone="subdued">
                    {countFor(t).toLocaleString()}
                  </Text>
                </InlineStack>
              ))}
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
