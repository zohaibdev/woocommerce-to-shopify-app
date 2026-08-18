import { useCallback, useEffect, useState } from "react";
import {
  Page,
  Card,
  IndexTable,
  Badge,
  Text,
  Select,
  Pagination,
  BlockStack,
  InlineStack,
  Box,
  SkeletonBodyText,
  ProgressBar,
  Modal,
  DataTable,
  Tabs,
  Button,
} from "@shopify/polaris";
import { adminApi, type AdminMigration, type PageMeta } from "../api";
import MigrationDebugPanel from "./MigrationDebugPanel";

const STATUSES = [
  { label: "All statuses", value: "" },
  { label: "Processing", value: "processing" },
  { label: "Pending", value: "pending" },
  { label: "Completed", value: "completed" },
  { label: "Completed with errors", value: "completed_with_errors" },
  { label: "Failed", value: "failed" },
  { label: "Cancelled", value: "cancelled" },
];

const tone = (s: string) =>
  s === "completed" ? "success" : s === "failed" || s === "cancelled" ? "critical"
    : s === "completed_with_errors" ? "attention" : "info";

export default function AdminMigrations() {
  const [rows, setRows] = useState<AdminMigration[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState("");
  const [loading, setLoading] = useState(true);
  const [detail, setDetail] = useState<any>(null);
  // 0 = the merchant-facing summary, 1 = the developer diagnostics. Reset when a
  // different migration is opened, or the panel would poll the run you just
  // closed while showing the one you just opened.
  const [tab, setTab] = useState(0);

  const load = useCallback((p: number, st: string) => {
    setLoading(true);
    adminApi
      .migrations(p, st ? { status: st } : {})
      .then(({ data, meta }) => {
        setRows(data);
        setMeta(meta);
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    load(page, status);
  }, [load, page, status]);

  return (
    <Page title="Migrations" subtitle={meta ? `${meta.total} total` : undefined}>
      <BlockStack gap="400">
        <Card>
          <Select
            label="Filter by status"
            options={STATUSES}
            value={status}
            onChange={(v) => {
              setPage(1);
              setStatus(v);
            }}
          />
        </Card>

        <Card padding="0">
          {loading ? (
            <Box padding="400">
              <SkeletonBodyText lines={6} />
            </Box>
          ) : (
            <IndexTable
              resourceName={{ singular: "migration", plural: "migrations" }}
              itemCount={rows.length}
              selectable={false}
              headings={[
                { title: "Migration" },
                { title: "Store" },
                { title: "Status" },
                { title: "Progress" },
                { title: "Created" },
              ]}
            >
              {rows.map((m, i) => {
                const totals = m.progress.reduce(
                  (a, p) => ({ processed: a.processed + p.processed, total: a.total + p.total, failed: a.failed + p.failed }),
                  { processed: 0, total: 0, failed: 0 },
                );
                const pct = totals.total ? Math.round((totals.processed / totals.total) * 100) : 0;
                return (
                  <IndexTable.Row
                    id={String(m.id)}
                    key={m.id}
                    position={i}
                    onClick={() => {
                      setTab(0);
                      adminApi.migration(m.id).then(setDetail);
                    }}
                  >
                    <IndexTable.Cell>
                      <Text as="span" fontWeight="semibold">
                        {m.name ?? `#${m.id}`}
                      </Text>
                    </IndexTable.Cell>
                    <IndexTable.Cell>{m.shop ?? "—"}</IndexTable.Cell>
                    <IndexTable.Cell>
                      <Badge tone={tone(m.status)}>{m.status}</Badge>
                    </IndexTable.Cell>
                    <IndexTable.Cell>
                      <div style={{ minWidth: 140 }}>
                        <ProgressBar progress={pct} size="small" />
                        <Text as="span" variant="bodySm" tone="subdued">
                          {`${totals.processed}/${totals.total}${totals.failed ? ` · ${totals.failed} failed` : ""}`}
                        </Text>
                      </div>
                    </IndexTable.Cell>
                    <IndexTable.Cell>
                      <Text as="span" variant="bodySm">
                        {new Date(m.created_at).toLocaleString()}
                      </Text>
                    </IndexTable.Cell>
                  </IndexTable.Row>
                );
              })}
            </IndexTable>
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

      {detail && (
        <Modal
          open
          onClose={() => setDetail(null)}
          title={`${detail.name ?? `Migration #${detail.id}`} — ${detail.shop ?? ""}`}

        >
          <Tabs
            tabs={[
              { id: "summary", content: "Summary" },
              { id: "debug", content: "Developer log" },
            ]}
            selected={tab}
            onSelect={setTab}
          >
            <Modal.Section>
              {tab === 0 ? (
                <BlockStack gap="400">
                  <DataTable
                    columnContentTypes={["text", "numeric", "numeric", "numeric"]}
                    headings={["Entity", "Processed", "Successful", "Failed"]}
                    rows={detail.entities.map((e: any) => [
                      e.type,
                      `${e.processed}/${e.total}`,
                      e.successful,
                      e.failed,
                    ])}
                  />
                  <InlineStack align="space-between" blockAlign="center">
                    <Text as="h3" variant="headingSm">
                      Failed items ({detail.failed_items.length})
                    </Text>
                    {/* The one thing worth reaching from the summary without
                        switching tabs: on a run where everything failed, the
                        whole file is the answer. */}
                    <Button onClick={() => setTab(1)}>Open developer log</Button>
                  </InlineStack>
                  {detail.failed_items.length > 0 && (
                    <DataTable
                      columnContentTypes={["text", "numeric", "text"]}
                      headings={["Type", "Woo ID", "Reason"]}
                      rows={detail.failed_items.map((f: any) => [
                        f.entity_type,
                        f.woocommerce_id,
                        (f.error ?? "").slice(0, 160),
                      ])}
                    />
                  )}
                </BlockStack>
              ) : (
                <MigrationDebugPanel migrationId={detail.id} />
              )}
            </Modal.Section>
          </Tabs>
        </Modal>
      )}
    </Page>
  );
}
