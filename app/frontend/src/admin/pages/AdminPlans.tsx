import { useEffect, useState } from "react";
import {
  Page,
  Card,
  DataTable,
  Badge,
  BlockStack,
  Text,
  SkeletonBodyText,
  Box,
  Banner,
} from "@shopify/polaris";
import { adminApi, type Plan } from "../api";

export default function AdminPlans() {
  const [plans, setPlans] = useState<Plan[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    adminApi.plans().then(setPlans).catch((e) => setError(e.message));
  }, []);

  const limitText = (limits: Plan["limits"]) =>
    limits
      ? Object.entries(limits)
          .map(([k, v]) => `${k}: ${v ?? "unlimited"}`)
          .join(", ")
      : "—";

  return (
    <Page title="Plans" subtitle="Plans and the stores subscribed to them">
      <BlockStack gap="400">
        {error && (
          <Banner tone="critical" title="Could not load plans">
            {error}
          </Banner>
        )}

        <Card padding="0">
          {!plans ? (
            <Box padding="400">
              <SkeletonBodyText lines={5} />
            </Box>
          ) : (
            <DataTable
              columnContentTypes={["text", "text", "numeric", "text", "numeric"]}
              headings={["Plan", "Code", "Price", "Limits", "Active subscriptions"]}
              rows={plans.map((p) => [
                p.name,
                p.code,
                `$${p.price}/${p.interval}`,
                limitText(p.limits),
                p.active_subscriptions,
              ])}
            />
          )}
        </Card>

        <Card>
          <BlockStack gap="200">
            <Text as="h2" variant="headingMd">
              Billing status
            </Text>
            <Text as="p" tone="subdued">
              Plans are defined here and read by the migration engine for limits. Shopify Billing
              API charges are not wired up yet — subscriptions currently have to be assigned
              manually.
            </Text>
            <Box>
              <Badge tone="attention">Billing integration pending</Badge>
            </Box>
          </BlockStack>
        </Card>
      </BlockStack>
    </Page>
  );
}
