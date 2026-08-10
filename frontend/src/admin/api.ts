// Super Admin API client. Uses its own bearer token (localStorage), entirely
// separate from the merchant app's Shopify session-token auth.

const BASE = (import.meta.env.VITE_API_URL ?? "/api") + "/admin";
const TOKEN_KEY = "store_shift_admin_token";

export const adminToken = {
  get: () => localStorage.getItem(TOKEN_KEY),
  set: (t: string) => localStorage.setItem(TOKEN_KEY, t),
  clear: () => localStorage.removeItem(TOKEN_KEY),
};

async function request<T>(path: string, options: RequestInit = {}): Promise<T> {
  const token = adminToken.get();
  const res = await fetch(`${BASE}${path}`, {
    ...options,
    headers: {
      "Content-Type": "application/json",
      Accept: "application/json",
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...(options.headers ?? {}),
    },
  });

  // A 401 on /login means bad credentials — surface the server's message.
  // A 401 elsewhere means the stored token is no longer valid.
  const isLogin = path === "/login";

  if (res.status === 401 && !isLogin) {
    adminToken.clear();
    throw new Error("Session expired — please sign in again.");
  }
  if (!res.ok) {
    let msg = `Request failed (${res.status})`;
    try {
      const body = await res.json();
      msg = body.error ?? body.message ?? msg;
    } catch {
      /* keep default */
    }
    throw new Error(msg);
  }
  return res.json() as Promise<T>;
}

// ---- Types ----
export interface PageMeta {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
  has_more: boolean;
}

export interface Overview {
  stores: number;
  stores_uninstalled: number;
  migrations: number;
  migrations_running: number;
  failed_items: number;
  open_tickets: number;
  active_subscriptions: number;
}

export interface AdminStore {
  id: number;
  shopify_domain: string;
  name: string | null;
  installed: boolean;
  installed_at: string | null;
  uninstalled_at: string | null;
  scopes: string | null;
  woocommerce: { store_url: string; status: string } | null;
  plan: string | null;
  plan_status: string | null;
  migrations_count: number;
  last_migration: { id: number; status: string; created_at: string } | null;
}

export interface AdminMigration {
  id: number;
  name: string | null;
  shop: string | null;
  shop_id: number;
  status: string;
  entities: string[];
  progress: {
    type: string;
    processed: number;
    total: number;
    successful?: number;
    failed: number;
    percentage?: number;
  }[];
  created_at: string;
  started_at: string | null;
  completed_at: string | null;
}

export interface ErrorLog {
  id: number;
  migration_id: number;
  shop: string | null;
  level: string;
  entity_type: string | null;
  message: string;
  created_at: string;
}

export interface Ticket {
  id: number;
  subject: string;
  status: string;
  priority: string;
  shop: string | null;
  shop_id: number | null;
  contact_email: string | null;
  replies_count: number;
  last_reply_at: string | null;
  created_at: string;
  message?: string;
  replies?: {
    id: number;
    author_type: string;
    author_name: string | null;
    message: string;
    created_at: string;
  }[];
}

export interface Content {
  id: number;
  type: string;
  title: string;
  body: string | null;
  url: string | null;
  position: number;
  enabled: boolean;
}

export interface Plan {
  id: number;
  code: string;
  name: string;
  price: string;
  interval: string;
  limits: Record<string, number | null> | null;
  active: boolean;
  active_subscriptions: number;
}

// ---- Endpoints ----
export const adminApi = {
  login: (email: string, password: string) =>
    request<{ token: string; admin: { id: number; name: string; email: string } }>("/login", {
      method: "POST",
      body: JSON.stringify({ email, password }),
    }),
  me: () => request<{ id: number; name: string; email: string }>("/me"),
  logout: () => request<{ ok: boolean }>("/logout", { method: "POST" }),

  overview: () => request<Overview>("/overview"),

  stores: (page = 1, search = "") =>
    request<{ data: AdminStore[]; meta: PageMeta }>(
      `/stores?page=${page}&search=${encodeURIComponent(search)}`,
    ),
  store: (id: number) => request<any>(`/stores/${id}`),
  plans: () => request<Plan[]>("/plans"),

  migrations: (page = 1, params: { shop_id?: number; status?: string } = {}) => {
    const q = new URLSearchParams({ page: String(page), ...(params as any) });
    return request<{ data: AdminMigration[]; meta: PageMeta }>(`/migrations?${q}`);
  },
  migration: (id: number) => request<any>(`/migrations/${id}`),

  errors: (page = 1, params: { shop_id?: number; migration_id?: number } = {}) => {
    const q = new URLSearchParams({ page: String(page), ...(params as any) });
    return request<{ data: ErrorLog[]; meta: PageMeta }>(`/errors?${q}`);
  },

  tickets: (page = 1, status = "") =>
    request<{ data: Ticket[]; meta: PageMeta }>(`/tickets?page=${page}&status=${status}`),
  ticket: (id: number) => request<Ticket>(`/tickets/${id}`),
  replyTicket: (id: number, message: string, status?: string) =>
    request<Ticket>(`/tickets/${id}/reply`, {
      method: "POST",
      body: JSON.stringify({ message, ...(status ? { status } : {}) }),
    }),
  updateTicket: (id: number, data: { status?: string; priority?: string }) =>
    request<Ticket>(`/tickets/${id}`, { method: "PATCH", body: JSON.stringify(data) }),

  content: () => request<{ data: Content[]; types: string[] }>("/content"),
  createContent: (data: Partial<Content>) =>
    request<Content>("/content", { method: "POST", body: JSON.stringify(data) }),
  updateContent: (id: number, data: Partial<Content>) =>
    request<Content>(`/content/${id}`, { method: "PATCH", body: JSON.stringify(data) }),
  deleteContent: (id: number) => request<{ ok: boolean }>(`/content/${id}`, { method: "DELETE" }),
  reorderContent: (order: number[]) =>
    request<{ ok: boolean }>("/content/reorder", {
      method: "POST",
      body: JSON.stringify({ order }),
    }),
};
