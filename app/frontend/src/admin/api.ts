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
  /**
   * Whether this server lets the app start its own worker. When false the host
   * has disabled proc_open and a cron really is the only answer — so the panel
   * must say that instead of offering a button that cannot work.
   */
  can_self_start: boolean;
  auto_worker_enabled: boolean;
}

export interface QueueActionResult {
  message: string;
  can_self_start?: boolean;
  started?: boolean;
  retried?: number;
  pending_jobs?: number;
  failed_jobs?: number;
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

/** What one shop currently holds for one entity type — what a reset acts on. */
export interface StoreEntityRow {
  type: string;
  total: number;
  success: number;
  failed: number;
  skipped: number;
  pending: number;
}

export type EntityResetMode = "resync" | "forget";

export interface EntityResetResult {
  entity_type: string;
  mode: EntityResetMode;
  affected: number;
  entities: StoreEntityRow[];
  message: string;
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

/**
 * The developer view of one run: what the SYSTEM did, as opposed to what
 * happened to the merchant's data.
 *
 * Every number here is summed from the per-job `debug` lines the engine writes
 * (see App\Support\MigrationDiagnostics), so it is empty for any stretch of a
 * run that executed with MIGRATION_DEBUG_LOG off — which is what
 * `debug_logging_enabled` is for.
 */
export interface MigrationDebug {
  migration_id: number;
  shop: string | null;
  status: string;
  started_at: string | null;
  completed_at: string | null;
  elapsed_seconds: number;
  debug_logging_enabled: boolean;
  /** Why N workers are busy when M are running — the per-entity ceiling. */
  concurrency: Record<string, string | number>;
  runtime: Record<string, string | number>;
  queue: { pending_jobs: number; failed_jobs: number };
  throughput: {
    entity: string;
    total: number;
    processed: number;
    successful: number;
    failed: number;
    records_per_minute: number | null;
  }[];
  totals: Record<string, number>;
  workers: {
    worker: string;
    jobs: number;
    seconds: number;
    last_seen: string;
    recent: boolean;
  }[];
  outcomes: Record<string, number>;
  slowest_records: { id: number; seconds: number; at: string }[];
  notes: Record<string, number>;
  batches: Record<string, Record<string, number>>;
  source_health: {
    status: string;
    average_seconds: number;
    requests: number;
    failures: number;
    message: string;
  } | null;
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
  /**
   * Content features, each metered on its own. null/absent = unlimited,
   * 0 = not included in this plan. `blogs` covers blogs AND their posts —
   * one feature to a merchant, so one number here.
   */
  coupons?: number | null;
  blogs?: number | null;
  reviews?: number | null;
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

  /** Start a background worker without an SSH session. */
  runQueue: () => request<QueueActionResult>("/queue/run", { method: "POST" }),

  /** Put abandoned jobs back on the queue. */
  retryFailedJobs: () =>
    request<QueueActionResult>("/queue/retry-failed", { method: "POST" }),

  stores: (page = 1, search = "") =>
    request<{ data: AdminStore[]; meta: PageMeta }>(
      `/stores?page=${page}&search=${encodeURIComponent(search)}`,
    ),
  store: (id: number) => request<any>(`/stores/${id}`),

  // Support tool: let one entity type be migrated again for one store.
  //
  // `resync` keeps the mapping rows in the state that makes a re-run ADOPT
  // whatever is already in Shopify; `forget` deletes them, so a re-run creates
  // everything again. The difference is duplicates, which is why the caller has
  // to name the mode rather than get a default it did not think about.
  resetStoreEntity: (id: number, entityType: string, mode: EntityResetMode) =>
    request<EntityResetResult>(`/stores/${id}/reset-entity`, {
      method: "POST",
      body: JSON.stringify({ entity_type: entityType, mode }),
    }),
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

  /** Live developer diagnostics for one run. */
  migrationDebug: (id: number) => request<MigrationDebug>(`/migrations/${id}/debug`),

  /**
   * The full developer log as a text file.
   *
   * Fetched rather than linked: the endpoint needs the admin bearer token, and a
   * plain new-tab open would send no Authorization header and 401.
   */
  downloadMigrationDebugLog: async (id: number) => {
    const res = await fetch(`${BASE}/migrations/${id}/debug-log`, {
      headers: { Authorization: `Bearer ${adminToken.get()}` },
    });

    if (!res.ok) {
      throw new Error(`Download failed (${res.status})`);
    }

    const url = URL.createObjectURL(await res.blob());
    const a = document.createElement("a");
    a.href = url;
    a.download = `store-shift-debug-migration-${id}.txt`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  },

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
