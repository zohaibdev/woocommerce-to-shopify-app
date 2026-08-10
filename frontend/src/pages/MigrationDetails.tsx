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
} from "@shopify/polaris";
import { useParams } from "react-router-dom";
import { api, type Migration, type MigrationItem } from "../api/client";

const ACTIVE_STATES = ["pending", "processing", "paused"];

export default function MigrationDetails() {
  const { id } = useParams();
  const migrationId = Number(id);
  const [migration, setMigration] = useState<Migration | null>(null);
  const [failedItems, setFailedItems] = useState<MigrationItem[]>([]);
  const [error, setError] = useState<string | null>(null);
  const timer = useRef<number | null>(null);

  const load = useCallback(async () => {
    try {
      const m = await api.getMigration(migrationId);
      setMigration(m);
      if (!ACTIVE_STATES.includes(m.status)) {
        if (timer.current) window.clearInterval(timer.current);
        if (m.status === "completed_with_errors" || m.status === "failed") {
          const { data } = await api.getItems(migrationId, { status: "failed" });
          setFailedItems(data);
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
    await api.cancelMigration(migrationId);
    load();
  };
  const retry = async () => {
    await api.retryFailed(migrationId);
    setFailedItems([]);
    timer.current = window.setInterval(load, 3000);
    load();
  };

  if (!migration) {
    return (
      <Page title="Migration">
        <Spinner accessibilityLabel="Loading migration" />
      </Page>
    );
  }

  const isActive = ACTIVE_STATES.includes(migration.status);

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
              <Badge tone={migration.status === "completed" ? "success" : "info"}>
                {migration.status}
              </Badge>
            </InlineStack>
          </BlockStack>
        </Card>

        <Card>
          <BlockStack gap="400">
            {migration.entities.map((e) => (
              <BlockStack key={e.type} gap="100">
                <InlineStack align="space-between">
                  <Text as="span" fontWeight="bold">
                    {e.type}
                  </Text>
                  <Text as="span" tone="subdued">
                    {e.processed}/{e.total} · {e.successful} ok · {e.failed} failed
                  </Text>
                </InlineStack>
                <ProgressBar progress={e.percentage} size="small" />
              </BlockStack>
            ))}
          </BlockStack>
        </Card>

        {failedItems.length > 0 && (
          <Card>
            <BlockStack gap="300">
              <InlineStack align="space-between">
                <Text as="h2" variant="headingMd">
                  Failed items ({failedItems.length})
                </Text>
                <Button onClick={retry}>Retry failed items</Button>
              </InlineStack>
              <DataTable
                columnContentTypes={["text", "numeric", "text"]}
                headings={["Type", "WooCommerce ID", "Reason"]}
                rows={failedItems.map((i) => [i.entity_type, i.woocommerce_id, i.error ?? ""])}
              />
            </BlockStack>
          </Card>
        )}
      </BlockStack>
    </Page>
  );
}
