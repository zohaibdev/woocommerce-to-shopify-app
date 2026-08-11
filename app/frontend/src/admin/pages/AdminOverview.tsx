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

const TILES: { key: keyof Overview; label: string; to?: string; tone?: "critical" }[] = [
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
