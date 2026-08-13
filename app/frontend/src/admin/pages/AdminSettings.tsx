import { useEffect, useState } from "react";
import {
  Page,
  Card,
  BlockStack,
  InlineStack,
  Text,
  TextField,
  Select,
  Button,
  Banner,
  Badge,
  Divider,
  FormLayout,
  SkeletonBodyText,
  Box,
} from "@shopify/polaris";
import { adminApi, type BrandingSettings, type MailSettings } from "../api";

const SCHEMES = [
  { label: "TLS / STARTTLS (usually port 587)", value: "smtp" },
  { label: "SSL (usually port 465)", value: "smtps" },
];

interface Draft {
  host: string;
  port: string;
  username: string;
  password: string;
  scheme: "smtp" | "smtps";
  from_address: string;
  from_name: string;
}

const toDraft = (s: MailSettings): Draft => ({
  host: s.host ?? "",
  port: String(s.port ?? 587),
  username: s.username ?? "",
  password: "", // never populated — the server keeps it
  scheme: s.scheme ?? "smtp",
  from_address: s.from_address ?? "",
  from_name: s.from_name ?? "",
});

export default function AdminSettings() {
  const [settings, setSettings] = useState<MailSettings | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [branding, setBranding] = useState<BrandingSettings | null>(null);
  const [brandName, setBrandName] = useState("Store Shift");
  const [brandingBusy, setBrandingBusy] = useState(false);
  const [saving, setSaving] = useState(false);
  const [testing, setTesting] = useState(false);
  const [testTo, setTestTo] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = () =>
    adminApi
      .mailSettings()
      .then((s) => {
        setSettings(s);
        setDraft(toDraft(s));
      })
      .catch((e) => setError((e as Error).message));

  const loadBranding = () =>
    adminApi
      .branding()
      .then((b) => {
        setBranding(b);
        setBrandName(b.name);
      })
      .catch((e) => setError((e as Error).message));

  useEffect(() => {
    load();
    loadBranding();
  }, []);

  const applyBranding = async (fn: () => Promise<BrandingSettings>, done: string) => {
    setBrandingBusy(true);
    setError(null);
    setNotice(null);
    try {
      const updated = await fn();
      setBranding(updated);
      setBrandName(updated.name);
      setNotice(done);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBrandingBusy(false);
    }
  };

  const uploadLogo = (logo: File) =>
    applyBranding(() => adminApi.saveBranding({ logo }), "Email logo updated.");

  const removeLogo = () =>
    applyBranding(() => adminApi.saveBranding({ removeLogo: true }), "Email logo removed.");

  const saveBrandName = () =>
    applyBranding(() => adminApi.saveBranding({ name: brandName.trim() }), "Email name saved.");

  const set = (key: keyof Draft) => (value: string) =>
    setDraft((d) => (d ? { ...d, [key]: value } : d));

  const save = async () => {
    if (!draft) return;
    setSaving(true);
    setError(null);
    setNotice(null);
    try {
      const saved = await adminApi.saveMailSettings({
        host: draft.host.trim(),
        port: parseInt(draft.port, 10) || 587,
        username: draft.username.trim(),
        password: draft.password,
        scheme: draft.scheme,
        from_address: draft.from_address.trim(),
        from_name: draft.from_name.trim(),
      });
      setSettings(saved);
      setDraft(toDraft(saved));
      setNotice("SMTP settings saved.");
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setSaving(false);
    }
  };

  const sendTest = async () => {
    setTesting(true);
    setError(null);
    setNotice(null);
    try {
      const { sent_to } = await adminApi.testMailSettings(testTo.trim());
      setNotice(`Test message sent to ${sent_to}.`);
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setTesting(false);
    }
  };

  return (
    <Page title="Settings" subtitle="Application-wide configuration">
      <BlockStack gap="400">
        {error && (
          <Banner tone="critical" title="Something went wrong" onDismiss={() => setError(null)}>
            {error}
          </Banner>
        )}
        {notice && (
          <Banner tone="success" onDismiss={() => setNotice(null)}>
            {notice}
          </Banner>
        )}

        <Card>
          <BlockStack gap="400">
            <Text as="h2" variant="headingMd">
              Email branding
            </Text>
            <Text as="p" tone="subdued">
              Shown at the top of every email the app sends. PNG, JPG or GIF up to 512&nbsp;KB — email
              clients don't render SVG.
            </Text>

            <InlineStack gap="400" blockAlign="center" wrap={false}>
              <Box
                padding="300"
                borderWidth="025"
                borderColor="border"
                borderRadius="200"
                minWidth="180px"
              >
                {branding?.has_logo && branding.logo_url ? (
                  <img
                    src={branding.logo_url}
                    alt="Email logo"
                    style={{ maxHeight: 60, maxWidth: 200, display: "block" }}
                  />
                ) : (
                  <Text as="span" tone="subdued">
                    No logo — the name is used
                  </Text>
                )}
              </Box>

              <BlockStack gap="200">
                <input
                  type="file"
                  accept="image/png,image/jpeg,image/gif"
                  onChange={(e) => {
                    const file = e.target.files?.[0];
                    if (file) uploadLogo(file);
                    e.target.value = "";
                  }}
                />
                {branding?.has_logo && (
                  <InlineStack>
                    <Button tone="critical" variant="plain" loading={brandingBusy} onClick={removeLogo}>
                      Remove logo
                    </Button>
                  </InlineStack>
                )}
              </BlockStack>
            </InlineStack>

            <TextField
              label="Name shown in emails"
              value={brandName}
              onChange={setBrandName}
              placeholder="Store Shift"
              autoComplete="off"
              connectedRight={
                <Button loading={brandingBusy} onClick={saveBrandName}>
                  Save
                </Button>
              }
            />
          </BlockStack>
        </Card>

        <Card>
          <BlockStack gap="400">
            <InlineStack align="space-between" blockAlign="center">
              <Text as="h2" variant="headingMd">
                Outgoing email (SMTP)
              </Text>
              {settings &&
                (settings.configured ? (
                  <Badge tone="success">Configured here</Badge>
                ) : (
                  <Badge tone="attention">Using .env defaults</Badge>
                ))}
            </InlineStack>

            <Text as="p" tone="subdued">
              Saved here, these override the server's <code>.env</code> file — no SSH or redeploy
              needed. The password is stored encrypted and is never sent back to this page.
            </Text>

            {!draft ? (
              <SkeletonBodyText lines={6} />
            ) : (
              <FormLayout>
                <FormLayout.Group>
                  <TextField
                    label="SMTP host"
                    value={draft.host}
                    onChange={set("host")}
                    placeholder="smtp.hostinger.com"
                    autoComplete="off"
                  />
                  <TextField
                    label="Port"
                    type="number"
                    value={draft.port}
                    onChange={set("port")}
                    autoComplete="off"
                  />
                </FormLayout.Group>

                <Select
                  label="Encryption"
                  options={SCHEMES}
                  value={draft.scheme}
                  onChange={(v) => setDraft({ ...draft, scheme: v as "smtp" | "smtps" })}
                />

                <FormLayout.Group>
                  <TextField
                    label="Username"
                    value={draft.username}
                    onChange={set("username")}
                    placeholder="support@webnday.com"
                    autoComplete="off"
                  />
                  <TextField
                    label="Password"
                    type="password"
                    value={draft.password}
                    onChange={set("password")}
                    placeholder={settings?.has_password ? "•••••••• (unchanged)" : ""}
                    helpText={
                      settings?.has_password
                        ? "Leave blank to keep the saved password."
                        : "Stored encrypted."
                    }
                    autoComplete="off"
                  />
                </FormLayout.Group>

                <FormLayout.Group>
                  <TextField
                    label="From address"
                    type="email"
                    value={draft.from_address}
                    onChange={set("from_address")}
                    placeholder="support@webnday.com"
                    autoComplete="off"
                  />
                  <TextField
                    label="From name"
                    value={draft.from_name}
                    onChange={set("from_name")}
                    placeholder="Store Shift"
                    autoComplete="off"
                  />
                </FormLayout.Group>

                <InlineStack align="end">
                  <Button variant="primary" loading={saving} onClick={save}>
                    Save settings
                  </Button>
                </InlineStack>
              </FormLayout>
            )}

            <Divider />

            <BlockStack gap="200">
              <Text as="h3" variant="headingSm">
                Send a test message
              </Text>
              <Text as="p" tone="subdued">
                Uses the saved settings — save any changes first.
              </Text>
              <InlineStack gap="200" blockAlign="end">
                <div style={{ flex: 1 }}>
                  <TextField
                    label="Send to"
                    type="email"
                    value={testTo}
                    onChange={setTestTo}
                    placeholder="you@example.com"
                    autoComplete="email"
                  />
                </div>
                <Button
                  loading={testing}
                  disabled={!testTo.trim() || !settings?.configured}
                  onClick={sendTest}
                >
                  Send test
                </Button>
              </InlineStack>
            </BlockStack>
          </BlockStack>
        </Card>
      </BlockStack>
    </Page>
  );
}
