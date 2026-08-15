import { useEffect } from "react";
import { Frame, Navigation, FooterHelp, Link, Text } from "@shopify/polaris";
import {
  HomeIcon,
  OrderIcon,
  StoreIcon,
  SettingsIcon,
  QuestionCircleIcon,
  CreditCardIcon,
} from "@shopify/polaris-icons";
import { Routes, Route, useNavigate, useLocation } from "react-router-dom";
import Dashboard from "./pages/Dashboard";
import WooCommerceConnection from "./pages/WooCommerceConnection";
import NewMigration from "./pages/NewMigration";
import MigrationHistory from "./pages/MigrationHistory";
import MigrationDetails from "./pages/MigrationDetails";
import Settings from "./pages/Settings";
import Support from "./pages/Support";
import InstallPrompt from "./pages/InstallPrompt";
import Pricing from "./pages/Pricing";
import AdminApp from "./admin/AdminApp";

/**
 * True when the app was opened with Shopify context — embedded in the admin
 * (`host`), straight after OAuth (`shop`), or by a merchant who has used it
 * before (remembered shop). Without it every API call 401s, so we show the
 * install prompt instead of a dashboard that cannot load.
 */
/**
 * Open a page of the public marketing site (docs, contact) in a new tab.
 *
 * The app is served from /app/ on the same host, so the public pages sit at the
 * origin root. `_blank` is required: these pages set no frame headers for the
 * Shopify admin, and navigating the embedded iframe away from the app would
 * strand the merchant.
 */
function openPublicPage(path: string): void {
  window.open(`${window.location.origin}${path}`, "_blank", "noopener");
}

function hasShopContext(): boolean {
  const q = new URLSearchParams(window.location.search);
  return q.has("host") || q.has("shop") || !!localStorage.getItem("shop_domain");
}

function App() {
  const navigate = useNavigate();
  const location = useLocation();

  // Shopify returns the merchant to the app root after a charge is approved or
  // declined, but only the Pricing page knows what `?billing=` means — so the
  // outcome was never shown. Take them there, keeping the flag.
  useEffect(() => {
    const billing = new URLSearchParams(location.search).get("billing");
    if (billing && location.pathname !== "/pricing") {
      navigate(`/pricing${location.search}`, { replace: true });
    }
  }, [location.pathname, location.search, navigate]);

  // The Super Admin dashboard is a separate surface with its own auth and chrome.
  if (location.pathname.startsWith("/admin")) {
    return <AdminApp />;
  }

  // Opened directly (not from Shopify) — offer installation instead of a
  // dashboard that would just render a 401.
  if (!hasShopContext()) {
    return <InstallPrompt />;
  }

  const navMarkup = (
    <Navigation location={location.pathname}>
      <Navigation.Section
        items={[
          {
            label: "Home",
            icon: HomeIcon,
            onClick: () => navigate("/"),
            selected: location.pathname === "/",
          },
          {
            label: "Migrations",
            icon: OrderIcon,
            onClick: () => navigate("/migrations"),
            selected: location.pathname.startsWith("/migrations"),
          },
          {
            label: "WooCommerce",
            icon: StoreIcon,
            onClick: () => navigate("/woocommerce"),
            selected: location.pathname === "/woocommerce",
          },
          {
            label: "Plans & pricing",
            icon: CreditCardIcon,
            onClick: () => navigate("/pricing"),
            selected: location.pathname === "/pricing",
          },
          {
            label: "Support",
            icon: QuestionCircleIcon,
            onClick: () => navigate("/support"),
            selected: location.pathname === "/support",
          },
          {
            label: "Settings",
            icon: SettingsIcon,
            onClick: () => navigate("/settings"),
            selected: location.pathname === "/settings",
          },
        ]}
      />
    </Navigation>
  );

  return (
    <Frame navigation={navMarkup}>
      <Routes>
        <Route path="/" element={<Dashboard />} />
        <Route path="/woocommerce" element={<WooCommerceConnection />} />
        <Route path="/migrations" element={<MigrationHistory />} />
        <Route path="/migrations/new" element={<NewMigration />} />
        <Route path="/migrations/:id" element={<MigrationDetails />} />
        <Route path="/settings" element={<Settings />} />
        <Route path="/support" element={<Support />} />
        <Route path="/pricing" element={<Pricing />} />
      </Routes>

      {/*
        Everything in here must be INLINE. Polaris wraps FooterHelp's children in
        a <p>, and the BlockStack/InlineStack this used to contain are divs — so
        every render logged "div cannot be a descendant of p", and the browser
        silently reshapes invalid nesting into a DOM that no longer matches what
        React thinks it rendered.
      */}
      <FooterHelp>
        <span style={{ display: "inline-flex", flexWrap: "wrap", gap: 8, justifyContent: "center" }}>
          {/* Support is a page inside the app — sending merchants to a mailto:
              (which the admin iframe blocks anyway) was never right. */}
          <Link onClick={() => navigate("/support")}>Support</Link>
          <Text as="span" tone="subdued">
            ·
          </Text>
          <Link onClick={() => openPublicPage("/developers/")}>Documentation</Link>
          <Text as="span" tone="subdued">
            ·
          </Text>
          <Link onClick={() => openPublicPage("/contact/")}>Contact</Link>
          <Text as="span" tone="subdued">
            ·
          </Text>
          <Link onClick={() => navigate("/settings")}>Settings</Link>
        </span>
        <span style={{ display: "block", marginTop: 4 }}>
          <Text as="span" variant="bodySm" tone="subdued">
            Store Shift — WooCommerce → Shopify migration
          </Text>
        </span>
      </FooterHelp>
    </Frame>
  );
}

export default App;
