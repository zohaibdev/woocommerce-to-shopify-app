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
  Divider,
} from "@shopify/polaris";
import { useParams, useNavigate } from "react-router-dom";
import { adminApi } from "../api";

export default function AdminStoreDetail() {
  const { id } = useParams();
  const navigate = useNavigate();
  const [data, setData] = useState<any>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    adminApi
      .store(Number(id))
      .then(setData)
      .catch((e) => setError(e.message));
  }, [id]);

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
    </Page>
  );
}
