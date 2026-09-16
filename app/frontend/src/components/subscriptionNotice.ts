import type { SubscriptionLifecycle } from "../api/client";

/**
 * Whether the status banner has anything to say. Lets a page skip the layout
 * section entirely instead of rendering an empty gap.
 *
 * Worth interrupting for: a plan that won't renew, a payment hold, an
 * unconfirmed renewal, or a plan that ended. `compact` (the home page) stops
 * mentioning an ended plan 30 days after it ended.
 */
export function hasSubscriptionNotice(
  subscription: SubscriptionLifecycle | null | undefined,
  compact = false,
): boolean {
  if (!subscription?.plan) return false;

  switch (subscription.state) {
    case "cancelled":
    case "frozen":
    case "unknown":
      return true;
    case "ended": {
      if (!compact) return true;
      const endedAt = subscription.ended_at ?? subscription.cancelled_at;

      return endedAt !== null && Date.now() - new Date(endedAt).getTime() < 30 * 86_400_000;
    }
    default:
      return false;
  }
}
