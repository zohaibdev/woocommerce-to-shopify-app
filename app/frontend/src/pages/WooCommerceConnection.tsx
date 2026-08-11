import { useEffect, useState } from "react";
import {
  Page,
  Card,
  FormLayout,
  TextField,
  Button,
  Banner,
  BlockStack,
  Text,
  Badge,
} from "@shopify/polaris";
import { api, type WooConnection } from "../api/client";

export default function WooCommerceConnection() {
  const [storeUrl, setStoreUrl] = useState("");
  const [consumerKey, setConsumerKey] = useState("");
  const [consumerSecret, setConsumerSecret] = useState("");
  const [connection, setConnection] = useState<WooConnection | null>(null);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    // Best-effort load of existing (masked) connection.
    api.testWoo().then(setConnection).catch(() => undefined);
  }, []);

  const submit = async () => {
    setSaving(true);
    setError(null);
    try {
      const conn = await api.connectWoo({
        store_url: storeUrl,
        consumer_key: consumerKey,
        consumer_secret: consumerSecret,
      });
      setConnection(conn);
      setConsumerKey("");
      setConsumerSecret("");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setSaving(false);
    }
  };

  return (
    <Page title="Connect WooCommerce" backAction={{ content: "Home", url: "/" }}>
      <BlockStack gap="400">
        {connection?.status === "connected" && (
          <Banner tone="success" title="WooCommerce connected">
            <BlockStack gap="100">
              <Text as="p">Store: {connection.store_url}</Text>
              <Text as="p">
                WooCommerce {connection.wc_version} · WordPress {connection.wp_version}
              </Text>
              {connection.last_tested_at && (
                <Text as="p" tone="subdued">
                  Last tested: {new Date(connection.last_tested_at).toLocaleString()}
                </Text>
              )}
            </BlockStack>
          </Banner>
        )}

        {error && (
          <Banner tone="critical" title="Connection failed">
            {error}
          </Banner>
        )}

        <Card>
          <FormLayout>
            <TextField
              label="WooCommerce store URL"
              value={storeUrl}
              onChange={setStoreUrl}
              placeholder="https://example.com"
              autoComplete="off"
              type="url"
            />
            <TextField
              label="Consumer key"
              value={consumerKey}
              onChange={setConsumerKey}
              placeholder={connection?.consumer_key_masked ?? "ck_..."}
              autoComplete="off"
            />
            <TextField
              label="Consumer secret"
              value={consumerSecret}
              onChange={setConsumerSecret}
              placeholder="cs_..."
              type="password"
              autoComplete="off"
            />
            <Button variant="primary" loading={saving} onClick={submit}>
              Test connection
            </Button>
          </FormLayout>
        </Card>

        {connection && connection.status !== "connected" && (
          <Badge tone="critical">{`Status: ${connection.status}`}</Badge>
        )}
      </BlockStack>
    </Page>
  );
}
