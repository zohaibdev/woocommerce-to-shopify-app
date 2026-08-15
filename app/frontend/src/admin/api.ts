// Super Admin API client. Uses its own bearer token (localStorage), entirely
// separate from the merchant app's Shopify session-token auth.

const BASE =
  (import.meta.env.VITE_API_URL ??
    `${import.meta.env.BASE_URL.replace(/\/$/, "")}/api`) + "/admin";
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

export interface BrandingSettings {
  /** Shown at the top of emails when there is no logo. */
  name: string;
  has_logo: boolean;
  logo_url: string | null;
}

export interface MailSettings {
  host: string | null;
  port: number;
  username: string | null;
  scheme: "smtp" | "smtps";
  from_address: string | null;
  from_name: string | null;
  /** The password is never sent to the client — this only says one exists. */
  has_password: boolean;
  /** True once SMTP has been saved here (rather than inherited from .env). */
  configured: boolean;
}

export interface MailSettingsInput {
  host: string;
  port: number;
  username: string;
  /** Blank keeps the stored password. */
  password: string;
  scheme: "smtp" | "smtps";
  from_address: string;
  from_name: string;
}

export interface QueueHealth {
  pending_jobs: number;
  failed_jobs: number;
  oldest_pending_seconds: number | null;
  worker_stalled: boolean;
}

export interface Overview {
  queue: QueueHealth;
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

export interface PlanLimits {
  products: number | null;
  customers: number | null;
  orders: number | null;
  metafields: boolean;
  /**
   * One record budget shared across products, customers and orders instead of a
   * cap on each. Null = meter per entity. Both compose; the tighter one wins.
   */
  total?: number | null;
  /** Negotiated off-platform — not buyable through Shopify billing. */
  contact_only?: boolean;
}

export interface Plan {
  id: number;
  code: string;
  name: string;
  price: number;
  interval: string;
  limits: PlanLimits;
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
  plans: () => request<{ data: Plan[] }>("/plans"),
  createPlan: (data: Partial<Plan>) =>
    request<Plan>("/plans", { method: "POST", body: JSON.stringify(data) }),
  updatePlan: (id: number, data: Partial<Plan>) =>
    request<Plan>(`/plans/${id}`, { method: "PATCH", body: JSON.stringify(data) }),
  deletePlan: (id: number) => request<{ ok: boolean }>(`/plans/${id}`, { method: "DELETE" }),

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

  branding: () => request<BrandingSettings>("/settings/branding"),

  /** Multipart — do NOT set Content-Type, the browser adds the boundary. */
  saveBranding: async (data: { logo?: File; name?: string; removeLogo?: boolean }) => {
    const body = new FormData();
    if (data.logo) body.append("logo", data.logo);
    if (data.name !== undefined) body.append("name", data.name);
    if (data.removeLogo) body.append("remove_logo", "1");

    const res = await fetch(`${BASE}/settings/branding`, {
      method: "POST",
      headers: { Accept: "application/json", Authorization: `Bearer ${adminToken.get()}` },
      body,
    });

    if (!res.ok) {
      let msg = `Upload failed (${res.status})`;
      try {
        const b = await res.json();
        msg = b.message ?? b.error ?? msg;
      } catch {
        /* keep default */
      }
      throw new Error(msg);
    }

    return (await res.json()) as BrandingSettings;
  },

  mailSettings: () => request<MailSettings>("/settings/mail"),
  saveMailSettings: (data: MailSettingsInput) =>
    request<MailSettings>("/settings/mail", { method: "PUT", body: JSON.stringify(data) }),
  testMailSettings: (to: string) =>
    request<{ sent_to: string }>("/settings/mail/test", {
      method: "POST",
      body: JSON.stringify({ to }),
    }),

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
