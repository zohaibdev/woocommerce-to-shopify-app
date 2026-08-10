import { Frame, Navigation } from "@shopify/polaris";
import {
  HomeIcon,
  OrderIcon,
  StoreIcon,
  SettingsIcon,
} from "@shopify/polaris-icons";
import { Routes, Route, useNavigate, useLocation } from "react-router-dom";
import Dashboard from "./pages/Dashboard";
import WooCommerceConnection from "./pages/WooCommerceConnection";
import NewMigration from "./pages/NewMigration";
import MigrationHistory from "./pages/MigrationHistory";
import MigrationDetails from "./pages/MigrationDetails";
import Settings from "./pages/Settings";

function App() {
  const navigate = useNavigate();
  const location = useLocation();

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
      </Routes>
    </Frame>
  );
}

export default App;
