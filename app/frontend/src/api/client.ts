// Thin API client for the Laravel backend.
// In the embedded app, attach the Shopify session token to every request
// (via App Bridge getSessionToken). For local dev we fall back to no auth.

// Who this tab is, and whether it is inside the Shopify admin. Shared with the
// router (src/shopContext.ts) on purpose: the two used to answer separately and
// disagree, which is how a fresh browser got the install prompt on every
// client-side navigation.
import { appBridge, currentShop, isEmbedded } from "../shopContext";

// Derived from the deploy base ("/app/" in production, "/" in dev) so the API
// lives alongside the app: /app/api in production, /api locally.
const BASE_URL =
  import.meta.env.VITE_API_URL ?? `${import.meta.env.BASE_URL.replace(/\/$/, "")}/api`;

async function authHeader(): Promise<Record<string, string>> {
  // Embedded: authenticate with a fresh session token (JWT) per request.
  const sb = appBridge();

  if (sb?.idToken) {
    try {
      const token = await sb.idToken();

      return { Authorization: `Bearer ${token}` };
    } catch (e) {
      // App Bridge is here and REFUSED to mint a token. Falling through to the
      // dev fallback made that unreadable: the server rejects X-Shop-Domain in
      // production, so the merchant was told "Missing session token" — which
      // says the app forgot to send one, when in fact the admin declined to
      // issue one. The two have completely different fixes.
      throw new ApiError(
        401,
        "Shopify would not issue a session token for this page. Close the app and open it again from your " +
          "Shopify admin. If it keeps happening, reinstall the app. " +
          `(${(e as Error)?.message ?? "no reason given"})`,
      );
    }
  }

  // App Bridge should always be present in the admin. When it is not, the script
  // did not load — an ad blocker, a CSP, or the app opened outside the admin.
  // Saying so beats a 401 that reads as an expired login.
  if (isEmbedded()) {
    throw new ApiError(
      401,
      "Shopify App Bridge did not load, so this page cannot authenticate. Open the app from your Shopify " +
        "admin (Apps → Shop Shift) rather than from a bookmark, and check that no browser extension is " +
        "blocking cdn.shopify.com.",
    );
  }

  // Local-dev fallback (not embedded): identify the shop by header.
  const shop = currentShop();

  return shop ? { "X-Shop-Domain": shop } : {};
}

/** An API error that still knows its HTTP status, so callers can branch on it. */
export class ApiError extends Error {
  // Declared and assigned rather than a constructor parameter property, which
  // this project's `erasableSyntaxOnly` setting does not allow.
  readonly status: number;

  constructor(status: number, message: string) {
    super(message);
    this.name = "ApiError";
    this.status = status;
  }
}

/**
 * Pull the human sentence out of a Laravel error response.
 *
 * Banners used to print the raw body, so a merchant who started a second
 * migration was shown `API 409: {"message":"Migration #12 is still running…"}`
 * — the answer was in there, wrapped in something that reads as a crash.
 */
function errorMessage(status: number, body: string): string {
  try {
    const parsed = JSON.parse(body);
    const message =
      parsed?.message ||
      // Laravel validation: {"errors": {"field": ["reason"]}}
      (parsed?.errors && Object.values(parsed.errors as Record<string, string[]>)[0]?.[0]) ||
      // The WooCommerce connection endpoints answer 422 with the connection plus
      // an `error` explaining what the merchant's store actually said. Without
      // this the single most important message in the app — "HTTP 401: your API
      // key was revoked" — was thrown away and shown as "Request failed (422)".
      parsed?.error;

    // Billing failures carry a reference that support can find in the logs.
    if (typeof message === "string" && message.trim()) {
      return typeof parsed?.reference === "string" ? `${message} (Reference: ${parsed.reference.slice(0, 8)})` : message;
    }
  } catch {
    /* not JSON — fall through */
  }

  if (status === 401 || status === 403) {
    return "Your session expired. Reload the page to continue.";
  }
  if (status >= 500) {
    return "The server had a problem handling that. Please try again in a moment.";
  }

  return `Request failed (${status}).`;
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  let res: Response;

  // Resolved BEFORE the try, deliberately. Inside it, an authentication problem
  // authHeader() had just described precisely was caught by the network handler
  // below and rewritten as "Could not reach the server" — sending everyone to
  // check their wifi over a token the Shopify admin had declined to issue.
  const auth = await authHeader();

  try {
    res = await fetch(`${BASE_URL}${path}`, {
      ...options,
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        ...auth,
        ...(options.headers ?? {}),
      },
    });
  } catch {
    // Offline, DNS failure, the tunnel dropped. A polling page hits this
    // constantly on a flaky connection and it must not read as a bug.
    throw new ApiError(0, "Could not reach the server. Check your connection and try again.");
  }

  if (!res.ok) {
    throw new ApiError(res.status, errorMessage(res.status, await res.text()));
  }

  // 204, or a proxy that returns an empty body on success.
  const text = await res.text();

  if (!text) return undefined as T;

  try {
    return JSON.parse(text) as T;
  } catch {
    // Almost always a session-expiry HTML login page served with a 200.
    throw new ApiError(res.status, "The server returned an unexpected response. Reload the page.");
  }
}

