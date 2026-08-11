import { useEffect, useState } from "react";
import {
  Page,
  Card,
  Layout,
  Text,
  BlockStack,
  InlineGrid,
  SkeletonBodyText,
  Banner,
  Button,
  InlineStack,
} from "@shopify/polaris";
import { useNavigate } from "react-router-dom";
import { adminApi, type Overview } from "../api";

// Only the numeric counters are tiles — `queue` is an object, rendered as a banner.
type CounterKey = {
  [K in keyof Overview]: Overview[K] extends number ? K : never;
}[keyof Overview];

const TILES: { key: CounterKey; label: string; to?: string; tone?: "critical" }[] = [
  { key: "stores", label: "Installed stores", to: "/admin/stores" },
  { key: "stores_uninstalled", label: "Uninstalled" },
  { key: "active_subscriptions", label: "Active subscriptions", to: "/admin/plans" },
  { key: "migrations", label: "Total migrations", to: "/admin/migrations" },
  { key: "migrations_running", label: "Running now", to: "/admin/migrations" },
  { key: "failed_items", label: "Failed items", to: "/admin/errors", tone: "critical" },
  { key: "open_tickets", label: "Open tickets", to: "/admin/tickets" },
];

export default function AdminOverview() {
  const navigate = useNavigate();
  const [data, setData] = useState<Overview | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    adminApi.overview().then(setData).catch((e) => setError(e.message));
  }, []);

  return (
    <Page title="Overview" subtitle="Everything across all installed stores">
      <Layout>
        {error && (
          <Layout.Section>
            <Banner tone="critical" title="Could not load overview">
              {error}
            </Banner>
          </Layout.Section>
        )}

        {data?.queue?.worker_stalled && (
          <Layout.Section>
            <Banner tone="critical" title="Queue worker is not running">
              <BlockStack gap="200">
                <Text as="p">
                  {`${data.queue.pending_jobs} job(s) are waiting — the oldest has been queued for ${Math.round(
                    (data.queue.oldest_pending_seconds ?? 0) / 60,
                  )} minute(s). Migrations cannot progress until a worker consumes them.`}
                </Text>
                <Text as="p" variant="bodySm" tone="subdued">
                  Fix: add a cron job that runs every minute —
                  {" "}
                  <code>
                    cd ~/public_html/app/backend &amp;&amp; php artisan queue:work
                    --stop-when-empty --max-time=55
                  </code>
                </Text>
              </BlockStack>
            </Banner>
          </Layout.Section>
        )}

        {data && data.queue.failed_jobs > 0 && (
          <Layout.Section>
            <Banner tone="warning" title={`${data.queue.failed_jobs} failed job(s)`}>
              Some queued jobs threw an exception and were abandoned. Check Error logs, then
              re-queue with <code>php artisan queue:retry all</code>.
            </Banner>
          </Layout.Section>
        )}
        <Layout.Section>
          <InlineGrid columns={{ xs: 2, md: 4 }} gap="400">
            {TILES.map((t) => (
              <Card key={t.key}>
                <BlockStack gap="200">
                  <Text as="span" tone="subdued" variant="bodySm">
                    {t.label}
                  </Text>
                  {!data ? (
                    <SkeletonBodyText lines={1} />
                  ) : (
                    <Text
                      as="span"
                      variant="heading2xl"
                      tone={t.tone && data[t.key] > 0 ? "critical" : undefined}
                    >
                      {data[t.key].toLocaleString()}
                    </Text>
                  )}
                  {t.to && (
                    <InlineStack>
                      <Button variant="plain" onClick={() => navigate(t.to!)}>
                        View
                      </Button>
                    </InlineStack>
                  )}
                </BlockStack>
              </Card>
            ))}
          </InlineGrid>
        </Layout.Section>
      </Layout>
    </Page>
  );
}
