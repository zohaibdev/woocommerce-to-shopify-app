import { useEffect, useState } from "react";
import { Frame, Navigation, Loading } from "@shopify/polaris";
import {
  HomeIcon,
  StoreIcon,
  OrderIcon,
  AlertCircleIcon,
  ChatIcon,
  ContentIcon,
  CreditCardIcon,
} from "@shopify/polaris-icons";
import { Routes, Route, useNavigate, useLocation } from "react-router-dom";
import { adminApi, adminToken } from "./api";
import AdminLogin from "./pages/AdminLogin";
import AdminOverview from "./pages/AdminOverview";
import AdminStores from "./pages/AdminStores";
import AdminStoreDetail from "./pages/AdminStoreDetail";
import AdminMigrations from "./pages/AdminMigrations";
import AdminErrors from "./pages/AdminErrors";
import AdminTickets from "./pages/AdminTickets";
import AdminContent from "./pages/AdminContent";
import AdminPlans from "./pages/AdminPlans";

export default function AdminApp() {
  const navigate = useNavigate();
  const location = useLocation();
  const [authed, setAuthed] = useState<boolean | null>(null);

  useEffect(() => {
    if (!adminToken.get()) {
      setAuthed(false);
      return;
    }
    adminApi
      .me()
      .then(() => setAuthed(true))
      .catch(() => setAuthed(false));
  }, []);

  if (authed === null) {
    return (
      <Frame>
        <Loading />
      </Frame>
    );
  }

  if (!authed) {
    return <AdminLogin onSuccess={() => setAuthed(true)} />;
  }

  const item = (label: string, url: string, icon: typeof HomeIcon) => ({
    label,
    icon,
    onClick: () => navigate(url),
    selected: url === "/admin" ? location.pathname === "/admin" : location.pathname.startsWith(url),
  });

  const nav = (
    <Navigation location={location.pathname}>
      <Navigation.Section
        title="Super Admin"
        items={[
          item("Overview", "/admin", HomeIcon),
          item("Stores", "/admin/stores", StoreIcon),
          item("Plans", "/admin/plans", CreditCardIcon),
          item("Migrations", "/admin/migrations", OrderIcon),
          item("Error logs", "/admin/errors", AlertCircleIcon),
          item("Support tickets", "/admin/tickets", ChatIcon),
          item("Dashboard content", "/admin/content", ContentIcon),
        ]}
      />
      <Navigation.Section
        title="Account"
        items={[
          {
            label: "Sign out",
            onClick: async () => {
              try {
                await adminApi.logout();
              } catch {
                /* ignore */
              }
              adminToken.clear();
              setAuthed(false);
              navigate("/admin");
            },
          },
        ]}
      />
    </Navigation>
  );

  return (
    <Frame navigation={nav}>
      <Routes>
        <Route path="/admin" element={<AdminOverview />} />
        <Route path="/admin/stores" element={<AdminStores />} />
        <Route path="/admin/stores/:id" element={<AdminStoreDetail />} />
        <Route path="/admin/plans" element={<AdminPlans />} />
        <Route path="/admin/migrations" element={<AdminMigrations />} />
        <Route path="/admin/errors" element={<AdminErrors />} />
        <Route path="/admin/tickets" element={<AdminTickets />} />
        <Route path="/admin/content" element={<AdminContent />} />
      </Routes>
    </Frame>
  );
}
