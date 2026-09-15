import type { SubscriptionLifecycle } from "../api/client";

/**
 * Whether the banner has anything to say. Lets a page skip the layout section
 * entirely instead of rendering an empty gap.
 */
export function hasSubscriptionNotice(
  subscription: SubscriptionLifecycle | null | undefined,
  compact = false,
): boolean {
  if (!subscription?.plan) return false;
  if (subscription.state === "cancelled_grace") return true;
  if (subscription.state !== "ended") return false;
  if (!compact) return true;

  const endedOn = subscription.access_until ? new Date(subscription.access_until).getTime() : null;

  return endedOn !== null && Date.now() - endedOn < 30 * 86_400_000;
}