// ---- Types ----
export interface Shop {
  shopify_domain: string;
  name: string | null;
  email: string | null;
  woocommerce_connected: boolean;
  granted_scopes: string[];
  required_scopes: string[];
  missing_scopes: string[];
  needs_scope_update: boolean;
  reauth_url: string;
  /** The run in flight, if any. A shop may only have one at a time. */
  active_migration_id: number | null;
  /** False when the app has no mail server, so "email me" cannot deliver. */
  email_delivery_configured: boolean;
}

export interface WooConnection {
  store_url: string;
  consumer_key_masked: string;
  status: "connected" | "error" | "disconnected";
  wc_version: string | null;
  wp_version: string | null;
  last_tested_at: string | null;
}

/**
 * A CORE entity this store would not count, while others counted fine.
 *
 * Distinct from `source_error` on purpose. A refusal on one endpoint while the
 * same API key reads four others is not a credentials problem by definition, so
 * it must not be rendered as one — overwhelmingly it is a security plugin, WAF
 * or rate limiter blocking a single path.
 */
export interface BlockedEntity {
  type: string;
  /** HTTP status WooCommerce (or whatever refused it) answered with. */
  status: number | null;
  reason: string;
}

export interface EntityCount {
  type: string;
  /** How many records exist in WooCommerce. */
  count: number;
  /** Plan cap: null = unlimited, 0 = the entity is not in the plan at all. */
  limit: number | null;
  /** Already migrated successfully for this shop. */
  used: number;
  remaining: number | null;
  /** How many this run would actually migrate (count capped by the plan). */
  migratable: number;
  /** The plan does not include this entity. */
  locked: boolean;
  /** The cap is spent and there is still source data it can never reach. */
  exhausted: boolean;
  /**
   * This entity draws on the plan's SHARED record budget rather than a cap of
   * its own — so its number can be cut short by what another entity took first,
   * which is not something the merchant can work out from this row alone.
   */
  pooled: boolean;
  reason: string | null;
  /**
   * This entity's own share of the time estimate, for the records it will
   * actually migrate. Per entity so the summary can answer for whatever the
   * merchant ticked — the preview is computed before they tick anything. Safe
   * to sum: the spread across parallel workers is a division.
   */
  estimated_seconds: number;
}

/**
 * One record budget spent across products, customers and orders together, rather
 * than a separate cap for each. Null on plans that meter per entity.
 */
export interface PlanPool {
  limit: number;
  /** Distinct records this shop has already migrated. Re-running is free. */
  used: number;
  remaining: number;
  /** Entity types that draw on it — categories deliberately do not. */
  entities: string[];
}

export interface EntityProgress {
  type: string;
  total: number;
  processed: number;
  successful: number;
  failed: number;
  percentage: number;
}

/**
 * How the merchant's own WooCommerce store is holding up. Present only while a
 * migration is running — a slow source is the most common reason a run crawls,
 * and it is not something the merchant can otherwise see.
 */
export interface SourceHealth {
  status: "healthy" | "slow" | "very_slow" | "failing";
  average_seconds: number;
  requests: number;
  failures: number;
  message: string;
}

