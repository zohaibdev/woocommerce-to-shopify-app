import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter, Link as RouterLink } from "react-router-dom";
import { AppProvider } from "@shopify/polaris";
import type { LinkLikeComponentProps } from "@shopify/polaris/build/ts/src/utilities/link";
import enTranslations from "@shopify/polaris/locales/en.json";
import "@shopify/polaris/build/esm/styles.css";
import App from "./App";

// Vite sets BASE_URL from vite.config `base` ("/app/" in production, "/" in dev).
// React Router needs it without the trailing slash so /app/admin resolves.
const basename = import.meta.env.BASE_URL.replace(/\/$/, "");

/**
 * Routes every Polaris `url` prop (Page backAction, Banner action, Link, …)
 * through React Router.
 *
 * Without this Polaris renders a bare <a href="/migrations">, which ignores the
 * router basename — in production that lands on https://host/migrations instead
 * of /app/migrations and 404s. External links still open normally.
 */
function AppLink({ url, children, external, ...rest }: LinkLikeComponentProps) {
  const isExternal =
    external ||
    /^[a-z][a-z0-9+.-]*:/i.test(url) || // http:, https:, mailto:, tel: …
    url.startsWith("//");

  if (isExternal) {
    return (
      <a href={url} target="_blank" rel="noopener noreferrer" {...rest}>
        {children}
      </a>
    );
  }

  return (
    <RouterLink to={url} {...rest}>
      {children}
    </RouterLink>
  );
}

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    {/* Router must wrap AppProvider so linkComponent can use RouterLink. */}
    <BrowserRouter basename={basename}>
      <AppProvider i18n={enTranslations} linkComponent={AppLink}>
        <App />
      </AppProvider>
    </BrowserRouter>
  </StrictMode>
);
