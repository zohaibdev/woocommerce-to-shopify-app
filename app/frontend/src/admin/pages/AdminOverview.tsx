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
  const [notice, setNotice] = useState<string | null>(null);

  const load = () => adminApi.overview().then(setData).catch((e) => setError(e.message));

  useEffect(() => {
    load();
  }, []);

  /**
   * Run a queue recovery and report what happened.
   *
   * The counters are re-read afterwards so the banner that prompted the click
   * disappears on its own — leaving a "worker is not running" warning on screen
   * after the worker has been started reads as the button not having worked.
   */
  const act = async (which: "run" | "retry") => {
    setError(null);
    setNotice(null);
    try {
      const result =
        which === "run" ? await adminApi.runQueue() : await adminApi.retryFailedJobs();
      setNotice(result.message);
      await load();
    } catch (e) {
      setError((e as Error).message);
    }
  };

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

        {notice && (
          <Layout.Section>
            <Banner tone="info" onDismiss={() => setNotice(null)}>
              {notice}
            </Banner>
          </Layout.Section>
        )}

        {data?.queue?.worker_stalled && (
          <Layout.Section>
            <Banner
              tone="critical"
              title="Queue worker is not running"
              /*
                The old banner told whoever read it to SSH in and run a command.
                Most people seeing this are looking at a frozen migration on a
                phone, so the recovery is a button now — and the cron advice is
                shown only when the host will not let the app start its own
                worker, which is the one case where it is actually the answer.
              */
              action={
                data.queue.can_self_start
                  ? { content: "Start worker now", onAction: () => act("run") }
                  : undefined
              }
            >
              <BlockStack gap="200">
                <Text as="p">
                  {`${data.queue.pending_jobs} job(s) are waiting — the oldest has been queued for ${Math.round(
                    (data.queue.oldest_pending_seconds ?? 0) / 60,
                  )} minute(s). Migrations cannot progress until a worker consumes them.`}
                </Text>
                {data.queue.can_self_start ? (
                  <Text as="p" variant="bodySm" tone="subdued">
                    The app normally starts a worker by itself as merchants use it. If this keeps
                    coming back, a cron running every minute gives far better throughput:{" "}
                    <code>
                      cd ~/public_html/app/backend &amp;&amp; php artisan queue:work
                      --stop-when-empty --max-time=55
                    </code>
                  </Text>
                ) : (
                  <Text as="p" variant="bodySm" tone="subdued">
                    This server blocks the app from starting processes (<code>proc_open</code> is
                    disabled), so a cron job is required — add one that runs every minute:{" "}
                    <code>
                      cd ~/public_html/app/backend &amp;&amp; php artisan queue:work
                      --stop-when-empty --max-time=55
                    </code>
                  </Text>
                )}
              </BlockStack>
            </Banner>
          </Layout.Section>
        )}

        {data && data.queue.failed_jobs > 0 && (
          <Layout.Section>
            <Banner
              tone="warning"
              title={`${data.queue.failed_jobs} failed job(s)`}
              action={{ content: "Re-queue them", onAction: () => act("retry") }}
            >
              Some queued jobs threw an exception and were abandoned. They are retried
              automatically a few times; anything still here has failed repeatedly and is worth
              reading in Error logs before re-queueing again.
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
