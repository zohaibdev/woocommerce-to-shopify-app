import { Frame, Navigation, FooterHelp, Link, BlockStack, InlineStack, Text, Box } from "@shopify/polaris";
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
import { loadSettings } from "./settings";
import AdminApp from "./admin/AdminApp";

/**
 * True when the app was opened with Shopify context — embedded in the admin
 * (`host`), straight after OAuth (`shop`), or by a merchant who has used it
 * before (remembered shop). Without it every API call 401s, so we show the
 * install prompt instead of a dashboard that cannot load.
 */
function hasShopContext(): boolean {
  const q = new URLSearchParams(window.location.search);
  return q.has("host") || q.has("shop") || !!localStorage.getItem("shop_domain");
}

function App() {
  const navigate = useNavigate();
  const location = useLocation();
  const prefs = loadSettings();

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

      <FooterHelp>
        <BlockStack gap="100" inlineAlign="center">
          <InlineStack gap="200" blockAlign="center" align="center">
            <Link url={prefs.supportUrl} external>
              Support
            </Link>
            <Text as="span" tone="subdued">
              ·
            </Text>
            <Link url={prefs.docsUrl} external>
              Documentation
            </Link>
            <Text as="span" tone="subdued">
              ·
            </Text>
            <Link onClick={() => navigate("/settings")}>Settings</Link>
          </InlineStack>
          <Box>
            <Text as="span" variant="bodySm" tone="subdued">
              Store Shift — WooCommerce → Shopify migration
            </Text>
          </Box>
        </BlockStack>
      </FooterHelp>
    </Frame>
  );
}

export default App;
