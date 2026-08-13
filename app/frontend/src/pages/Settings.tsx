import { useEffect, useState } from "react";
import {
  Page,
  Layout,
  Card,
  BlockStack,
  InlineStack,
  Text,
  TextField,
  Select,
  Checkbox,
  Button,
  Badge,
  Toast,
  Banner,
  InlineGrid,
} from "@shopify/polaris";
import { useNavigate } from "react-router-dom";
import { api, type Shop } from "../api/client";
import { loadSettings, saveSettings, type AppSettings } from "../settings";

export default function Settings() {
  const navigate = useNavigate();
  const [shop, setShop] = useState<Shop | null>(null);
  const [settings, setSettings] = useState<AppSettings>(loadSettings());
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    api.getShop().then(setShop).catch(() => undefined);
  }, []);

  const update = <K extends keyof AppSettings>(key: K, value: AppSettings[K]) =>
    setSettings((s) => ({ ...s, [key]: value }));

  const save = () => {
    saveSettings(settings);
    setSaved(true);
  };

  return (
    <>
      <Page title="Settings" primaryAction={{ content: "Save", onAction: save }}>
        <Layout>
          {/* Account / connections */}
          <Layout.AnnotatedSection
            title="Account & connections"
            description="Your connected Shopify and WooCommerce stores."
          >
            <Card>
              <BlockStack gap="300">
                <InlineStack align="space-between" blockAlign="center">
                  <Text as="span">Shopify store</Text>
                  <Text as="span" tone="subdued">
                    {shop?.shopify_domain ?? "—"}
                  </Text>
                </InlineStack>
                <InlineStack align="space-between" blockAlign="center">
                  <Text as="span">WooCommerce</Text>
                  {shop?.woocommerce_connected ? (
                    <Badge tone="success">Connected</Badge>
                  ) : (
                    <Badge tone="attention">Not connected</Badge>
                  )}
                </InlineStack>
                <InlineStack>
                  <Button onClick={() => navigate("/woocommerce")}>Manage WooCommerce connection</Button>
                </InlineStack>
              </BlockStack>
            </Card>
          </Layout.AnnotatedSection>

          {/* App permissions */}
          <Layout.AnnotatedSection
            title="App permissions"
            description="Grant new permissions when the app adds features (e.g. orders)."
          >
            <Card>
              <BlockStack gap="400">
                {shop?.needs_scope_update ? (
                  <Banner
                    tone="warning"
                    title="Update available"
                    action={{
                      content: "Update app",
                      onAction: () => {
                        if (shop) (window.top ?? window).location.href = shop.reauth_url;
                      },
                    }}
                  >
                    Missing permissions: {shop.missing_scopes.join(", ")}
                  </Banner>
                ) : (
                  <Banner tone="success">All required permissions are granted.</Banner>
                )}

                <BlockStack gap="200">
                  <Text as="h3" variant="headingSm">
                    Granted scopes
                  </Text>
                  <InlineGrid columns={{ xs: 1, sm: 2 }} gap="200">
                    {(shop?.granted_scopes ?? []).map((s) => (
                      <Badge key={s} tone="success">
                        {s}
                      </Badge>
                    ))}
                    {(shop?.missing_scopes ?? []).map((s) => (
                      <Badge key={s} tone="attention">
                        {`${s} (missing)`}
                      </Badge>
                    ))}
                  </InlineGrid>
                </BlockStack>

                <InlineStack>
                  <Button
                    onClick={() => {
                      if (shop) (window.top ?? window).location.href = shop.reauth_url;
                    }}
                  >
                    Re-authorize app
                  </Button>
                </InlineStack>
              </BlockStack>
            </Card>
          </Layout.AnnotatedSection>

          {/* Migration defaults */}
          <Layout.AnnotatedSection
            title="Migration defaults"
            description="Prefill new migrations with these values. You can still change them per migration."
          >
            <Card>
              <BlockStack gap="400">
                <TextField
                  label="Default vendor"
                  value={settings.defaultVendor}
                  onChange={(v) => update("defaultVendor", v)}
                  placeholder="e.g. My Store"
                  helpText="Used for products with no brand in WooCommerce."
                  autoComplete="off"
                />
                <TextField
                  label="Default custom tags"
                  value={settings.defaultTags}
                  onChange={(v) => update("defaultTags", v)}
                  placeholder="e.g. migrated, from-woo"
                  helpText="Comma-separated, added to migrated products and customers."
                  autoComplete="off"
                />
                <Select
                  label="WooCommerce weight unit"
                  options={[
                    { label: "Kilograms (kg)", value: "KILOGRAMS" },
                    { label: "Grams (g)", value: "GRAMS" },
                    { label: "Pounds (lb)", value: "POUNDS" },
                    { label: "Ounces (oz)", value: "OUNCES" },
                  ]}
                  value={settings.weightUnit}
                  onChange={(v) => update("weightUnit", v)}
                  helpText="Prefills new migrations. WooCommerce doesn't expose its unit through the API."
                />
                <Select
                  label="Status polling interval"
                  options={[
                    { label: "Every 2 seconds", value: "2" },
                    { label: "Every 3 seconds", value: "3" },
                    { label: "Every 5 seconds", value: "5" },
                    { label: "Every 10 seconds", value: "10" },
                  ]}
                  value={String(settings.pollSeconds)}
                  onChange={(v) => update("pollSeconds", Number(v))}
                />
              </BlockStack>
            </Card>
          </Layout.AnnotatedSection>

          {/* Notifications */}
          <Layout.AnnotatedSection
            title="Notifications"
            description="Get notified about migration progress."
          >
            <Card>
              <BlockStack gap="300">
                <Checkbox
                  label="Email me when a migration completes"
                  checked={settings.emailNotifications}
                  onChange={(v) => update("emailNotifications", v)}
                  helpText="Email delivery is coming soon; your preference is saved now."
                />
              </BlockStack>
            </Card>
          </Layout.AnnotatedSection>
        </Layout>

        {saved && <Toast content="Settings saved" onDismiss={() => setSaved(false)} />}
      </Page>
    </>
  );
}
