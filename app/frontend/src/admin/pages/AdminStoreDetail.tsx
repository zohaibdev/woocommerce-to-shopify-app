import { useEffect, useState } from "react";
import {
  Page,
  Card,
  Layout,
  Text,
  Badge,
  BlockStack,
  InlineStack,
  DataTable,
  Spinner,
  Banner,
  Button,
  Modal,
  ChoiceList,
  Divider,
} from "@shopify/polaris";
import { useParams, useNavigate } from "react-router-dom";
import { adminApi, type EntityResetMode, type StoreEntityRow } from "../api";

/**
 * What each reset mode actually does to the merchant's Shopify store, in the
 * words an operator needs before choosing. The whole difference is duplicates,
 * so it is spelled out rather than left to the mode's name.
 */
const RESET_MODES: { value: EntityResetMode; label: string; helpText: string }[] = [
  {
    value: "resync",
    label: "Re-sync (recommended)",
    helpText:
      "Keeps the record of what was migrated. The next migration re-migrates these records and re-uses whatever it can already find in Shopify, so nothing is duplicated.",
  },
  {
    value: "forget",
    label: "Forget entirely",
    helpText:
      "Deletes the record of what was migrated. The next migration CREATES these records again — if they still exist in Shopify the merchant ends up with two of each. Only use this when they have been cleared out of Shopify.",
  },
];

