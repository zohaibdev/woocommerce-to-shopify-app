import { useCallback, useEffect, useRef, useState } from "react";
import {
  Page,
  Layout,
  Card,
  Text,
  Button,
  Badge,
  BlockStack,
  InlineGrid,
  InlineStack,
  Banner,
  ProgressBar,
  SkeletonBodyText,
  SkeletonDisplayText,
  Box,
  Icon,
  ResourceList,
  ResourceItem,
  Spinner,
} from "@shopify/polaris";
import {
  ProductIcon,
  CategoriesIcon,
  PersonIcon,
  OrderIcon,
  CheckCircleIcon,
  BookIcon,
  QuestionCircleIcon,
} from "@shopify/polaris-icons";
import { useNavigate } from "react-router-dom";
import { api, type Shop, type EntityCount, type Migration } from "../api/client";

const STAT_META: Record<string, { label: string; icon: typeof ProductIcon }> = {
  product: { label: "Products", icon: ProductIcon },
  category: { label: "Categories", icon: CategoriesIcon },
  order: { label: "Orders", icon: OrderIcon },
  customer: { label: "Customers", icon: PersonIcon },
};

const ACTIVE = ["pending", "processing", "paused"];

const statusTone = (s: Migration["status"]) =>
  s === "completed"
    ? "success"
    : s === "failed" || s === "cancelled"
      ? "critical"
      : s === "completed_with_errors" || s === "paused"
        ? "attention"
        : "info";

