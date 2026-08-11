import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { BrowserRouter } from "react-router-dom";
import { AppProvider } from "@shopify/polaris";
import enTranslations from "@shopify/polaris/locales/en.json";
import "@shopify/polaris/build/esm/styles.css";
import App from "./App";

// Vite sets BASE_URL from vite.config `base` ("/app/" in production, "/" in dev).
// React Router needs it without the trailing slash so /app/admin resolves.
const basename = import.meta.env.BASE_URL.replace(/\/$/, "");

createRoot(document.getElementById("root")!).render(
  <StrictMode>
    <AppProvider i18n={enTranslations}>
      <BrowserRouter basename={basename}>
        <App />
      </BrowserRouter>
    </AppProvider>
  </StrictMode>
);
