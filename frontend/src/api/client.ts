// Thin API client for the Laravel backend.
// In the embedded app, attach the Shopify session token to every request
// (via App Bridge getSessionToken). For local dev we fall back to no auth.

const BASE_URL = import.meta.env.VITE_API_URL ?? "/api";

// After OAuth the app is loaded at /?shop=xxx.myshopify.com. Persist that shop
// so subsequent API calls target the correct tenant. In a fully embedded app
// this is replaced by an App Bridge session token (JWT); see SPECIFICATION §8.
function currentShop(): string | null {
  const fromUrl = new URLSearchParams(window.location.search).get("shop");
  if (fromUrl) {
    localStorage.setItem("shop_domain", fromUrl);
    return fromUrl;
  }
  return localStorage.getItem("shop_domain");
}

// App Bridge (loaded via cdn.shopify.com/shopifycloud/app-bridge.js) exposes a
// global `shopify` object when the app is embedded in the Shopify admin.
interface AppBridge {
  idToken?: () => Promise<string>;
}
function appBridge(): AppBridge | undefined {
  return (window as unknown as { shopify?: AppBridge }).shopify;
}

async function authHeader(): Promise<Record<string, string>> {
  // Embedded: authenticate with a fresh session token (JWT) per request.
  const sb = appBridge();
  if (sb?.idToken) {
    try {
      const token = await sb.idToken();
      return { Authorization: `Bearer ${token}` };
    } catch {
      /* fall through to dev fallback */
    }
  }
  // Local-dev fallback (not embedded): identify the shop by header.
  const shop = currentShop();
  return shop ? { "X-Shop-Domain": shop } : {};
}

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const res = await fetch(`${BASE_URL}${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(await authHeader()),
      ...(options.headers ?? {}),
    },
  });
  if (!res.ok) {
    const body = await res.text();
    throw new Error(`API ${res.status}: ${body}`);
  }
  return res.json() as Promise<T>;
}

// ---- Types ----
export interface Shop {
  shopify_domain: string;
  name: string | null;
  woocommerce_connected: boolean;
  granted_scopes: string[];
  required_scopes: string[];
  missing_scopes: string[];
  needs_scope_update: boolean;
  reauth_url: string;
}

export interface WooConnection {
  store_url: string;
  consumer_key_masked: string;
  status: "connected" | "error" | "disconnected";
  wc_version: string | null;
  wp_version: string | null;
  last_tested_at: string | null;
}

export interface EntityCount {
  type: string;
  count: number;
}

export interface EntityProgress {
  type: string;
  total: number;
  processed: number;
  successful: number;
  failed: number;
  percentage: number;
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

export interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  has_more: boolean;
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

  preview: () => request<{ counts: EntityCount[] }>("/woocommerce/preview"),

  listMigrations: (page = 1, perPage = 10) =>
    request<{ data: Migration[]; meta: PageMeta }>(
      `/migrations?page=${page}&per_page=${perPage}`,
    ),

  createMigration: (opts: {
    entities: string[];
    customTags?: string[];
    defaultVendor?: string;
    name?: string;
  }) =>
    request<Migration>("/migrations", {
      method: "POST",
      body: JSON.stringify({
        entities: opts.entities,
        custom_tags: opts.customTags ?? [],
        default_vendor: opts.defaultVendor ?? "",
        name: opts.name ?? "",
      }),
    }),

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

  getItems: (id: number, params: { entity?: string; status?: string } = {}) => {
    const q = new URLSearchParams(params as Record<string, string>).toString();
    return request<{ data: MigrationItem[] }>(`/migrations/${id}/items?${q}`);
  },

  cancelMigration: (id: number) =>
    request<Migration>(`/migrations/${id}/cancel`, { method: "POST" }),

  retryFailed: (id: number) =>
    request<Migration>(`/migrations/${id}/retry-failed`, { method: "POST" }),
};
