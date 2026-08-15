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
import { redirectTop } from "../navigation";

export default function Settings() {
  const navigate = useNavigate();
  const [shop, setShop] = useState<Shop | null>(null);
  const [settings, setSettings] = useState<AppSettings>(loadSettings());
  const [saved, setSaved] = useState(false);

  useEffect(() => {
    api.getShop().then(setShop).catch(() => undefined);
  }, []);

  // Whether completion emails can be delivered at all. Until this was surfaced
  // the toggle below promised a feature that was "coming soon" long after it had
  // shipped, and gave no hint on an installation where it genuinely cannot work.
  const emailDeliveryConfigured = shop?.email_delivery_configured !== false;

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
                        if (shop) redirectTop(shop.reauth_url);
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
                      if (shop) redirectTop(shop.reauth_url);
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
                  label="Image size"
                  options={[
                    { label: "Standard — up to 1024px (recommended)", value: "standard" },
                    { label: "Large — up to 1536px", value: "large" },
                    { label: "Original — full-size files", value: "full" },
                  ]}
                  value={settings.imageSize}
                  onChange={(v) => update("imageSize", v)}
                  helpText="Prefills new migrations. Shopify downloads every image before saving a product, so smaller images migrate considerably faster."
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
                  helpText={
                    emailDeliveryConfigured
                      ? "Pre-ticks the notification box in the migration wizard, where you choose the address."
                      : "This app has no email server configured yet, so completion emails cannot be sent."
                  }
                  disabled={!emailDeliveryConfigured}
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