/**
 * How long a migration will take.
 *
 * `basis` is the part that matters to how it is worded. "plan" is modelled from
 * record counts and this shop's Shopify parallelism — a rough shape, useful for
 * telling minutes from overnight. "progress" is derived from the run's OWN
 * elapsed time, so it already accounts for the merchant's WooCommerce host,
 * their image sizes and their plan. Presenting a modelled number as if it were
 * measured is how an estimate loses a merchant's trust for good.
 */
export interface PlanEstimate {
  seconds: number;
  basis: "plan";
  workers: number;
}

export interface RunEstimate {
  seconds_remaining: number;
  basis: "plan" | "progress";
  workers: number;
  records_per_minute: number | null;
}

export interface Migration {
  id: number;
  name: string | null;
  status:
    | "pending"
    | "processing"
    | "completed"
    | "completed_with_errors"
    | "failed"
    | "cancelled"
    | "paused";
  selected_entities: string[];
  entities: EntityProgress[];
  started_at: string | null;
  completed_at: string | null;
  created_at: string;
  source_health: SourceHealth | null;
  /** Only while the run is active — afterwards it describes nothing. */
  estimate: RunEstimate | null;
  /**
   * Chunks whose records were never read from WooCommerce at all. They produce
   * no failed ITEMS, so this is the only signal that part of the catalogue is
   * missing — and the only reason to offer a retry when the item list is empty.
   */
  failed_batches: number;
}

export interface ContentItem {
  id: number;
  type: string;
  title: string;
  body: string | null;
  url: string | null;
}

export interface MerchantTicket {
  id: number;
  subject: string;
  status: string;
  created_at: string;
  replies: {
    author_type: string;
    author_name: string | null;
    message: string;
    created_at: string;
  }[];
}

export interface PlanLimits {
  products?: number | null;
  customers?: number | null;
  orders?: number | null;
  metafields?: boolean;
  /**
   * One record budget shared across products, customers and orders, instead of
   * a cap per entity. When present it is what actually meters the plan.
   */
  total?: number | null;
  /** Arranged with us directly, not buyable through Shopify billing. */
  contact_only?: boolean;
  /**
   * Content features, metered on their own and never out of the record budget
   * above. null/absent = unlimited, 0 = not included in this plan.
   * `blogs` covers blogs AND their posts — one feature, one number.
   */
  coupons?: number | null;
  blogs?: number | null;
  reviews?: number | null;
}

export interface BillingPlan {
  code: string;
  name: string;
  price: number;
  interval: string;
  limits: PlanLimits;
  is_current: boolean;
}

/** Shopify's two recurring intervals (AppPricingInterval). */
export type BillingInterval = "EVERY_30_DAYS" | "ANNUAL";

export type BillingState = "active" | "frozen" | "unknown" | "cancelled" | "pending" | "ended" | "free";

/**
 * The shop's billing, exactly as the server reconciled it with Shopify. The admin
 * panel renders the same object. Every date is one Shopify reported or an event
 * that was recorded — a date Shopify doesn't provide is null, never estimated.
 *
 * - `active`    paying; renews on `renews_at`
 * - `frozen`    Shopify put it on hold for an unpaid Shopify bill; paid features paused
 * - `unknown`   renewal couldn't be confirmed with Shopify for a week; paid features paused
 * - `cancelled` cancelled and won't renew, but already paid for — plan kept until `access_until`
 * - `pending`   first subscription waiting for approval in Shopify
 * - `ended`     a paid plan has ended; on the free plan
 * - `free`      never had a paid plan
 */