export default function Dashboard() {
  const navigate = useNavigate();
  const [shop, setShop] = useState<Shop | null>(null);
  const [counts, setCounts] = useState<EntityCount[]>([]);
  const [countsLoading, setCountsLoading] = useState(false);
  const [migrations, setMigrations] = useState<Migration[]>([]);
  const [running, setRunning] = useState<Migration | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const timer = useRef<number | null>(null);

  const pollRunning = useCallback(async (id: number) => {
    try {
      const m = await api.getMigration(id);
      setRunning(m);
      if (!ACTIVE.includes(m.status) && timer.current) {
        window.clearInterval(timer.current);
        timer.current = null;
        api.listMigrations().then(({ data }) => setMigrations(data));
      }
    } catch {
      /* ignore transient */
    }
  }, []);

  useEffect(() => {
    (async () => {
      try {
        const s = await api.getShop();
        setShop(s);
        if (s.woocommerce_connected) {
          // Migrations are fast (DB) — gate the page on these only.
          const { data } = await api.listMigrations();
          setMigrations(data);
          const active = data.find((m) => ACTIVE.includes(m.status));
          if (active) {
            setRunning(active);
            timer.current = window.setInterval(() => pollRunning(active.id), 3000);
          }
          // Counts hit the live WooCommerce API and can be slow — load them
          // separately so they never block the dashboard from rendering.
          setCountsLoading(true);
          api
            .preview()
            .then(({ counts }) => setCounts(counts))
            .catch(() => undefined)
            .finally(() => setCountsLoading(false));
        }
      } catch (e) {
        setError((e as Error).message);
      } finally {
        setLoading(false);
      }
    })();
    return () => {
      if (timer.current) window.clearInterval(timer.current);
    };
  }, [pollRunning]);

  const statCards = ["product", "category", "order", "customer"].map((type) => {
    const meta = STAT_META[type];
    const count = counts.find((c) => c.type === type)?.count ?? 0;
    return (
      <Card key={type}>
        <BlockStack gap="200">
          <InlineStack gap="200" blockAlign="center">
            <Box background="bg-surface-secondary" padding="200" borderRadius="200">
              <Icon source={meta.icon} tone="base" />
            </Box>
            <Text as="span" tone="subdued" variant="bodySm">
              {meta.label}
            </Text>
          </InlineStack>
          {countsLoading ? (
            <SkeletonDisplayText size="small" />
          ) : (
            <Text as="span" variant="heading2xl">
              {count.toLocaleString()}
            </Text>
          )}
        </BlockStack>
      </Card>
    );
  });

  if (loading) {
    return (
      <Page title="Home">
        <Layout>
          <Layout.Section>
            <Card>
              <BlockStack gap="300">
                <SkeletonDisplayText size="medium" />
                <SkeletonBodyText lines={2} />
              </BlockStack>
            </Card>
          </Layout.Section>
          <Layout.Section>
            <InlineGrid columns={{ xs: 2, md: 4 }} gap="400">
              {[0, 1, 2, 3].map((i) => (
                <Card key={i}>
                  <SkeletonBodyText lines={2} />
                </Card>
              ))}
            </InlineGrid>
          </Layout.Section>
        </Layout>
      </Page>
    );
  }

  return (
    <Page
      title="Home"
      primaryAction={
        shop?.woocommerce_connected
          ? { content: "Start new migration", onAction: () => navigate("/migrations/new") }
          : undefined
      }
    >
      <Layout>
        {error && (
          <Layout.Section>
            <Banner tone="critical" title="Something went wrong">
              {error}
            </Banner>
          </Layout.Section>
        )}

        {shop?.needs_scope_update && (
          <Layout.Section>
            <Banner
              tone="warning"
              title="App update available"
              action={{
                content: "Update app",
                onAction: () => {
                  (window.top ?? window).location.href = shop.reauth_url;
                },
              }}
            >
              This app needs additional permissions ({shop.missing_scopes.join(", ")}). Update the
              app to grant them and unlock all migration features.
            </Banner>
          </Layout.Section>
        )}

        {/* Hero */}
        <Layout.Section>
          <Box background="bg-fill-info-secondary" padding="500" borderRadius="300">
            <BlockStack gap="200">
              <Text as="h2" variant="headingLg">
                Welcome{shop?.name ? `, ${shop.name}` : ""} 👋
              </Text>
              <Text as="p" tone="subdued">
                Migrate your WooCommerce products, collections, variants, images, customers and
                orders into Shopify — safely, in the background.
              </Text>
              {shop?.woocommerce_connected ? (
                <InlineStack gap="100" blockAlign="center">
                  <Icon source={CheckCircleIcon} tone="success" />
                  <Badge tone="success">WooCommerce connected</Badge>
                </InlineStack>
              ) : (
                <Badge tone="attention">WooCommerce not connected</Badge>
              )}
            </BlockStack>
          </Box>
        </Layout.Section>

        {!shop?.woocommerce_connected && (
          <Layout.Section>
            <Banner
              tone="info"
              title="Connect your WooCommerce store to begin"
              action={{ content: "Connect WooCommerce", onAction: () => navigate("/woocommerce") }}
            >
              You haven't connected a WooCommerce store yet.
            </Banner>
          </Layout.Section>
        )}

        {shop?.woocommerce_connected && (
          <>
            <Layout.Section>
              <InlineGrid columns={{ xs: 2, md: 4 }} gap="400">
                {statCards}
              </InlineGrid>
            </Layout.Section>

            {/* Current migration progress (only while running) */}
            {running && ACTIVE.includes(running.status) && (
              <Layout.Section>
                <Card>
                  <BlockStack gap="400">
                    <InlineStack align="space-between" blockAlign="center">
                      <InlineStack gap="200" blockAlign="center">
                        <Spinner size="small" />
                        <Text as="h2" variant="headingMd">
                          Migration in progress
                        </Text>
                      </InlineStack>
                      <Button variant="plain" onClick={() => navigate(`/migrations/${running.id}`)}>
                        View
                      </Button>
                    </InlineStack>
                    {running.entities.map((e) => (
                      <BlockStack key={e.type} gap="100">
                        <InlineStack align="space-between">
                          <Text as="span" variant="bodySm">
                            {e.type}
                          </Text>
                          <Text as="span" variant="bodySm" tone="subdued">
                            {e.processed}/{e.total}
                          </Text>
                        </InlineStack>
                        <ProgressBar progress={e.percentage} size="small" />
                      </BlockStack>
                    ))}
                  </BlockStack>
                </Card>
              </Layout.Section>
            )}

            {/* Recent migrations + Documentation/Support */}
            <Layout.Section>
              <InlineGrid columns={{ xs: 1, md: ["twoThirds", "oneThird"] }} gap="400">
                <Card padding="0">
                  <Box padding="400" borderBlockEndWidth="025" borderColor="border">
                    <InlineStack align="space-between" blockAlign="center">
                      <Text as="h2" variant="headingMd">
                        Recent migrations
                      </Text>
                      <Button variant="plain" onClick={() => navigate("/migrations")}>
                        View all
                      </Button>
                    </InlineStack>
                  </Box>
                  {migrations.length === 0 ? (
                    <Box padding="400">
                      <Text as="p" tone="subdued">
                        No migrations yet. Start your first one above.
                      </Text>
                    </Box>
                  ) : (
                    <ResourceList
                      items={migrations.slice(0, 5)}
                      renderItem={(m) => (
                        <ResourceItem id={String(m.id)} onClick={() => navigate(`/migrations/${m.id}`)}>
                          <InlineStack align="space-between" blockAlign="center">
                            <BlockStack gap="050">
                              <Text as="span" fontWeight="semibold">
                                {m.name ? m.name : `Migration #${m.id}`}
                              </Text>
                              <Text as="span" variant="bodySm" tone="subdued">
                                {m.selected_entities.join(", ")}
                              </Text>
                            </BlockStack>
                            <Badge tone={statusTone(m.status)}>{m.status}</Badge>
                          </InlineStack>
                        </ResourceItem>
                      )}
                    />
                  )}
                </Card>

                <BlockStack gap="400">
                  {/* Documentation */}
                  <Card>
                    <BlockStack gap="300">
                      <InlineStack gap="200" blockAlign="center">
                        <Icon source={BookIcon} tone="base" />
                        <Text as="h2" variant="headingMd">
                          Documentation
                        </Text>
                      </InlineStack>
                      <Text as="p" tone="subdued" variant="bodySm">
                        Learn how migrations work, what data maps where, and how to handle large
                        stores.
                      </Text>
                      <InlineStack>
                        <Button
                          onClick={() =>
                            window.open("https://woocommerce.com/document/woocommerce-rest-api/", "_blank")
                          }
                        >
                          Read the docs
                        </Button>
                      </InlineStack>
                    </BlockStack>
                  </Card>

                  {/* Support */}
                  <Card>
                    <BlockStack gap="300">
                      <InlineStack gap="200" blockAlign="center">
                        <Icon source={QuestionCircleIcon} tone="base" />
                        <Text as="h2" variant="headingMd">
                          Support
                        </Text>
                      </InlineStack>
                      <Text as="p" tone="subdued" variant="bodySm">
                        Something not migrating as expected? We're here to help.
                      </Text>
                      <InlineStack>
                        <Button onClick={() => window.open("mailto:support@example.com", "_blank")}>
                          Contact support
                        </Button>
                      </InlineStack>
                    </BlockStack>
                  </Card>
                </BlockStack>
              </InlineGrid>
            </Layout.Section>
          </>
        )}
      </Layout>
    </Page>
  );
}
