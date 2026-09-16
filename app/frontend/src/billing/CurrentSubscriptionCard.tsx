import { Badge, BlockStack, Box, Button, Card, Divider, InlineStack, Text } from "@shopify/polaris";
import type { ReactNode } from "react";
import type { SubscriptionLifecycle } from "../api/client";
import { dateTime, longDate, priceLabel, reasonText, STATE_LABEL } from "./format";

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <InlineStack align="space-between" blockAlign="start" gap="400" wrap={false}>
      <Box minWidth="40%">
        <Text as="span" tone="subdued">
          {label}
        </Text>
      </Box>
      <Text as="span" alignment="end">
        {children}
      </Text>
    </InlineStack>
  );
}

/**
 * The merchant's subscription at a glance: plan, price, status, the dates that
 * matter for its state, and only the actions that are valid right now.
 */
export default function CurrentSubscriptionCard({
  subscription: sub,
  busy,
  onChangePlan,
  onCancel,
  onResubscribe,
  onContinueCheckout,
  onRefresh,
}: {
  subscription: SubscriptionLifecycle;
  busy: boolean;
  onChangePlan: () => void;
  onCancel: () => void;
  onResubscribe: () => void;
  onContinueCheckout: () => void;
  onRefresh: () => void;
}) {
  const state = STATE_LABEL[sub.state] ?? { label: sub.state, tone: undefined };
  const pending = sub.pending_change;

  if (sub.state === "free") {
    return (
      <Card>
        <BlockStack gap="300">
          <InlineStack align="space-between" blockAlign="center">
            <Text as="h2" variant="headingMd">
              Your subscription
            </Text>
            <Badge>Free plan</Badge>
          </InlineStack>
          <Text as="p" tone="subdued">
            You're on the free plan and not being charged. Choose a paid plan below — you approve the charge on
            Shopify's own screen, and it appears on your regular Shopify bill.
          </Text>
          {pending && <PendingChange sub={sub} onContinueCheckout={onContinueCheckout} busy={busy} />}
        </BlockStack>
      </Card>
    );
  }

  return (
    <Card>
      <BlockStack gap="400">
        <InlineStack align="space-between" blockAlign="center" gap="200">
          <Text as="h2" variant="headingMd">
            Your subscription
          </Text>
          <InlineStack gap="100">
            {sub.test && <Badge tone="info">Test charge</Badge>}
            <Badge tone={state.tone}>{state.label}</Badge>
          </InlineStack>
        </InlineStack>

        <BlockStack gap="050">
          <Text as="p" variant="headingLg">
            {sub.plan?.name ?? "—"}
          </Text>
          <Text as="p" tone="subdued">
            {priceLabel(sub.price, sub.interval, sub.currency ?? "USD")}
          </Text>
        </BlockStack>

        <Divider />

        <BlockStack gap="200">
          {sub.state === "pending" && (
            <Row label="Requested">{longDate(sub.created_at) ?? "—"}</Row>
          )}
          {sub.started_at && <Row label="Started">{longDate(sub.started_at)}</Row>}
          {sub.state === "active" && (
            <Row label="Next billing">{longDate(sub.renews_at) ?? "Not reported by Shopify yet"}</Row>
          )}
          {(sub.state === "frozen" || sub.state === "unknown") && sub.current_period_end && (
            <Row label="Billing period ended">{longDate(sub.current_period_end)}</Row>
          )}
          {(sub.state === "cancelled" || sub.state === "ended") && sub.cancelled_at && (
            <Row label="Cancelled">
              {longDate(sub.cancelled_at)} · {reasonText(sub.cancellation_reason)}
            </Row>
          )}
          {sub.state === "cancelled" && (
            <Row label="Available until">
              <Text as="span" fontWeight="semibold">
                {longDate(sub.access_until)}
              </Text>
            </Row>
          )}
          {sub.state === "ended" && sub.ended_at && <Row label="Ended">{longDate(sub.ended_at)}</Row>}
          {(sub.state === "cancelled" || sub.state === "ended") && <Row label="Renews">No — you won't be charged again</Row>}
          {sub.entitled_plan && sub.plan && sub.entitled_plan.code !== sub.plan.code && (
            <Row label="Plan in use now">{sub.entitled_plan.name}</Row>
          )}
          {sub.previous?.plan && (
            <Row label="Previous plan">
              {sub.previous.plan.name}
              {sub.previous.ended_at ? ` · ended ${longDate(sub.previous.ended_at)}` : ""}
            </Row>
          )}
        </BlockStack>

        {pending && <PendingChange sub={sub} onContinueCheckout={onContinueCheckout} busy={busy} />}

        <InlineStack gap="200" wrap>
          {sub.actions.can_resubscribe && (
            <Button variant="primary" onClick={onResubscribe} disabled={busy}>
              {`Resubscribe to ${sub.plan?.name ?? "plan"}`}
            </Button>
          )}
          {sub.actions.can_change_plan && sub.state !== "pending" && (
            <Button onClick={onChangePlan} disabled={busy}>
              Change plan
            </Button>
          )}
          {sub.state === "pending" && sub.actions.can_continue_checkout && (
            <Button variant="primary" onClick={onContinueCheckout} disabled={busy}>
              Continue to approval
            </Button>
          )}
          {sub.actions.can_cancel && (
            <Button variant="plain" tone="critical" onClick={onCancel} disabled={busy}>
              Cancel subscription
            </Button>
          )}
        </InlineStack>

        <Divider />

        <InlineStack align="space-between" blockAlign="center" gap="200">
          <Text as="p" variant="bodySm" tone={sub.sync.failing ? "critical" : "subdued"}>
            {sub.sync.failing
              ? "Couldn't reach Shopify on the last check — showing the last confirmed status."
              : `Checked with Shopify ${dateTime(sub.sync.last_synced_at) ?? "—"}`}
          </Text>
          <Button variant="plain" onClick={onRefresh} disabled={busy}>
            Refresh status
          </Button>
        </InlineStack>
      </BlockStack>
    </Card>
  );
}

function PendingChange({
  sub,
  onContinueCheckout,
  busy,
}: {
  sub: SubscriptionLifecycle;
  onContinueCheckout: () => void;
  busy: boolean;
}) {
  const pending = sub.pending_change;
  if (!pending?.plan || sub.state === "pending") return null;

  const text =
    pending.status === "scheduled"
      ? `Change to ${pending.plan.name} is approved and starts ${pending.effective_at ? `on ${longDate(pending.effective_at)}` : "at your next billing cycle"}.`
      : pending.status === "processing"
        ? `You approved ${pending.plan.name}. Shopify is still confirming it — this usually takes a moment.`
        : `You started a change to ${pending.plan.name} but haven't approved it in Shopify yet${
            pending.expires_at ? ` (the request expires ${longDate(pending.expires_at)})` : ""
          }. Nothing changes until you approve.`;

  return (
    <Box background="bg-surface-secondary" padding="300" borderRadius="200">
      <InlineStack align="space-between" blockAlign="center" gap="200">
        <Text as="p" variant="bodySm">
          {text}
        </Text>
        {pending.status === "awaiting_approval" && (
          <Button size="slim" onClick={onContinueCheckout} disabled={busy}>
            Continue to approval
          </Button>
        )}
      </InlineStack>
    </Box>
  );
}
