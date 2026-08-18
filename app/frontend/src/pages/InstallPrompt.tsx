import { useState } from "react";
import {
  Page,
  Card,
  FormLayout,
  TextField,
  Button,
  BlockStack,
  InlineStack,
  Text,
  Box,
  Icon,
  Banner,
} from "@shopify/polaris";
import { StoreIcon } from "@shopify/polaris-icons";

/**
 * Shown at /app/ when the app is opened WITHOUT Shopify context — i.e. someone
 * pasted the URL directly instead of arriving from the Shopify admin.
 *
 * Loading the merchant dashboard here is useless: every API call needs a session
 * token, so it would just render a 401. Instead we let them start the install /
 * open the app for their store.
 */
export default function InstallPrompt() {
  const [shop, setShop] = useState("");
  const [error, setError] = useState<string | null>(null);

  // "/app/" in production, "/" in dev.
  const base = import.meta.env.BASE_URL.replace(/\/$/, "");

  const open = () => {
    const domain = shop
      .trim()
      .replace(/^https?:\/\//, "")
      .replace(/\/.*$/, "");

    if (!domain) {
      setError("Enter your store domain to continue.");
      return;
    }

    const full = domain.endsWith(".myshopify.com") ? domain : `${domain}.myshopify.com`;

    if (!/^[a-zA-Z0-9][a-zA-Z0-9-]*\.myshopify\.com$/.test(full)) {
      setError("That doesn't look like a .myshopify.com domain.");
      return;
    }

    window.location.href = `${base}/api/auth?shop=${encodeURIComponent(full)}`;
  };

  return (
    <Page narrowWidth>
      <Box paddingBlockStart="800">
        <BlockStack gap="500">
          <BlockStack gap="200" inlineAlign="center">
            <Box background="bg-fill-info-secondary" padding="300" borderRadius="300">
              <span
                style={{
                  display: "inline-flex",
                  alignItems: "center",
                  justifyContent: "center",
                  width: 28,
                  height: 28,
                }}
              >
                <Icon source={StoreIcon} tone="base" />
              </span>
            </Box>
            <Text as="h1" variant="headingLg" alignment="center">
              Open Shift Shop
            </Text>
            <Text as="p" tone="subdued" alignment="center">
              This app runs inside your Shopify admin. Enter your store domain to install it or
              jump back in.
            </Text>
          </BlockStack>

          {error && (
            <Banner tone="critical" onDismiss={() => setError(null)}>
              {error}
            </Banner>
          )}

          <Card>
            <FormLayout>
              <TextField
                label="Shopify store domain"
                value={shop}
                onChange={(v) => {
                  setShop(v);
                  setError(null);
                }}
                onFocus={() => setError(null)}
                placeholder="your-store.myshopify.com"
                autoComplete="off"
                helpText="You'll be taken to Shopify to approve the app."
              />
              <Button variant="primary" size="large" fullWidth onClick={open}>
                Continue to Shopify
              </Button>
            </FormLayout>
          </Card>

          <InlineStack align="center">
            <Button variant="plain" url="/" external>
              Learn more about Shift Shop
            </Button>
          </InlineStack>
        </BlockStack>
      </Box>
    </Page>
  );
}
