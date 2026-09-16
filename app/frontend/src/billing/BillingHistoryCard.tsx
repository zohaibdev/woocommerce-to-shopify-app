import { BlockStack, Card, DataTable, Text } from "@shopify/polaris";
import type { BillingHistoryEntry } from "../api/client";
import { eventLabel, longDate, shortDate } from "./format";

/**
 * The subscription's lifecycle as it actually happened. Every row is a recorded
 * event with the date it occurred — nothing here is reconstructed or estimated.
 */
export default function BillingHistoryCard({ history }: { history: BillingHistoryEntry[] }) {
  return (
    <Card padding="0">
      <div style={{ padding: 16 }}>
        <BlockStack gap="100">
          <Text as="h2" variant="headingMd">
            Billing history
          </Text>
          <Text as="p" tone="subdued" variant="bodySm">
            Charges themselves appear on your Shopify bill (Settings → Billing).
          </Text>
        </BlockStack>
      </div>

      {history.length === 0 ? (
        <div style={{ padding: "0 16px 16px" }}>
          <Text as="p" tone="subdued">
            No subscription activity yet.
          </Text>
        </div>
      ) : (
        <DataTable
          columnContentTypes={["text", "text", "text", "text"]}
          headings={["Event", "Plan", "Date", "Details"]}
          rows={history.map((e) => [
            eventLabel(e.event),
            e.plan ?? "—",
            shortDate(e.occurred_at) ?? "—",
            <span key={e.id} style={{ whiteSpace: "normal", display: "block", minWidth: 220 }}>
              {e.message ?? ""}
              {e.access_until ? ` Available until ${longDate(e.access_until)}.` : ""}
              {e.next_billing ? ` Next billing ${longDate(e.next_billing)}.` : ""}
            </span>,
          ])}
        />
      )}
    </Card>
  );
}
