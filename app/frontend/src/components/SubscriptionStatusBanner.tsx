import { Banner, Text } from "@shopify/polaris";
import type { SubscriptionLifecycle } from "../api/client";
import { hasSubscriptionNotice } from "./subscriptionNotice";

const formatDate = (iso: string | null) =>
  iso
    ? new Date(iso).toLocaleDateString(undefined, { year: "numeric", month: "long", day: "numeric" })
    : null;

const days = (n: number) => `${n} day${n === 1 ? "" : "s"}`;

/** Why the subscription stopped, in the merchant's terms. */
function cancelledBecause(sub: SubscriptionLifecycle): string {
  const on = formatDate(sub.cancelled_at);
  const when = on ? ` on ${on}` : "";

  switch (sub.cancellation_reason) {
    case "uninstalled":
      return `was cancelled when the app was uninstalled${when}`;
    case "merchant":
      return `was cancelled${when}`;
    case "expired":
      return `expired${when}`;
    case "frozen":
      return `was paused by Shopify${when}`;
    default:
      return `was cancelled${when}`;
  }
}

/**
 * Tells a merchant what happened to a paid subscription they no longer pay for.
 *
 * App Store review 1.2.2 rejected the app because, after uninstalling and
 * reinstalling, nothing said the paid plan had been cancelled or how long the
 * merchant still had it. Shopify cancels every app charge on uninstall, so a
 * reinstall always lands here with something to explain.
 *
 * `compact` is for the home page: it only speaks up while the plan is still
 * running on paid time, or in the month after it ran out.
 */
export default function SubscriptionStatusBanner({
  subscription,
  onResubscribe,
  compact = false,
}: {
  subscription: SubscriptionLifecycle | null | undefined;
  onResubscribe?: () => void;
  compact?: boolean;
}) {
  if (!subscription?.plan || !hasSubscriptionNotice(subscription, compact)) return null;

  const { state, plan } = subscription;
  const action = onResubscribe
    ? { content: `Resubscribe to ${plan.name}`, onAction: onResubscribe }
    : undefined;

  if (state === "cancelled_grace") {
    const until = formatDate(subscription.access_until);

    return (
      <Banner
        tone="warning"
        title={`Your ${plan.name} subscription ${cancelledBecause(subscription)}`}
        action={action}
      >
        <Text as="p">
          You have already paid for this period, so {plan.name} features stay available until{" "}
          <Text as="span" fontWeight="semibold">
            {until}
          </Text>{" "}
          ({days(subscription.days_remaining)} remaining). After that your store moves to the Free
          plan. To keep {plan.name}, approve the subscription again — you won't be charged until the
          time you've already paid for runs out.
        </Text>
      </Banner>
    );
  }

  if (state === "ended") {
    const endedOn = subscription.access_until ? new Date(subscription.access_until) : null;

    return (
      <Banner
        tone="info"
        title={`Your ${plan.name} subscription ${cancelledBecause(subscription)}`}
        action={action}
      >
        <Text as="p">
          {endedOn ? `It ended on ${formatDate(subscription.access_until)}, and y` : "Y"}our store is
          now on the Free plan. Choose a plan to approve a new subscription through Shopify.
        </Text>
      </Banner>
    );
  }

  return null;
}
