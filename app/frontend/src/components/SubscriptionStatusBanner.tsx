import { Banner, Text } from "@shopify/polaris";
import type { SubscriptionLifecycle } from "../api/client";
import { longDate, reasonText } from "../billing/format";
import { hasSubscriptionNotice } from "./subscriptionNotice";

/**
 * Tells a merchant, in one place, the billing fact they most need right now:
 * a plan that won't renew (and until when it still works), a payment hold, a
 * renewal that couldn't be confirmed, or a plan that has ended.
 */
export default function SubscriptionStatusBanner({
  subscription,
  onResubscribe,
  onRefresh,
  compact = false,
}: {
  subscription: SubscriptionLifecycle | null | undefined;
  onResubscribe?: () => void;
  onRefresh?: () => void;
  compact?: boolean;
}) {
  if (!subscription?.plan || !hasSubscriptionNotice(subscription, compact)) return null;

  const { plan } = subscription;
  const resubscribe = onResubscribe ? { content: `Resubscribe to ${plan.name}`, onAction: onResubscribe } : undefined;

  switch (subscription.state) {
    case "cancelled":
      return (
        <Banner
          tone="warning"
          title={`Your ${plan.name} subscription is cancelled and won't renew`}
          action={resubscribe}
        >
          <Text as="p">
            It was {reasonText(subscription.cancellation_reason)}
            {subscription.cancelled_at ? ` on ${longDate(subscription.cancelled_at)}` : ""}. You've already paid
            for the current billing period, so {plan.name} stays available until{" "}
            <Text as="span" fontWeight="semibold">
              {longDate(subscription.access_until)}
            </Text>
            . You won't be charged again. After that date your store moves to the Free plan.
          </Text>
        </Banner>
      );

    case "frozen":
      return (
        <Banner tone="critical" title={`Your ${plan.name} subscription is on hold`}>
          <Text as="p">
            Shopify has paused it because a Shopify bill for your store is unpaid. {plan.name} features are
            unavailable until the bill is paid in your Shopify admin (Settings → Billing); the subscription then
            resumes on its own.
          </Text>
        </Banner>
      );

    case "unknown":
      return (
        <Banner
          tone="warning"
          title="We couldn't confirm your subscription with Shopify"
          action={onRefresh ? { content: "Check again", onAction: onRefresh } : undefined}
        >
          <Text as="p">
            Your {plan.name} billing period ended on {longDate(subscription.current_period_end)} and we haven't been
            able to confirm the renewal since, so paid features are paused until it's confirmed. This is usually
            temporary.
          </Text>
        </Banner>
      );

    case "ended":
      return (
        <Banner tone="info" title={`Your ${plan.name} subscription has ended`} action={resubscribe}>
          <Text as="p">
            It was {reasonText(subscription.cancellation_reason)}
            {subscription.ended_at ? ` and ended on ${longDate(subscription.ended_at)}` : ""}. Your store is on
            the Free plan and you are not being charged. To use {plan.name} again, subscribe and approve the
            charge in Shopify.
          </Text>
        </Banner>
      );

    default:
      return null;
  }
}