export default function AdminStoreDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [data, setData] = useState<any>(null);
  const [error, setError] = useState<string | null>(null);

  // Reset dialog state.
  const [resetting, setResetting] = useState<StoreEntityRow | null>(null);
  const [mode, setMode] = useState<EntityResetMode>("resync");
  const [busy, setBusy] = useState(false);
  const [notice, setNotice] = useState<string | null>(null);

  useEffect(() => {
    adminApi
      .store(Number(id))
      .then(setData)
      .catch((e) => setError(e.message));
  }, [id]);

  const runReset = async () => {
    if (!resetting) return;

    setBusy(true);
    setError(null);

    try {
      const result = await adminApi.resetStoreEntity(Number(id), resetting.type, mode);

      // The endpoint returns the fresh breakdown, so the table reflects the
      // reset without a round trip that could race another operator's change.
      setData({ ...data, entities: result.entities });
      setNotice(result.message);
      setResetting(null);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  if (error) {
    return (
      <Page title="Store" backAction={{ content: "Stores", onAction: () => navigate("/admin/stores") }}>
        <Banner tone="critical" title="Could not load store">
          {error}
        </Banner>
      </Page>
    );
  }

  if (!data) {
    return (
      <Page title="Store">
        <Spinner accessibilityLabel="Loading store" />
      </Page>
    );
  }

  const s = data.store;

  return (
    <Page
      title={s.shopify_domain}
      subtitle={s.installed ? "Installed" : "Uninstalled"}
      backAction={{ content: "Stores", onAction: () => navigate("/admin/stores") }}
    >
      <Layout>
        <Layout.Section>
          <Card>
            <BlockStack gap="300">
              <Text as="h2" variant="headingMd">
                Store details
              </Text>
              <Divider />
              <InlineStack align="space-between">
                <Text as="span">Status</Text>
                <Badge tone={s.installed ? "success" : "critical"}>
                  {s.installed ? "Installed" : "Uninstalled"}
                </Badge>
              </InlineStack>
              <InlineStack align="space-between">
                <Text as="span">Installed at</Text>
                <Text as="span" tone="subdued">
                  {s.installed_at ? new Date(s.installed_at).toLocaleString() : "—"}
                </Text>
              </InlineStack>
              <InlineStack align="space-between">
                <Text as="span">Plan</Text>
                <Text as="span" tone="subdued">
                  {s.plan ? `${s.plan} (${s.plan_status})` : "No subscription"}
                </Text>
              </InlineStack>
              <InlineStack align="space-between">
                <Text as="span">WooCommerce</Text>
                <Text as="span" tone="subdued">
                  {s.woocommerce ? `${s.woocommerce.store_url} · ${s.woocommerce.status}` : "Not connected"}
                </Text>
              </InlineStack>
              <InlineStack align="space-between">
                <Text as="span">Scopes</Text>
                <Text as="span" tone="subdued">
                  {s.scopes ?? "—"}
                </Text>
              </InlineStack>
            </BlockStack>
          </Card>
        </Layout.Section>

        <Layout.Section>
          <Card padding="0">
            <div style={{ padding: 16 }}>
              <BlockStack gap="200">
                <Text as="h2" variant="headingMd">
                  Migrated records
                </Text>
                <Text as="p" tone="subdued">
                  What this store has migrated so far. Resetting an entity lets the merchant migrate
                  it again — this also frees those records from their current billing period&rsquo;s
                  allowance.
                </Text>
              </BlockStack>
            </div>

            {notice && (
              <div style={{ padding: "0 16px 16px" }}>
                <Banner tone="success" onDismiss={() => setNotice(null)}>
                  {notice}
                </Banner>
              </div>
            )}

            {!data.entities || data.entities.length === 0 ? (
              <div style={{ padding: 16 }}>
                <Text as="p" tone="subdued">
                  Nothing migrated yet — there is nothing to reset.
                </Text>
              </div>
            ) : (
              <DataTable
                columnContentTypes={["text", "numeric", "numeric", "numeric", "text"]}
                headings={["Entity", "Migrated", "Failed", "Total", ""]}
                rows={(data.entities as StoreEntityRow[]).map((e) => [
                  e.type,
                  e.success.toLocaleString(),
                  e.failed.toLocaleString(),
                  e.total.toLocaleString(),
                  <Button
                    key={e.type}
                    size="slim"
                    onClick={() => {
                      setMode("resync");
                      setNotice(null);
                      setResetting(e);
                    }}
                  >
                    Reset
                  </Button>,
                ])}
              />
            )}
          </Card>
        </Layout.Section>

        <Layout.Section>
          <Card padding="0">
            <div style={{ padding: 16 }}>
              <Text as="h2" variant="headingMd">
                Migration history
              </Text>
            </div>
            {data.migrations.length === 0 ? (
              <div style={{ padding: 16 }}>
                <Text as="p" tone="subdued">
                  No migrations yet.
                </Text>
              </div>
            ) : (
              <DataTable
                columnContentTypes={["text", "text", "text", "text"]}
                headings={["Migration", "Entities", "Status", "Created"]}
                rows={data.migrations.map((m: any) => [
                  m.name ?? `#${m.id}`,
                  (m.entities ?? []).join(", "),
                  m.status,
                  new Date(m.created_at).toLocaleString(),
                ])}
              />
            )}
          </Card>
        </Layout.Section>

        <Layout.Section>
          <Card padding="0">
            <div style={{ padding: 16 }}>
              <Text as="h2" variant="headingMd">
                Recent errors
              </Text>
            </div>
            {data.recent_errors.length === 0 ? (
              <div style={{ padding: 16 }}>
                <Text as="p" tone="subdued">
                  No errors recorded.
                </Text>
              </div>
            ) : (
              <DataTable
                columnContentTypes={["text", "text", "text"]}
                headings={["When", "Entity", "Message"]}
                rows={data.recent_errors.map((e: any) => [
                  new Date(e.created_at).toLocaleString(),
                  e.entity_type ?? "—",
                  e.message,
                ])}
              />
            )}
          </Card>
        </Layout.Section>
      </Layout>

      {resetting && (
        <Modal
          open
          onClose={() => setResetting(null)}
          title={`Reset ${resetting.type} for ${s.shopify_domain}`}
          primaryAction={{
            content: mode === "forget" ? "Forget records" : "Reset records",
            onAction: runReset,
            loading: busy,
            destructive: mode === "forget",
          }}
          secondaryActions={[{ content: "Cancel", onAction: () => setResetting(null) }]}
        >
          <Modal.Section>
            <BlockStack gap="400">
              <Text as="p">
                This affects <b>{resetting.total.toLocaleString()}</b> {resetting.type} record
                {resetting.total === 1 ? "" : "s"} for this store. It changes what Shop Shift
                remembers — it does not delete anything from the merchant&rsquo;s Shopify store.
              </Text>

              <ChoiceList
                title="What should happen on the next migration?"
                choices={RESET_MODES}
                selected={[mode]}
                onChange={(v) => setMode(v[0] as EntityResetMode)}
              />

              {mode === "forget" && (
                <Banner tone="warning" title="This can create duplicates">
                  The next migration will create these {resetting.type} records again. If they are
                  still in the merchant&rsquo;s Shopify store, they will end up with two of each.
                </Banner>
              )}
            </BlockStack>
          </Modal.Section>
        </Modal>
      )}
    </Page>
  );
}
