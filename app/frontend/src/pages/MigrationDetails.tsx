import { useCallback, useEffect, useRef, useState } from "react";
import {
  Page,
  Card,
  ProgressBar,
  BlockStack,
  InlineStack,
  Text,
  Badge,
  Button,
  Banner,
  DataTable,
  Spinner,
  Box,
} from "@shopify/polaris";
import { useParams } from "react-router-dom";
import { api, type Migration, type MigrationItem, type MigrationLogEntry } from "../api/client";

const ACTIVE_STATES = ["pending", "processing", "paused"];

/**
 * A finished-with-errors or failed run used to be badged "info" — the same blue
 * as one that is merely queued — so the one screen that exists to report the
 * outcome gave no visual signal of what the outcome was.
 */
const STATUS_TONE: Record<string, "success" | "critical" | "attention" | "info"> = {
  completed: "success",
  completed_with_errors: "attention",
  paused: "attention",
  failed: "critical",
  cancelled: "critical",
  processing: "info",
  pending: "info",
};

export default function MigrationDetails() {
  const { id } = useParams();
  const migrationId = Number(id);
  const [migration, setMigration] = useState<Migration | null>(null);
  const [failedItems, setFailedItems] = useState<MigrationItem[]>([]);
  const [failedTotal, setFailedTotal] = useState(0);
  const [logs, setLogs] = useState<MigrationLogEntry[]>([]);
  const [retrying, setRetrying] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const timer = useRef<number | null>(null);

  const load = useCallback(async () => {
    try {
      const [m, { data: logEntries }] = await Promise.all([
        api.getMigration(migrationId),
        api.getLogs(migrationId).catch(() => ({ data: [] as MigrationLogEntry[] })),
      ]);
      setMigration(m);
      setLogs(logEntries);
      // A poll that succeeds clears a message left by one that did not; a page
      // that is working again should not still be shouting about a blip.
      setError(null);

      if (!ACTIVE_STATES.includes(m.status)) {
        if (timer.current) {
          window.clearInterval(timer.current);
          timer.current = null;
        }

        if (m.status === "completed_with_errors" || m.status === "failed") {
          // 200 is the API's page limit. A big run can fail more than that, so
          // the true total is shown alongside and the complete list lives in the
          // log download, which streams and has no cap.
          //
          // Failing to fetch the list must not cost the merchant the whole page,
          // which is the part that tells them the run finished at all.
          try {
            const { data, meta } = await api.getItems(migrationId, {
              status: "failed",
              per_page: 200,
            });
            setFailedItems(data);
            setFailedTotal(meta?.total ?? data.length);
          } catch {
            /* the summary above still stands */
          }
        }
      }
    } catch (e) {
      setError((e as Error).message);
    }
  }, [migrationId]);

  useEffect(() => {
    load();
    // Poll every 3s while active. Server is the source of truth.
    timer.current = window.setInterval(load, 3000);
    return () => {
      if (timer.current) window.clearInterval(timer.current);
    };
  }, [load]);

  const cancel = async () => {
    try {
      await api.cancelMigration(migrationId);
    } catch (e) {
      setError((e as Error).message);
    }
    load();
  };
  const retry = async () => {
    setRetrying(true);
    setError(null);
    try {
      const updated = await api.retryFailed(migrationId);
      setMigration(updated);
      setFailedItems([]);
      setFailedTotal(0);

      // Restart polling — but clear the old timer first, or every retry leaves
      // another interval running against the same endpoint.
      if (timer.current) window.clearInterval(timer.current);
      timer.current = window.setInterval(load, 3000);
      load();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setRetrying(false);
    }
  };

  if (!migration) {
    return (
      <Page title="Migration">
        <Spinner accessibilityLabel="Loading migration" />
      </Page>
    );
  }

  const isActive = ACTIVE_STATES.includes(migration.status);

  // Older API responses (a page kept open across a deploy) have no such field.
  const failedBatches = migration.failed_batches ?? 0;

  return (
    <Page
      title={migration.name ? migration.name : `Migration #${migration.id}`}
      subtitle={migration.name ? `Migration #${migration.id}` : undefined}
      backAction={{ content: "Migrations", url: "/migrations" }}
      secondaryActions={[
        {
          content: "Download log",
          onAction: () => api.downloadLog(migrationId).catch((e) => setError((e as Error).message)),
        },
        ...(isActive
          ? [{ content: "Cancel migration", destructive: true, onAction: cancel }]
          : []),
      ]}
    >
      <BlockStack gap="400">
        {error && (
          <Banner tone="critical" title="Error">
            {error}
          </Banner>
        )}

        <Card>
          <BlockStack gap="200">
            <InlineStack align="space-between">
              <Text as="h2" variant="headingMd">
                Status
              </Text>
              <Badge tone={STATUS_TONE[migration.status] ?? "info"}>{migration.status}</Badge>
            </InlineStack>
          </BlockStack>
        </Card>

        {migration.status === "pending" && (
          <Banner tone="info" title="Queued — waiting for a worker">
            Migrations run on a background queue that is checked periodically, so there can be a
            short delay before the first numbers move. You can close this page; the run continues.
          </Banner>
        )}

        {/*
          When a run crawls, the cause is almost always the merchant's own
          WooCommerce host rather than anything on this side. Saying so while it
          is still happening is the difference between "this app is broken" and
          "my server is slow" — and only one of those they can act on.
        */}
        {/*
          A healthy store used to render NOTHING here, which meant the merchant
          only ever saw this section when something was wrong — and the rest of
          the time had no way to tell whether their store was being read at all,
          or whether the app had simply stopped. "Everything is fine" is the
          answer they are looking for most of the time, so it has to be on screen
          too; it is just quiet about it instead of shouting.
        */}
        {migration.source_health &&
          (migration.source_health.status === "healthy" ? (
            <Card>
              <InlineStack gap="200" blockAlign="center">
                <Badge tone="success">Store responding</Badge>
                <Text as="span" tone="subdued" variant="bodySm">
                  WooCommerce is answering in about {migration.source_health.average_seconds}s per
                  request, measured over {migration.source_health.requests}
                  {migration.source_health.requests === 1 ? " request" : " requests"}.
                </Text>
              </InlineStack>
            </Card>
          ) : (
            <Banner
              tone={migration.source_health.status === "failing" ? "critical" : "warning"}
              title={
                migration.source_health.status === "failing"
                  ? "Your WooCommerce store is not responding"
                  : "Your WooCommerce store is slow"
              }
            >
              <BlockStack gap="100">
                <Text as="p">{migration.source_health.message}</Text>
                <Text as="p" tone="subdued" variant="bodySm">
                  Measured over {migration.source_health.requests} requests to your store
                  {migration.source_health.failures > 0 &&
                    ` — ${migration.source_health.failures} of them failed`}
                  .
                </Text>
              </BlockStack>
            </Banner>
          ))}

        <Card>
          <BlockStack gap="400">
            {(migration.entities ?? []).map((e) => {
              // The bar tracks what actually LANDED, not what was attempted. A run
              // where 30 of 50 orders failed used to show a full bar because all 50
              // were "processed" — which reads as success when it is the opposite.
              const landed = e.total > 0 ? Math.round((e.successful / e.total) * 100) : 0;

              return (
                <BlockStack key={e.type} gap="100">
                  <InlineStack align="space-between">
                    <Text as="span" fontWeight="bold">
                      {e.type}
                    </Text>
                    {e.total === 0 && isActive ? (
                      <Text as="span" tone="subdued">
                        counting…
                      </Text>
                    ) : (
                      <InlineStack gap="150">
                        <Text as="span" tone="subdued">
                          {e.successful}/{e.total} migrated
                        </Text>
                        {e.failed > 0 && (
                          <Text as="span" tone="critical">
                            {e.failed} failed
                          </Text>
                        )}
                      </InlineStack>
                    )}
                  </InlineStack>
                  <ProgressBar
                    progress={landed}
                    size="small"
                    tone={e.failed > 0 ? "critical" : "primary"}
                  />
                </BlockStack>
              );
            })}
          </BlockStack>
        </Card>

        <Card>
          <BlockStack gap="300">
            <InlineStack align="space-between" blockAlign="center">
              <Text as="h2" variant="headingMd">
                Activity
              </Text>
              {isActive && <Spinner accessibilityLabel="Migration running" size="small" />}
            </InlineStack>

            {logs.length === 0 ? (
              <Text as="p" tone="subdued">
                {isActive ? "Waiting for the first update…" : "Nothing was logged for this run."}
              </Text>
            ) : (
              <BlockStack gap="150">
                {logs.map((l) => (
                  <InlineStack key={l.id} gap="200" blockAlign="start" wrap={false}>
                    <Box minWidth="64px">
                      <Text as="span" variant="bodySm" tone="subdued">
                        {new Date(l.created_at).toLocaleTimeString()}
                      </Text>
                    </Box>
                    <Text
                      as="span"
                      variant="bodySm"
                      tone={
                        l.level === "error" ? "critical" : l.level === "warning" ? "caution" : "subdued"
                      }
                    >
                      {l.entity_type ? `${l.entity_type}: ` : ""}
                      {l.message}
                    </Text>
                  </InlineStack>
                ))}
              </BlockStack>
            )}
          </BlockStack>
        </Card>

        {(failedItems.length > 0 || failedBatches > 0) && (
          <Card>
            <BlockStack gap="300">
              <InlineStack align="space-between">
                <Text as="h2" variant="headingMd">
                  {failedItems.length > 0
                    ? `Failed items (${failedTotal || failedItems.length})`
                    : "Unfinished batches"}
                </Text>
                <Button onClick={retry} loading={retrying}>
                  Retry failed items
                </Button>
              </InlineStack>
              {/*
                A batch fails as a whole when its WooCommerce page could not be
                read at all, and then its records never reach migration_items —
                so they appear in no list on this page. Without saying so, the
                merchant sees "completed with errors" and no errors.
              */}
              {failedBatches > 0 && (
                <Banner tone="warning">
                  <p>
                    {failedBatches} batch{failedBatches === 1 ? "" : "es"} could not be read from
                    WooCommerce, so those records were never attempted and are not listed below.
                    "Retry failed items" runs them again. If this keeps happening, your store is
                    most likely timing out on large pages — try again when it is under less load.
                  </p>
                </Banner>
              )}
              {failedTotal > failedItems.length && (
                <Text as="p" tone="subdued" variant="bodySm">
                  Showing the first {failedItems.length} of {failedTotal}. Download the log for the
                  full list — "Retry failed items" retries every one of them, not just those shown.
                </Text>
              )}
              {failedItems.length > 0 && (
                <DataTable
                  columnContentTypes={["text", "numeric", "text"]}
                  headings={["Type", "WooCommerce ID", "Reason"]}
                  rows={failedItems.map((i) => [i.entity_type, i.woocommerce_id, i.error ?? ""])}
                />
              )}
            </BlockStack>
          </Card>
        )}
      </BlockStack>
    </Page>
  );
}
