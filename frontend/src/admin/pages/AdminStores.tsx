import { useCallback, useEffect, useState } from "react";
import {
  Page,
  Card,
  IndexTable,
  Badge,
  Text,
  TextField,
  Pagination,
  BlockStack,
  InlineStack,
  Box,
  SkeletonBodyText,
  EmptyState,
} from "@shopify/polaris";
import { useNavigate } from "react-router-dom";
import { adminApi, type AdminStore, type PageMeta } from "../api";

export default function AdminStores() {
  const navigate = useNavigate();
  const [rows, setRows] = useState<AdminStore[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [search, setSearch] = useState("");
  const [loading, setLoading] = useState(true);

  const load = useCallback((p: number, q: string) => {
    setLoading(true);
    adminApi
      .stores(p, q)
      .then(({ data, meta }) => {
        setRows(data);
        setMeta(meta);
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    const t = setTimeout(() => load(page, search), 250);
    return () => clearTimeout(t);
  }, [load, page, search]);

  return (
    <Page title="Stores" subtitle={meta ? `${meta.total} total` : undefined}>
      <BlockStack gap="400">
        <Card>
          <TextField
            label="Search stores"
            labelHidden
            value={search}
            onChange={(v) => {
              setPage(1);
              setSearch(v);
            }}
            placeholder="Search by myshopify domain…"
            autoComplete="off"
            clearButton
            onClearButtonClick={() => setSearch("")}
          />
        </Card>

        <Card padding="0">
          {loading ? (
            <Box padding="400">
              <SkeletonBodyText lines={6} />
            </Box>
          ) : rows.length === 0 ? (
            <EmptyState heading="No stores found" image="">
              <p>No store matches this search.</p>
            </EmptyState>
          ) : (
            <IndexTable
              resourceName={{ singular: "store", plural: "stores" }}
              itemCount={rows.length}
              selectable={false}
              headings={[
                { title: "Store" },
                { title: "Status" },
                { title: "Plan" },
                { title: "WooCommerce" },
                { title: "Migrations" },
                { title: "Last migration" },
              ]}
            >
              {rows.map((s, i) => (
                <IndexTable.Row
                  id={String(s.id)}
                  key={s.id}
                  position={i}
                  onClick={() => navigate(`/admin/stores/${s.id}`)}
                >
                  <IndexTable.Cell>
                    <Text as="span" fontWeight="semibold">
                      {s.shopify_domain}
                    </Text>
                  </IndexTable.Cell>
                  <IndexTable.Cell>
                    <Badge tone={s.installed ? "success" : "critical"}>
                      {s.installed ? "Installed" : "Uninstalled"}
                    </Badge>
                  </IndexTable.Cell>
                  <IndexTable.Cell>
                    {s.plan ? (
                      <Badge tone="info">{`${s.plan}${s.plan_status ? ` · ${s.plan_status}` : ""}`}</Badge>
                    ) : (
                      <Text as="span" tone="subdued">
                        —
                      </Text>
                    )}
                  </IndexTable.Cell>
                  <IndexTable.Cell>
                    {s.woocommerce ? (
                      <Badge tone={s.woocommerce.status === "connected" ? "success" : "attention"}>
                        {s.woocommerce.status}
                      </Badge>
                    ) : (
                      <Text as="span" tone="subdued">
                        Not connected
                      </Text>
                    )}
                  </IndexTable.Cell>
                  <IndexTable.Cell>{s.migrations_count}</IndexTable.Cell>
                  <IndexTable.Cell>
                    {s.last_migration ? (
                      <Text as="span" variant="bodySm">
                        {s.last_migration.status}
                      </Text>
                    ) : (
                      <Text as="span" tone="subdued">
                        —
                      </Text>
                    )}
                  </IndexTable.Cell>
                </IndexTable.Row>
              ))}
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
    </Page>
  );
}
