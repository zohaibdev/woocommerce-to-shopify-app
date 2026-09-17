/**
 * Billing wording and formatting shared by the merchant app and the admin panel,
 * so the two can never describe the same subscription differently.
 */

type Tone = "success" | "attention" | "warning" | "critical" | "info" | undefined;

export function longDate(iso: string | null | undefined): string | null {
  return iso
    ? new Date(iso).toLocaleDateString(undefined, { year: "numeric", month: "long", day: "numeric" })
    : null;
}

export function shortDate(iso: string | null | undefined): string | null {
  return iso
    ? new Date(iso).toLocaleDateString(undefined, { year: "numeric", month: "short", day: "numeric" })
    : null;
}

export function dateTime(iso: string | null | undefined): string | null {
  return iso ? new Date(iso).toLocaleString() : null;
}

/** Shopify's AppPricingInterval, or a plan's own monthly/yearly setting. */
export function intervalLabel(interval: string | null | undefined): string {
  switch (interval) {
    case "ANNUAL":
    case "yearly":
      return "per year";
    default:
      // Shopify bills EVERY_30_DAYS — not per calendar month.
      return "every 30 days";
  }
}

export function priceLabel(price: number | null | undefined, interval: string | null | undefined, currency = "USD"): string {
  if (price === null || price === undefined) return "—";
  if (price === 0) return "Free";

  const amount = new Intl.NumberFormat(undefined, { style: "currency", currency, minimumFractionDigits: price % 1 ? 2 : 0 }).format(price);

  return `${amount} ${intervalLabel(interval)}`;
}

export const STATE_LABEL: Record<string, { label: string; tone: Tone }> = {
  active: { label: "Active", tone: "success" },
  frozen: { label: "On hold — payment issue", tone: "critical" },
  unknown: { label: "Needs confirmation", tone: "warning" },
  cancelled: { label: "Cancelled — won't renew", tone: "attention" },
  pending: { label: "Waiting for approval", tone: "info" },
  ended: { label: "Ended", tone: undefined },
  free: { label: "Free plan", tone: undefined },
};

export function reasonText(reason: string | null | undefined): string {
  switch (reason) {
    case "merchant":
      return "cancelled by you";
    case "admin":
      return "cancelled by Store Shift support";
    case "uninstalled":
      return "cancelled when the app was uninstalled";
    case "replaced":
      return "replaced by another plan";
    case "shopify":
      return "cancelled in Shopify";
    case "expired":
      return "expired";
    case "declined":
      return "declined";
    case "not_approved":
      return "not approved in time";
    default:
      return reason ? reason.replace(/_/g, " ") : "ended";
  }
}

export const EVENT_LABEL: Record<string, string> = {
  checkout_started: "Subscription requested",
  checkout_declined: "Subscription declined",
  checkout_expired: "Approval expired",
  subscription_activated: "Subscription started",
  plan_changed: "Plan changed",
  previous_plan_ended: "Previous plan ended",
  plan_change_scheduled: "Plan change scheduled",
  subscription_renewed: "Renewed",
  cancellation_requested: "Cancellation requested",
  subscription_cancelled: "Subscription cancelled",
  subscription_frozen: "Put on hold",
  subscription_unfrozen: "Payment resumed",
  subscription_expired: "Subscription expired",
  app_reinstalled: "App reinstalled",
  billing_sync_failed: "Shopify sync failed",
  billing_sync_unmatched: "Unmatched Shopify subscription",
  billing_operation_failed: "Billing operation failed",
};

export function eventLabel(event: string): string {
  return EVENT_LABEL[event] ?? event.replace(/_/g, " ");
}