export interface SubscriptionLifecycle {
  state: BillingState;
  status: string | null;
  shopify_status: string | null;
  plan: { code: string; name: string; price: number } | null;
  price: number | null;
  currency: string | null;
  interval: BillingInterval | null;
  test: boolean;
  trial_days: number | null;
  created_at: string | null;
  started_at: string | null;
  current_period_end: string | null;
  renews_at: string | null;
  cancelled_at: string | null;
  cancellation_reason: string | null;
  access_until: string | null;
  ended_at: string | null;
  entitled_plan: { code: string; name: string; price: number } | null;
  previous: {
    plan: { code: string; name: string; price: number } | null;
    status: string;
    ended_at: string | null;
    reason: string | null;
  } | null;
  pending_change: {
    plan: { code: string; name: string; price: number } | null;
    price: number;
    interval: BillingInterval | null;
    created_at: string | null;
    /** awaiting_approval: not approved yet. processing: approved, Shopify hasn't activated it. scheduled: deferred to the next cycle. */
    status: "awaiting_approval" | "processing" | "scheduled";
    effective_at: string | null;
    expires_at: string | null;
  } | null;
  sync: { last_synced_at: string | null; failing: boolean };
  actions: {
    can_cancel: boolean;
    can_change_plan: boolean;
    can_resubscribe: boolean;
    can_continue_checkout: boolean;
  };
  /** What "Cancel subscription" would do right now. access_until null => ends immediately. */
  cancellation: { prorate: boolean; access_until: string | null };
}

export interface BillingHistoryEntry {
  id: number;
  event: string;
  plan?: string;
  status?: string;
  message?: string;
  occurred_at: string;
  access_until?: string;
  next_billing?: string;
}

export interface PlanChangePreview {
  type: "new" | "upgrade" | "downgrade" | "switch" | "current" | "cancel" | "current_free";
  timing: "on_approval" | "next_billing_cycle" | "on_cancel" | null;
  proration: "charge_difference" | "credit" | "none";
  effective_at: string | null;
}

export interface BillingPlan {
  code: string;
  name: string;
  price: number;
  interval: string;
  limits: PlanLimits;
  is_current: boolean;
  change: PlanChangePreview | null;
}

export interface BillingInfo {
  current_plan: { code: string; name: string; price: number; interval: string; limits: PlanLimits } | null;
  subscription_status: string | null;
  subscription: SubscriptionLifecycle;
  history: BillingHistoryEntry[];
  plans: BillingPlan[];
}

export interface SubscribeResult extends BillingInfo {
  result: "confirmation_required" | "cancelled" | "noop";
  confirmation_url: string | null;
  reused: boolean;
  plan: string;
}

export interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  has_more: boolean;
}

/**
 * The migration list also reports the shop's in-flight run, so the page can hide
 * "New migration" without having to guess from whichever page of results it
 * happens to be showing.
 */
export interface MigrationListMeta extends PageMeta {
  active_migration_id: number | null;
}

export interface MigrationLogEntry {
  id: number;
  level: "info" | "warning" | "error";
  entity_type: string | null;
  message: string;
  created_at: string;
}

export interface MigrationItem {
  id: number;
  entity_type: string;
  woocommerce_id: number;
  shopify_id: string | null;
  status: "pending" | "success" | "failed" | "skipped";
  error: string | null;
}

