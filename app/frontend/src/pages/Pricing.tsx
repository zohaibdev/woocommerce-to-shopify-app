import { useCallback, useEffect, useState } from "react";
import {
  Page,
  Layout,
  Card,
  Text,
  Button,
  Badge,
  BlockStack,
  InlineStack,
  InlineGrid,
  Banner,
  Divider,
  Icon,
  Box,
  SkeletonBodyText,
} from "@shopify/polaris";
import { CheckIcon, XSmallIcon } from "@shopify/polaris-icons";
import { useNavigate } from "react-router-dom";
import { api, type BillingInfo, type BillingPlan } from "../api/client";
import { redirectTop } from "../navigation";

const cap = (v: number | null | undefined) =>
  v === null || v === undefined ? "Unlimited" : v.toLocaleString();

function Feature({ ok, children }: { ok: boolean; children: React.ReactNode }) {
  return (
    <InlineStack gap="150" blockAlign="center" wrap={false}>
      <span
        style={{
          display: "inline-flex",
          alignItems: "center",
          justifyContent: "center",
          width: 18,
          height: 18,
          flex: "none",
        }}
      >
        <Icon source={ok ? CheckIcon : XSmallIcon} tone={ok ? "success" : "subdued"} />
      </span>
      <Text as="span" variant="bodySm" tone={ok ? undefined : "subdued"}>
        {children}
      </Text>
    </InlineStack>
  );
}

export default function Pricing() {
  const navigate = useNavigate();
  const [info, setInfo] = useState<BillingInfo | null>(null);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(() => {
    api
      .billing()
      .then(setInfo)
      .catch((e) => setError((e as Error).message));
  }, []);

  useEffect(() => {
    load();
    // Shopify sends us back with ?billing=success|declined after approval.
    const result = new URLSearchParams(window.location.search).get("billing");
    if (result === "success") setNotice("Your plan is now active.");
    if (result === "declined") setError("The charge was declined or cancelled.");
  }, [load]);

  const choose = async (plan: BillingPlan) => {
    setBusy(plan.code);
    setError(null);
    try {
      const { confirmation_url } = await api.subscribe(plan.code);
      if (confirmation_url) {
        // Must break out of the Shopify iframe to show the approval screen.
        // Must leave the admin iframe. Assigning window.top.location throws a
        // SecurityError cross-origin, so the merchant never reached Shopify's
        // charge approval screen — the app simply went blank on Subscribe.
        redirectTop(confirmation_url);
        return;
      }
      setNotice(`Switched to the ${plan.name} plan.`);
      load();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(null);
    }
  };

  if (!info) {
    return (
      <Page title="Plans & pricing">
        <Card>
          <SkeletonBodyText lines={6} />
        </Card>
      </Page>
    );
  }

  return (
    <Page
      title="Plans & pricing"
      subtitle={info.current_plan ? `You're on the ${info.current_plan.name} plan` : undefined}
    >
      <Layout>
        {error && (
          <Layout.Section>
            <Banner tone="critical" title="Could not change plan" onDismiss={() => setError(null)}>
              {error}
            </Banner>
          </Layout.Section>
        )}
        {notice && (
          <Layout.Section>
            <Banner tone="success" onDismiss={() => setNotice(null)}>
              {notice}
            </Banner>
          </Layout.Section>
        )}

        <Layout.Section>
          <InlineGrid columns={{ xs: 1, sm: 2, lg: 4 }} gap="400">
            {info.plans.map((p) => (
              <Card key={p.code}>
                <BlockStack gap="400">
                  <BlockStack gap="100">
                    <InlineStack align="space-between" blockAlign="center">
                      <Text as="h2" variant="headingMd">
                        {p.name}
                      </Text>
                      {p.is_current && <Badge tone="success">Current</Badge>}
                    </InlineStack>
                    <InlineStack gap="100" blockAlign="end">
                      <Text as="span" variant="heading2xl">
                        {p.price === 0 ? "Free" : `$${p.price}`}
                      </Text>
                      {p.price > 0 && (
                        <Text as="span" tone="subdued" variant="bodySm">
                          /month
                        </Text>
                      )}
                    </InlineStack>
                  </BlockStack>

                  <Divider />

                  <BlockStack gap="200">
                    {/*
                      A plan with `total` sells ONE record budget spent across
                      products, customers and orders together. Listing it as
                      three separate lines would read as three times the
                      allowance the merchant actually gets.
                    */}
                    {p.limits.total != null ? (
                      <>
                        <Feature ok>
                          {`${cap(p.limits.total)} records total`}
                        </Feature>
                        <Feature ok>Products, customers &amp; orders — your mix</Feature>
                      </>
                    ) : (
                      <>
                        <Feature ok>{`${cap(p.limits.products)} products`}</Feature>
                        <Feature ok>{`${cap(p.limits.customers)} customers`}</Feature>
                        <Feature ok={(p.limits.orders ?? 1) !== 0}>
                          {p.limits.orders === 0
                            ? "Orders not included"
                            : `${cap(p.limits.orders)} orders`}
                        </Feature>
                      </>
                    )}
                    <Feature ok>Collections, variants &amp; images</Feature>
                    <Feature ok={p.limits.metafields !== false}>Metafields &amp; brands</Feature>
                  </BlockStack>

                  <Box paddingBlockStart="200">
                    {/*
                      A contact-only plan is negotiated and invoiced off-platform,
                      so an "Upgrade" button here would open a Shopify charge
                      screen for something the checkout cannot actually sell. The
                      API refuses it too (422) — this just stops the merchant
                      reaching a dead end to find that out.
                    */}
                    {p.limits.contact_only ? (
                      <Button variant="secondary" onClick={() => navigate("/support")} fullWidth>
                        Contact us
                      </Button>
                    ) : (
                      <Button
                        variant={p.is_current ? "secondary" : "primary"}
                        disabled={p.is_current}
                        loading={busy === p.code}
                        onClick={() => choose(p)}
                        fullWidth
                      >
                        {p.is_current
                          ? "Current plan"
                          : p.price === 0
                            ? "Switch to Free"
                            : `Upgrade to ${p.name}`}
                      </Button>
                    )}
                  </Box>
                </BlockStack>
              </Card>
            ))}
          </InlineGrid>
        </Layout.Section>

        <Layout.Section>
          <Card>
            <BlockStack gap="200">
              <Text as="h2" variant="headingMd">
                How billing works
              </Text>
              <Text as="p" tone="subdued" variant="bodySm">
                Paid plans are charged through Shopify and appear on your regular Shopify invoice.
                You approve the charge on Shopify's own screen — we never see your card. You can
                switch back to the Free plan at any time; limits then apply to future migrations
                only, and nothing already migrated is removed.
              </Text>
            </BlockStack>
          </Card>
        </Layout.Section>
      </Layout>
    </Page>
  );
}
