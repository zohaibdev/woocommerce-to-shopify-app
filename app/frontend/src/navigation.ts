/**
 * Leave the Shopify admin iframe.
 *
 * `window.top.location.href = url` throws a SecurityError from inside a
 * cross-origin iframe, which is exactly where this app always runs — so the
 * "Update app" button did not redirect anywhere, it threw, React unmounted the
 * tree, and the merchant was left looking at a blank panel.
 *
 * App Bridge intercepts `window.open(url, "_top")` and performs the navigation
 * properly. The two fallbacks below cover a non-embedded load (straight after
 * OAuth, or opened directly), where plain top navigation is allowed.
 */
export function redirectTop(url: string): void {
  try {
    if (window.open(url, "_top")) return;
  } catch {
    /* not permitted here — try the next one */
  }

  try {
    if (window.top && window.top !== window.self) {
      window.top.location.href = url;

      return;
    }
  } catch {
    /* cross-origin, which is the normal case inside the admin */
  }

  window.location.href = url;
}

/**
 * Open a page outside the app in a new tab.
 *
 * Never navigates the current frame: these pages send no frame headers for the
 * Shopify admin, so loading one in place strands the merchant on a blank iframe
 * with no way back.
 */
export function openExternal(url: string): void {
  try {
    window.open(url, "_blank", "noopener,noreferrer");
  } catch {
    /* popup blocked — nothing useful left to try, and nothing to break */
  }
}
