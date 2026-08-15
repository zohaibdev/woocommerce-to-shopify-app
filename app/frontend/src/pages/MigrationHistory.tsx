import { useCallback, useEffect, useState } from "react";
import {
  Page,
  Card,
  ResourceList,
  ResourceItem,
  Text,
  Badge,
  EmptyState,
  SkeletonBodyText,
  Pagination,
  InlineStack,
  BlockStack,
  Box,
  Banner,
} from "@shopify/polaris";
import { useNavigate } from "react-router-dom";
import { api, type Migration, type MigrationListMeta } from "../api/client";

const statusTone: Record<Migration["status"], "success" | "critical" | "attention" | "info"> = {
  completed: "success",
  completed_with_errors: "attention",
  failed: "critical",
  cancelled: "critical",
  processing: "info",
  pending: "info",
  paused: "attention",
};

const PER_PAGE = 10;

export default function MigrationHistory() {
  const navigate = useNavigate();
  const [migrations, setMigrations] = useState<Migration[]>([]);
  const [meta, setMeta] = useState<MigrationListMeta | null>(null);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);

  const [error, setError] = useState<string | null>(null);

  const load = useCallback((p: number) => {
    setLoading(true);
    setError(null);
    api
      .listMigrations(p, PER_PAGE)
      .then(({ data, meta }) => {
        setMigrations(data);
        setMeta(meta);
      })
      // Without this the promise rejected unhandled and the list sat on a
      // skeleton forever, which reads as the page having hung.
      .catch((e) => setError((e as Error).message))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    load(page);
  }, [load, page]);

  const rangeStart = meta ? (meta.current_page - 1) * meta.per_page + 1 : 0;
  const rangeEnd = meta ? Math.min(meta.current_page * meta.per_page, meta.total) : 0;

  return (
    <Page
      title="Migrations"
      subtitle={meta && meta.total > 0 ? `${meta.total} total` : undefined}
      primaryAction={
        // A shop may only run one migration at a time — the API returns 409 for a
        // second — so while one is in flight this points at it instead of
        // offering a wizard that cannot be submitted.
        meta?.active_migration_id
          ? {
              content: "View running migration",
              onAction: () => navigate(`/migrations/${meta.active_migration_id}`),
            }
          : { content: "New migration", onAction: () => navigate("/migrations/new") }
      }
    >
      <BlockStack gap="400">
        {error && (
          <Banner tone="critical" title="Could not load your migrations">
            {error}
          </Banner>
        )}

        <Card padding="0">
          {loading ? (
            <div style={{ padding: 16 }}>
              <SkeletonBodyText lines={6} />
            </div>
          ) : migrations.length === 0 ? (
            <EmptyState
              heading="No migrations yet"
              action={{
                content: "Start new migration",
                onAction: () => navigate("/migrations/new"),
                disabled: !!meta?.active_migration_id,
              }}
              image="https://cdn.shopify.com/s/files/1/0262/4071/2726/files/emptystate-files.png"
            >
              <p>Run your first WooCommerce → Shopify migration.</p>
            </EmptyState>
          ) : (
            <ResourceList
              items={migrations}
              renderItem={(m) => (
                <ResourceItem
                  key={m.id}
                  id={String(m.id)}
                  onClick={() => navigate(`/migrations/${m.id}`)}
                  accessibilityLabel={`Migration ${m.id}`}
                >
                  <InlineStack align="space-between" blockAlign="center" wrap={false}>
                    <BlockStack gap="050">
                      <Text as="h3" fontWeight="bold">
                        {m.name ? m.name : `Migration #${m.id}`}
                      </Text>
                      <Text as="span" variant="bodySm" tone="subdued">
                        {m.selected_entities.join(", ")}
                      </Text>
                      <Text as="span" variant="bodySm" tone="subdued">
                        {new Date(m.created_at).toLocaleString()}
                      </Text>
                    </BlockStack>
                    <Badge tone={statusTone[m.status]}>{m.status}</Badge>
                  </InlineStack>
                </ResourceItem>
              )}
            />
          )}
        </Card>

        {meta && meta.last_page > 1 && (
          <Box paddingBlockEnd="400">
            <InlineStack align="center" gap="300" blockAlign="center">
              <Pagination
                hasPrevious={meta.current_page > 1 && !loading}
                onPrevious={() => setPage((p) => Math.max(1, p - 1))}
                hasNext={meta.has_more && !loading}
                onNext={() => setPage((p) => p + 1)}
                label={`${rangeStart}–${rangeEnd} of ${meta.total}`}
              />
            </InlineStack>
          </Box>
        )}
      </BlockStack>
    </Page>
  );
}
