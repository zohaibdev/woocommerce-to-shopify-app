import { useCallback, useEffect, useState } from "react";
import {
  Page,
  Card,
  DataTable,
  Pagination,
  BlockStack,
  InlineStack,
  Box,
  SkeletonBodyText,
  TextField,

  EmptyState,
} from "@shopify/polaris";
import { adminApi, type ErrorLog, type PageMeta } from "../api";

export default function AdminErrors() {
  const [rows, setRows] = useState<ErrorLog[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [shopId, setShopId] = useState("");
  const [migrationId, setMigrationId] = useState("");
  const [loading, setLoading] = useState(true);

  const load = useCallback((p: number, shop: string, mig: string) => {
    setLoading(true);
    adminApi
      .errors(p, {
        ...(shop ? { shop_id: Number(shop) } : {}),
        ...(mig ? { migration_id: Number(mig) } : {}),
      })
      .then(({ data, meta }) => {
        setRows(data);
        setMeta(meta);
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    const t = setTimeout(() => load(page, shopId, migrationId), 250);
    return () => clearTimeout(t);
  }, [load, page, shopId, migrationId]);

  return (
    <Page title="Error logs" subtitle={meta ? `${meta.total} errors` : undefined}>
      <BlockStack gap="400">
        <Card>
          <InlineStack gap="400">
            <div style={{ minWidth: 180 }}>
              <TextField
                label="Filter by store ID"
                value={shopId}
                onChange={(v) => {
                  setPage(1);
                  setShopId(v.replace(/\D/g, ""));
                }}
                placeholder="e.g. 3"
                autoComplete="off"
                clearButton
                onClearButtonClick={() => setShopId("")}
              />
            </div>
            <div style={{ minWidth: 180 }}>
              <TextField
                label="Filter by migration ID"
                value={migrationId}
                onChange={(v) => {
                  setPage(1);
                  setMigrationId(v.replace(/\D/g, ""));
                }}
                placeholder="e.g. 25"
                autoComplete="off"
                clearButton
                onClearButtonClick={() => setMigrationId("")}
              />
            </div>
          </InlineStack>
        </Card>

        <Card padding="0">
          {loading ? (
            <Box padding="400">
              <SkeletonBodyText lines={8} />
            </Box>
          ) : rows.length === 0 ? (
            <EmptyState heading="No errors" image="">
              <p>Nothing has failed for this filter.</p>
            </EmptyState>
          ) : (
            <DataTable
              columnContentTypes={["text", "text", "numeric", "text", "text"]}
              headings={["When", "Store", "Migration", "Entity", "Message"]}
              rows={rows.map((e) => [
                new Date(e.created_at).toLocaleString(),
                e.shop ?? "—",
                e.migration_id,
                e.entity_type ?? "—",
                e.message.length > 180 ? e.message.slice(0, 180) + "…" : e.message,
              ])}
            />
          )}
        </Card>

        {meta && meta.last_page > 1 && (
          <InlineStack align="center">
            <Pagination
              hasPrevious={meta.current_page > 1}
              onPrevious={() => setPage((p) => p - 1)}
              hasNext={meta.has_more}
              onNext={() => setPage((p) => p + 1)}
              label={`Page ${meta.current_page} of ${meta.last_page}`}
            />
          </InlineStack>
        )}
      </BlockStack>
    </Page>
  );
}
