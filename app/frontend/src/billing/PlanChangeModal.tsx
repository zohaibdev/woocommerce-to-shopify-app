import { BlockStack, Modal, Text } from "@shopify/polaris";
import type { BillingPlan, SubscriptionLifecycle } from "../api/client";
import { longDate, priceLabel } from "./format";

/**
 * Says what choosing a plan will do BEFORE the merchant is sent to Shopify, from
 * the server's preview of Shopify's documented replacement rules: when it takes
 * effect and whether Shopify charges a prorated difference or credits one.
 */
export default function PlanChangeModal({
  plan,
  subscription: sub,
  working,
  onClose,
  onConfirm,
}: {
  plan: BillingPlan | null;
  subscription: SubscriptionLifecycle;
  working: boolean;
  onClose: () => void;
  onConfirm: () => void;
}) {
  if (!plan) return null;

  const change = plan.change;
  const currentName = sub.state === "active" || sub.state === "frozen" || sub.state === "unknown" ? sub.plan?.name : null;
  const resubscribing = (sub.state === "cancelled" || sub.state === "ended") && sub.plan?.code === plan.code;

  const title =
    change?.type === "upgrade"
      ? `Upgrade to ${plan.name}`
      : change?.type === "downgrade"
        ? `Downgrade to ${plan.name}`
        : resubscribing
          ? `Resubscribe to ${plan.name}`
          : currentName
            ? `Switch to ${plan.name}`
            : `Subscribe to ${plan.name}`;

  let effect: string;

  if (change && change.timing === "next_billing_cycle") {
    effect = `Your ${currentName ?? "current"} plan continues until ${
      longDate(change.effective_at) ?? "the end of its billing period"
    }; ${plan.name} starts then. Shopify doesn't charge for ${plan.name} until it starts.`;
  } else if (currentName && change?.proration === "charge_difference") {
    effect = `Your ${currentName} plan is replaced as soon as you approve. Shopify charges the prorated difference for the rest of your current billing period.`;
  } else if (currentName && change?.proration === "credit") {
    effect = `Your ${currentName} plan is replaced as soon as you approve. Shopify credits the unused part of it to your Shopify bill.`;
  } else if (currentName) {
    effect = `Your ${currentName} plan is replaced as soon as you approve.`;
  } else if (sub.state === "cancelled") {
    effect = `Approving starts a new ${plan.name} subscription right away. Your cancelled ${sub.plan?.name} plan's remaining days end when it starts.`;
  } else {
    effect = `Your ${plan.name} subscription starts as soon as you approve it.`;
  }

  return (
    <Modal
      open
      onClose={() => !working && onClose()}
      title={title}
      primaryAction={{ content: "Continue to Shopify", loading: working, onAction: onConfirm }}
      secondaryActions={[{ content: "Not now", onAction: onClose, disabled: working }]}
    >
      <Modal.Section>
        <BlockStack gap="300">
          <Text as="p" variant="headingMd">
            {plan.name} · {priceLabel(plan.price, plan.interval)}
          </Text>
          <Text as="p">{effect}</Text>
          <Text as="p" tone="subdued">
            You'll approve the charge on Shopify's own screen, and it appears on your regular Shopify bill. If you
            decline or close that screen, nothing changes and nothing is charged.
          </Text>
        </BlockStack>
      </Modal.Section>
    </Modal>
  );
}
