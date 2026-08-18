// Who this browser tab is running the app AS — the one question that decides
// between the dashboard and the install prompt.
//
// It lives here rather than in either caller because App.tsx and api/client.ts
// were answering it SEPARATELY and disagreeing. The router asked "is `host` or
// `shop` on the query string, or is a shop in localStorage?" — and none of the
// three survives a client-side navigation in a browser that has never run the
// app before:
//
//   * Shopify puts `host`/`shop` on the FIRST url only; navigate("/migrations")
//     drops the whole query string.
//   * `shop_domain` was written by api/client.ts, but only down its NON-embedded
//     branch — so inside the Shopify admin, where App Bridge always answers, it
//     was never written at all.
//
// The result was the reported bug exactly: open the app in a new browser, click
// any nav item, and the marketing install prompt replaced the app. A reload
// fixed it because Shopify re-adds `host` when it reloads the iframe. In a
// browser that had used the app before, a stale `shop_domain` hid the whole
// thing.
//
// So the first load REMEMBERS what it was given (see capture(), called once from
// main.tsx before React mounts) and everything afterwards reads from here.

const SHOP_KEY = "shop_domain";
const HOST_KEY = "shopify_host";

/**
 * Written by the App Bridge bootstrap in index.html as well as by capture(),
 * which is why the name is shared rather than owned by this module.
 */
const EMBEDDED_KEY = "shopify_embedded";

/** Storage throws in a third-party iframe with cookies blocked; never fatal. */
function read(store: Storage, key: string): string | null {
  try {
    return store.getItem(key);
  } catch {
    return null;
  }
}

function write(store: Storage, key: string, value: string): void {
  try {
    store.setItem(key, value);
  } catch {
    /* the in-iframe test below still covers us */
  }
}

/** App Bridge's global, present only once its script has run in the admin. */
interface AppBridgeGlobal {
  idToken?: () => Promise<string>;
  config?: { shop?: string; host?: string };
}

export function appBridge(): AppBridgeGlobal | undefined {
  return (window as unknown as { shopify?: AppBridgeGlobal }).shopify;
}

/**
 * Remember whatever identifies this session, from wherever it is available.
 *
 * Called once at boot AND again on every read, because App Bridge may not have
 * finished loading when the first call happens — `shopify.config.shop` is the
 * only source that still works after a deep-link reload with no query string.
 */
export function capture(): void {
  const q = new URLSearchParams(window.location.search);
  const bridge = appBridge();

  const shop = q.get("shop") ?? bridge?.config?.shop;
  const host = q.get("host") ?? bridge?.config?.host;

  if (shop) write(localStorage, SHOP_KEY, shop);

  if (host) {
    write(sessionStorage, HOST_KEY, host);
    write(sessionStorage, EMBEDDED_KEY, "1");
  }

  // App Bridge itself is proof of the admin even when it exposes no config.
  if (bridge) write(sessionStorage, EMBEDDED_KEY, "1");
}

/** The shop this tab belongs to, or null. Used for the local-dev header auth. */
export function currentShop(): string | null {
  capture();

  return read(localStorage, SHOP_KEY);
}

/**
 * Are we running inside the Shopify admin?
 *
 * The iframe test is last and deliberately unconditional: a browser that blocks
 * storage for third-party frames leaves every remembered value null, and being
 * framed at all is then the only honest signal left.
 */
export function isEmbedded(): boolean {
  capture();

  return read(sessionStorage, EMBEDDED_KEY) === "1" || !!appBridge() || window.top !== window.self;
}

/**
 * Enough context to run the app. False means the app was opened cold, outside
 * Shopify, by someone who has never installed it — the only case that should
 * ever see the install prompt.
 */
export function hasShopContext(): boolean {
  return isEmbedded() || currentShop() !== null;
}
