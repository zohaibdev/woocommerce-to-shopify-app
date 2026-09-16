import { BlockStack, Modal, Text } from "@shopify/polaris";
import type { SubscriptionLifecycle } from "../api/client";
import { longDate, priceLabel, STATE_LABEL } from "./format";

/**
 * Confirms a cancellation and states exactly what it will do, using what the
 * server says cancelling would do right now (proration setting, paid period).
 *
 * Shopify's Billing API cancels immediately — there is no "cancel at period end"
 * — so the only question is whether the plan keeps working for the period
 * already paid for (no proration) or ends at once (prorated credit).
 */
export default function CancelSubscriptionModal({
  open,
  subscription: sub,
  cancelling,
  onClose,
  onConfirm,
}: {
  open: boolean;
  subscription: SubscriptionLifecycle;
  cancelling: boolean;
  onClose: () => void;
  onConfirm: () => void;
}) {
  const planName = sub.plan?.name ?? "paid";
  const until = sub.cancellation.access_until;
  const state = STATE_LABEL[sub.state]?.label ?? sub.state;

  return (
    <Modal
      open={open}
      onClose={() => !cancelling && onClose()}
      title={`Cancel your ${planName} plan?`}
      primaryAction={{ content: "Cancel subscription", destructive: true, loading: cancelling, onAction: onConfirm }}
      secondaryActions={[{ content: "Keep subscription", onAction: onClose, disabled: cancelling }]}
    >
      <Modal.Section>
        <BlockStack gap="100">
          <Text as="p">
            <Text as="span" fontWeight="semibold">
              {planName}
            </Text>{" "}
            · {priceLabel(sub.price, sub.interval, sub.currency ?? "USD")} · {state}
          </Text>
          {sub.renews_at && (
            <Text as="p" tone="subdued">
              Next billing would have been {longDate(sub.renews_at)}.
            </Text>
          )}
        </BlockStack>
      </Modal.Section>

      <Modal.Section>
        <BlockStack gap="300">
          {until ? (
            <Text as="p">
              Shopify cancels the subscription now and{" "}
              <Text as="span" fontWeight="semibold">
                won't charge you again
              </Text>
              . You've already paid for the current billing period, so {planName} stays available until{" "}
              <Text as="span" fontWeight="semibold">
                {longDate(until)}
              </Text>
              . After that your store moves to the Free plan.
            </Text>
          ) : sub.cancellation.prorate ? (
            <Text as="p">
              The subscription{" "}
              <Text as="span" fontWeight="semibold">
                ends immediately
              </Text>{" "}
              and Shopify credits the unused part of this billing period to your Shopify bill. Your store moves to
              the Free plan right away, and you won't be charged again.
            </Text>
          ) : (
            <Text as="p">
              The subscription{" "}
              <Text as="span" fontWeight="semibold">
                ends immediately
              </Text>{" "}
              and you won't be charged again. Your store moves to the Free plan right away.
            </Text>
          )}

          <Text as="p">
            Migrations you start {until ? `after ${longDate(until)}` : "from now on"} use the Free plan's limits.
            Nothing you've already migrated is removed, and a migration that's already running isn't stopped.
          </Text>

          <Text as="p" tone="subdued">
            Cancelling can't be undone. To use {planName} again later, you'll approve a new subscription in
            Shopify.
          </Text>
        </BlockStack>
      </Modal.Section>
    </Modal>
  );
}