// ---- Endpoints ----
export const api = {
  getShop: () => request<Shop>("/shop"),

  connectWoo: (data: { store_url: string; consumer_key: string; consumer_secret: string }) =>
    request<WooConnection>("/woocommerce/connect", { method: "POST", body: JSON.stringify(data) }),

  testWoo: () => request<WooConnection>("/woocommerce/test", { method: "POST" }),

  /** The stored connection, without touching the merchant's store. Null if none. */
  getWooConnection: () => request<WooConnection | null>("/woocommerce/connection"),

  preview: () =>
    request<{
      counts: EntityCount[];
      plan: { code: string; name: string } | null;
      /** Shared record budget, or null when the plan meters each entity. */
      pool: PlanPool | null;
      /** Null when there is nothing to migrate. */
      estimate: PlanEstimate | null;
      shop_email: string | null;
      email_delivery_configured: boolean;
      active_migration_id: number | null;
      /** Set when the counts could not be read — expired API keys, store down. */
      source_error: string | null;
      /**
       * Optional endpoints this store does not expose — usually wp/v2 (blogs,
       * posts, pages) behind a security plugin. Not an error; those entities
       * simply cannot be offered.
       */
      unavailable_entities?: string[];
      /**
       * Core entities that were refused while others read fine. `source_error`
       * stays null in that case — the store is demonstrably readable.
       */
      blocked_entities?: BlockedEntity[];
    }>("/woocommerce/preview"),

  listMigrations: (page = 1, perPage = 10) =>
    request<{ data: Migration[]; meta: MigrationListMeta }>(
      `/migrations?page=${page}&per_page=${perPage}`,
    ),

  createMigration: (opts: {
    entities: string[];
    customTags?: string[];
    defaultVendor?: string;
    name?: string;
    weightUnit?: string;
    /** standard (1024px) | large (1536px) | full (original files). */
    imageSize?: string;
    /**
     * Copy images embedded in descriptions onto Shopify too, so a description
     * does not break when the WooCommerce store is switched off. Costs an upload
     * per new image, so it is opt-in.
     */
    rehostDescriptionImages?: boolean;
    /** Blank = no email when the migration finishes. */
    notifyEmail?: string;
  }) =>
    request<Migration>("/migrations", {
      method: "POST",
      body: JSON.stringify({
        entities: opts.entities,
        custom_tags: opts.customTags ?? [],
        default_vendor: opts.defaultVendor ?? "",
        name: opts.name ?? "",
        weight_unit: opts.weightUnit ?? "KILOGRAMS",
        image_size: opts.imageSize ?? "standard",
        rehost_description_images: opts.rehostDescriptionImages ?? false,
        notify_email: opts.notifyEmail ?? "",
      }),
    }),

  // --- Billing ---
  /** refresh: ask Shopify now instead of using a recent sync (the server rate-limits it). */
  billing: (refresh = false) =>
    request<BillingInfo>(`/billing${refresh ? "?refresh=1" : ""}`, { cache: "no-store" }),

  subscribe: (plan: string) =>
    request<SubscribeResult>("/billing/subscribe", {
      method: "POST",
      body: JSON.stringify({ plan }),
    }),

  cancelPlan: () => request<BillingInfo & { result: string }>("/billing/cancel", { method: "POST" }),

  // Admin-managed dashboard content (videos, links, resources).
  // no-store: admin edits must show up on the next load, never from cache.
  content: () =>
    request<{ data: ContentItem[]; grouped: Record<string, ContentItem[]> }>("/content", {
      cache: "no-store",
    }),

  createTicket: (data: {
    subject: string;
    message: string;
    contact_email?: string;
    migration_id?: number;
  }) => request<{ id: number; status: string }>("/support/tickets", {
    method: "POST",
    body: JSON.stringify(data),
  }),

  myTickets: () => request<{ data: MerchantTicket[] }>("/support/tickets"),

  // Fetches the log WITH the shop header (a plain new-tab open would drop it and
  // resolve the wrong tenant -> 404), then triggers a browser download.
  downloadLog: async (id: number) => {
    const res = await fetch(`${BASE_URL}/migrations/${id}/download`, {
      headers: await authHeader(),
    });
    if (!res.ok) {
      throw new Error(`Download failed (${res.status})`);
    }
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `migration-${id}-log.txt`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  },

  getMigration: (id: number) => request<Migration>(`/migrations/${id}`),

  getItems: (
    id: number,
    params: { entity?: string; status?: string; page?: number; per_page?: number } = {},
  ) => {
    const q = new URLSearchParams(
      Object.fromEntries(Object.entries(params).map(([k, v]) => [k, String(v)])),
    ).toString();
    return request<{ data: MigrationItem[]; meta: PageMeta }>(`/migrations/${id}/items?${q}`);
  },

  replyToTicket: (id: number, message: string) =>
    request<{ author_type: string; author_name: string | null; message: string; created_at: string }>(
      `/support/tickets/${id}/reply`,
      { method: "POST", body: JSON.stringify({ message }) },
    ),

  getLogs: (id: number, limit = 100) =>
    request<{ data: MigrationLogEntry[] }>(`/migrations/${id}/logs?limit=${limit}`, {
      cache: "no-store",
    }),

  cancelMigration: (id: number) =>
    request<Migration>(`/migrations/${id}/cancel`, { method: "POST" }),

  retryFailed: (id: number) =>
    request<Migration>(`/migrations/${id}/retry-failed`, { method: "POST" }),
};
