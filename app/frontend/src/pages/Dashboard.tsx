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
  QuestionCircleIcon,
  PlayIcon,
} from "@shopify/polaris-icons";
import { useNavigate } from "react-router-dom";
import {
  api,
  type Shop,
  type EntityCount,
  type Migration,
  type ContentItem,
} from "../api/client";
import { loadSettings } from "../settings";
import { openExternal, redirectTop } from "../navigation";

const STAT_META: Record<string, { label: string; icon: typeof ProductIcon }> = {
  product: { label: "Products", icon: ProductIcon },
  category: { label: "Categories", icon: CategoriesIcon },
  order: { label: "Orders", icon: OrderIcon },
  customer: { label: "Customers", icon: PersonIcon },
};

const ACTIVE = ["pending", "processing", "paused"];

/**
 * Icon + heading on a shared baseline. Polaris <Icon> renders a block-level svg
 * with its own line-height, so pairing it directly with <Text> sits a couple of
 * pixels off — the fixed-size flex box keeps them optically centred.
 */
function IconTitle({
  icon,
  children,
}: {
  icon: typeof ProductIcon;
  children: React.ReactNode;
}) {
  return (
    <InlineStack gap="200" blockAlign="center" wrap={false}>
      <span
        style={{ display: "inline-flex", alignItems: "center", justifyContent: "center", width: 20, height: 20 }}
      >
        <Icon source={icon} tone="base" />
      </span>
      <Text as="h2" variant="headingMd">
        {children}
      </Text>
    </InlineStack>
  );
}

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
  const prefs = loadSettings();
  const [shop, setShop] = useState<Shop | null>(null);
  const [counts, setCounts] = useState<EntityCount[]>([]);
  const [countsLoading, setCountsLoading] = useState(false);
  const [migrations, setMigrations] = useState<Migration[]>([]);
  const [running, setRunning] = useState<Migration | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [content, setContent] = useState<ContentItem[]>([]);
  const timer = useRef<number | null>(null);

  // Admin-managed content wins; fall back to local settings when none exists.
  const byType = (t: string) => content.filter((c) => c.type === t);
  const video = byType("tutorial_video")[0];
  const videoUrl = video?.url || prefs.tutorialVideoUrl;
  const supportItem = byType("support_link")[0];
  const supportUrl = supportItem?.url || prefs.supportUrl;
  const announcements = byType("announcement");

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
    // The interval below is created AFTER two awaits, so by the time it exists
    // the cleanup for this run of the effect may already have happened —
    // React's StrictMode mounts, unmounts and remounts on purpose, and every
    // remount used to leave its predecessor's timer running against the API
    // forever. Measured: three overlapping pollers on a freshly loaded page,
    // growing by one on every return to the dashboard.
    let cancelled = false;

    (async () => {
      try {
        const s = await api.getShop();

        if (cancelled) return;
        setShop(s);

        // Content is admin-managed; never let it block the dashboard.
        api
          .content()
          .then(({ data }) => {
            if (!cancelled) setContent(data);
          })
          .catch(() => undefined);

        if (s.woocommerce_connected) {
          // Migrations are fast (DB) — gate the page on these only.
          const { data } = await api.listMigrations();

          if (cancelled) return;
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
            .then(({ counts }) => {
              if (!cancelled) setCounts(counts);
            })
            .catch(() => undefined)
            .finally(() => {
              if (!cancelled) setCountsLoading(false);
            });
        }
      } catch (e) {
        if (!cancelled) setError((e as Error).message);
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();

    return () => {
      cancelled = true;

      if (timer.current) {
        window.clearInterval(timer.current);
        timer.current = null;
      }
    };
  }, [pollRunning]);

  /**
   * The run in flight, if any.
   *
   * `shop.active_migration_id` is only correct as of page load, so once polling
   * has an opinion it wins — otherwise finishing a migration left the dashboard
   * insisting one was still running until the merchant reloaded.
   */
  const activeMigrationId = running
    ? ACTIVE.includes(running.status)
      ? running.id
      : null
    : (shop?.active_migration_id ?? null);

  const statCards = ["product", "category", "order", "customer"].map((type) => {
    const meta = STAT_META[type];
    const count = counts.find((c) => c.type === type)?.count ?? 0;
    return (
      <Card key={type}>
        <BlockStack gap="200">
          <InlineStack gap="200" blockAlign="center" wrap={false}>
            {/* Fixed square keeps every card's icon on the same baseline. */}
            <Box
              background="bg-surface-secondary"
              borderRadius="200"
              width="36px"
              minHeight="36px"
            >
              <div
                style={{
                  display: "flex",
                  alignItems: "center",
                  justifyContent: "center",
                  height: "36px",
                }}
              >
                <Icon source={meta.icon} tone="base" />
              </div>
            </Box>
            <Text as="span" tone="subdued" variant="bodySm" truncate>
              {meta.label}
            </Text>
          </InlineStack>
          {/* Reserve the value row's height so cards don't jump while loading. */}
          <Box minHeight="32px">
            {countsLoading ? (
              <SkeletonDisplayText size="small" />
            ) : (
              <Text as="span" variant="heading2xl">
                {count.toLocaleString()}
              </Text>
            )}
          </Box>
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
          ? activeMigrationId
            ? {
                // Only one run at a time: the API refuses a second one, so
                // offering the button would only ever produce an error.
                content: "View running migration",
                onAction: () => navigate(`/migrations/${activeMigrationId}`),
              }
            : { content: "Start new migration", onAction: () => navigate("/migrations/new") }
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

        {announcements.map((a) => (
          <Layout.Section key={a.id}>
            <Banner
              tone="info"
              title={a.title}
              action={a.url ? { content: "Learn more", url: a.url, external: true } : undefined}
            >
              {a.body}
            </Banner>
          </Layout.Section>
        ))}

        {shop?.needs_scope_update && (
          <Layout.Section>
            <Banner
              tone="warning"
              title="App update available"
              action={{
                content: "Update app",
                // Assigning window.top.location throws a SecurityError inside the
                // admin iframe, which unmounted the whole app instead of
                // redirecting. See navigation.ts.
                onAction: () => redirectTop(shop.reauth_url),
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
                <InlineStack gap="150" blockAlign="center" wrap={false}>
                  <span
                    style={{
                      display: "inline-flex",
                      alignItems: "center",
                      justifyContent: "center",
                      width: 20,
                      height: 20,
                    }}
                  >
                    <Icon source={CheckCircleIcon} tone="success" />
                  </span>
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
                        <ResourceItem key={m.id} id={String(m.id)} onClick={() => navigate(`/migrations/${m.id}`)}>
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
                  {/* Support */}
                  <Card>
                    <BlockStack gap="300">
                      <IconTitle icon={QuestionCircleIcon}>
                        {supportItem?.title || "Support"}
                      </IconTitle>
                      <Text as="p" tone="subdued" variant="bodySm">
                        {supportItem?.body ||
                          "Something not migrating as expected? We're here to help."}
                      </Text>
                      <InlineStack gap="200">
                        <Button variant="primary" onClick={() => navigate("/support")}>
                          Get help
                        </Button>
                        {supportUrl && (
                          <Button onClick={() => openExternal(supportUrl)}>
                            Contact support
                          </Button>
                        )}
                      </InlineStack>
                    </BlockStack>
                  </Card>
                </BlockStack>
              </InlineGrid>
            </Layout.Section>

            {/* Video tutorial — managed by the super admin (Dashboard content) */}
            <Layout.Section>
              <Card>
                <BlockStack gap="300">
                  <IconTitle icon={PlayIcon}>{video?.title || "Video tutorial"}</IconTitle>
                  {video?.body && (
                    <Text as="p" tone="subdued" variant="bodySm">
                      {video.body}
                    </Text>
                  )}

                  {videoUrl ? (
                    <Box borderRadius="200" overflowX="hidden" overflowY="hidden">
                      <div style={{ position: "relative", paddingTop: "56.25%" }}>
                        <iframe
                          src={videoUrl}
                          title="Shift Shop tutorial"
                          allow="accelerometer; autoplay; clipboard-write; encrypted-media; picture-in-picture"
                          allowFullScreen
                          style={{
                            position: "absolute",
                            top: 0,
                            left: 0,
                            width: "100%",
                            height: "100%",
                            border: 0,
                          }}
                        />
                      </div>
                    </Box>
                  ) : (
                    <Box background="bg-surface-secondary" padding="500" borderRadius="200">
                      <BlockStack gap="200" inlineAlign="center">
                        <Text as="p" tone="subdued" alignment="center">
                          No tutorial video configured yet.
                        </Text>
                        <Button onClick={() => navigate("/settings")}>Add a video</Button>
                      </BlockStack>
                    </Box>
                  )}
                </BlockStack>
              </Card>
            </Layout.Section>
          </>
        )}
      </Layout>
    </Page>
  );
}
