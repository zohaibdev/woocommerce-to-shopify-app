import { useEffect, useState } from "react";
import {
  Page,
  Card,
  FormLayout,
  TextField,
  Button,
  Banner,
  BlockStack,
  InlineStack,
  Text,
} from "@shopify/polaris";
import { api, type WooConnection } from "../api/client";
import SecurityPluginWarning from "../components/SecurityPluginWarning";

export default function WooCommerceConnection() {
  const [storeUrl, setStoreUrl] = useState("");
  const [consumerKey, setConsumerKey] = useState("");
  const [consumerSecret, setConsumerSecret] = useState("");
  const [connection, setConnection] = useState<WooConnection | null>(null);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    // Reads what is STORED. This used to run a live test() instead, which cost
    // up to two minutes on a store that had gone away — and because a failed
    // test answers 422, the result was thrown away and a merchant with a working
    // connection was shown a blank "connect your store" form.
    api
      .getWooConnection()
      .then((c) => {
        // `store_url`, not just `c`. "No connection yet" used to arrive as an
        // empty object rather than null, and an empty object is truthy — so this
        // put `undefined` into a state field the render then called .trim() on,
        // and the page every unconnected merchant needs crashed before they could
        // type anything into it.
        if (!c?.store_url) return;
        setConnection(c);
        // Prefilled so re-saving credentials does not mean retyping the URL.
        setStoreUrl(c.store_url);
      })
      .catch(() => undefined);
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

  const retest = async () => {
    setTesting(true);
    setError(null);
    try {
      setConnection(await api.testWoo());
    } catch (e) {
      setError((e as Error).message);
      // The test failed, so what is stored is no longer working — say so rather
      // than leaving a stale green banner sitting above the error.
      setConnection((c) => (c ? { ...c, status: "error" } : c));
    } finally {
      setTesting(false);
    }
  };

  // Once credentials are stored, the URL alone is enough to save — the server
  // keeps the key and secret it already has. Demanding all three meant a merchant
  // fixing a typo in their store URL had to generate a whole new key pair in
  // WordPress, because this form can never show them the secret it holds.
  const canSave =
    storeUrl.trim() !== "" &&
    (!!connection || (consumerKey.trim() !== "" && consumerSecret.trim() !== ""));

  return (
    <Page title="Connect WooCommerce" backAction={{ content: "Home", url: "/" }}>
      <BlockStack gap="400">
        {connection?.status === "connected" && (
          <Banner tone="success" title="WooCommerce connected">
            <BlockStack gap="100">
              <Text as="p">Store: {connection.store_url}</Text>
              {connection.wc_version && (
                <Text as="p">
                  WooCommerce {connection.wc_version} · WordPress {connection.wp_version}
                </Text>
              )}
              {connection.last_tested_at && (
                <Text as="p" tone="subdued">
                  Last tested: {new Date(connection.last_tested_at).toLocaleString()}
                </Text>
              )}
            </BlockStack>
          </Banner>
        )}

        {connection && connection.status !== "connected" && (
          <Banner tone="warning" title="This connection is not working">
            <BlockStack gap="100">
              <Text as="p">
                Store: {connection.store_url}
                {connection.consumer_key_masked ? ` · key ${connection.consumer_key_masked}` : ""}
              </Text>
              <Text as="p">
                The most common cause is API keys that were revoked or that no longer have Read
                permission. Generate a new key/secret pair in WooCommerce → Settings → Advanced →
                REST API, then enter them below.
              </Text>
            </BlockStack>
          </Banner>
        )}

        {error && (
          <Banner tone="critical" title="Connection failed">
            {error}
          </Banner>
        )}

        {/* The connection working is not the same as a MIGRATION working: a
            single test call sails through protection that throttles ten
            thousand. */}
        <SecurityPluginWarning />

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
              helpText={
                connection
                  ? "Leave blank to keep the key already saved — useful when you only need to correct the store URL."
                  : undefined
              }
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
            <InlineStack gap="200">
              <Button variant="primary" loading={saving} disabled={!canSave} onClick={submit}>
                Save and test
              </Button>
              {connection && (
                <Button loading={testing} onClick={retest}>
                  Re-test saved connection
                </Button>
              )}
            </InlineStack>
          </FormLayout>
        </Card>
      </BlockStack>
    </Page>
  );
}
