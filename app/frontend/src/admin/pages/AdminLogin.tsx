import { useState } from "react";
import {
  Card,
  FormLayout,
  TextField,
  Button,
  Banner,
  BlockStack,
  InlineStack,
  Text,
  Box,
  Icon,
  Badge,
} from "@shopify/polaris";
import { LockIcon, StoreIcon } from "@shopify/polaris-icons";
import { adminApi, adminToken } from "../api";

export default function AdminLogin({ onSuccess }: { onSuccess: () => void }) {
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const submit = async () => {
    if (!email || !password || busy) return;
    setBusy(true);
    setError(null);
    try {
      const { token } = await adminApi.login(email, password);
      adminToken.set(token);
      onSuccess();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div
      style={{
        minHeight: "100vh",
        display: "flex",
        alignItems: "center",
        justifyContent: "center",
        padding: "24px",
        background:
          "linear-gradient(135deg, #1a1f36 0%, #2d3561 45%, #0f7b6c 100%)",
      }}
    >
      <div style={{ width: "100%", maxWidth: 420 }}>
        <BlockStack gap="500">
          {/* Brand */}
          <BlockStack gap="200" inlineAlign="center">
            <div
              style={{
                width: 56,
                height: 56,
                borderRadius: 16,
                background: "rgba(255,255,255,0.14)",
                border: "1px solid rgba(255,255,255,0.25)",
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
                backdropFilter: "blur(6px)",
              }}
            >
              <span style={{ display: "inline-flex", filter: "invert(1) brightness(2)" }}>
                <Icon source={StoreIcon} />
              </span>
            </div>
            <div style={{ textAlign: "center" }}>
              <div
                style={{
                  color: "#fff",
                  fontSize: 26,
                  fontWeight: 650,
                  letterSpacing: "-0.02em",
                  lineHeight: 1.2,
                }}
              >
                Shop Shift
              </div>
              <div style={{ color: "rgba(255,255,255,0.72)", fontSize: 14, marginTop: 4 }}>
                WooCommerce → Shopify migration
              </div>
            </div>
            <Badge tone="info">Super Admin</Badge>
          </BlockStack>

          {error && (
            <Banner tone="critical" title="Sign in failed" onDismiss={() => setError(null)}>
              {error}
            </Banner>
          )}

          <Card>
            <Box padding="200">
              <BlockStack gap="400">
                <InlineStack gap="200" blockAlign="center">
                  <span
                    style={{
                      display: "inline-flex",
                      alignItems: "center",
                      justifyContent: "center",
                      width: 20,
                      height: 20,
                    }}
                  >
                    <Icon source={LockIcon} tone="base" />
                  </span>
                  <Text as="h1" variant="headingMd">
                    Sign in to your dashboard
                  </Text>
                </InlineStack>

                <FormLayout>
                  <TextField
                    label="Email"
                    type="email"
                    value={email}
                    onChange={setEmail}
                    autoComplete="username"
                    placeholder="you@example.com"
                    onFocus={() => setError(null)}
                  />
                  <TextField
                    label="Password"
                    type="password"
                    value={password}
                    onChange={setPassword}
                    autoComplete="current-password"
                    placeholder="••••••••••••"
                  />
                  <Button
                    variant="primary"
                    size="large"
                    loading={busy}
                    disabled={!email || !password}
                    onClick={submit}
                    fullWidth
                  >
                    Sign in
                  </Button>
                </FormLayout>
              </BlockStack>
            </Box>
          </Card>

          <Text as="p" alignment="center" variant="bodySm">
            <span style={{ color: "rgba(255,255,255,0.6)" }}>
              Authorized personnel only · All activity is logged
            </span>
          </Text>
        </BlockStack>
      </div>
    </div>
  );
}
