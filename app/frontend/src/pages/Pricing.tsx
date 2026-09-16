import { useCallback, useEffect, useRef, useState } from "react";
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
import SubscriptionStatusBanner from "../components/SubscriptionStatusBanner";
import CurrentSubscriptionCard from "../billing/CurrentSubscriptionCard";
import CancelSubscriptionModal from "../billing/CancelSubscriptionModal";
import PlanChangeModal from "../billing/PlanChangeModal";
import BillingHistoryCard from "../billing/BillingHistoryCard";
import { intervalLabel, longDate } from "../billing/format";

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

/** Shopify returns the merchant here with ?billing=… after the approval screen. */
const OUTCOMES: Record<string, { tone: "success" | "info" | "critical"; text: string }> = {
  success: { tone: "success", text: "Your subscription is active." },
  scheduled: { tone: "info", text: "Approved. Your new plan starts at the beginning of your next billing cycle." },
  processing: { tone: "info", text: "Thanks — Shopify is confirming your subscription. This page updates on its own." },
  declined: { tone: "critical", text: "The subscription wasn't approved, so nothing changed and nothing was charged." },
};

export default function Pricing() {
  const navigate = useNavigate();
  const plansRef = useRef<HTMLDivElement>(null);
  const [info, setInfo] = useState<BillingInfo | null>(null);
  // ONE in-flight billing action at a time: every billing button is disabled
  // while it runs, so repeated clicks can't start a second request.
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<{ tone: "success" | "info" | "critical"; text: string } | null>(null);
  const [confirmCancel, setConfirmCancel] = useState(false);
  const [changeTo, setChangeTo] = useState<BillingPlan | null>(null);

  /** Always replace state wholesale from the server — never patch a local copy. */
  const load = useCallback(async (refresh = false) => {
    try {
      const next = await api.billing(refresh);
      setInfo(next);

      return next;
    } catch (e) {
      setError((e as Error).message);

      return null;
    }
  }, []);

  useEffect(() => {
    const outcome = new URLSearchParams(window.location.search).get("billing");
    if (outcome && OUTCOMES[outcome]) setNotice(OUTCOMES[outcome]);

    let cancelled = false;
    let timer: number | undefined;

    (async () => {
      let next = await load(outcome !== null);

      // Approved but not confirmed yet: ask Shopify a few more times.
      for (let attempt = 0; outcome === "processing" && attempt < 5 && !cancelled; attempt++) {
        const stillProcessing = next?.subscription.pending_change?.status === "processing" || next?.subscription.state === "pending";
        if (!stillProcessing) {
          if (next?.subscription.state === "active") setNotice(OUTCOMES.success);
          break;
        }
        await new Promise((resolve) => {
          timer = window.setTimeout(resolve, 4000);
        });
        if (!cancelled) next = await load(true);
      }
    })();

    return () => {
      cancelled = true;
      window.clearTimeout(timer);
    };
  }, [load]);

  const run = async (key: string, action: () => Promise<void>) => {
    if (busy) return;
    setBusy(key);
    setError(null);
    try {
      await action();
    } catch (e) {
      // Close any dialog, or it would sit over the error banner explaining why.
      setConfirmCancel(false);
      setChangeTo(null);
      setError((e as Error).message);
      // Whatever happened on Shopify's side, show what is actually true now.
      await load();
    } finally {
      setBusy(null);
    }
  };

  const startCheckout = (plan: BillingPlan) =>
    run(plan.code, async () => {
      const result = await api.subscribe(plan.code);

      if (result.confirmation_url) {
        // Must leave the admin iframe to show Shopify's approval screen.
        redirectTop(result.confirmation_url);
        return;
      }

      setInfo(result);
      setChangeTo(null);
      setNotice({
        tone: "success",
        text:
          result.result === "cancelled"
            ? cancelledNotice(result)
            : "Nothing to change — you're already on that plan.",
      });
    });

  const cancelSubscription = () =>
    run("cancel", async () => {
      const result = await api.cancelPlan();
      setInfo(result);
      setConfirmCancel(false);
      setNotice({ tone: "success", text: cancelledNotice(result) });
    });

  const refresh = () =>
    run("refresh", async () => {
      await load(true);
    });

  if (!info) {
    return (
      <Page title="Plans & pricing">
        {error ? (
          <Banner tone="critical" title="Couldn't load billing">
            {error}
          </Banner>
        ) : (
          <Card>
            <SkeletonBodyText lines={6} />
          </Card>
        )}
      </Page>
    );
  }

  const sub = info.subscription;
  const planByCode = (code?: string | null) => info.plans.find((p) => p.code === code) ?? null;
  const choosePlan = (plan: BillingPlan) => {
    if (plan.price === 0) {
      if (sub.actions.can_cancel) setConfirmCancel(true);
      return;
    }
    setChangeTo(plan);
  };
  const scrollToPlans = () => plansRef.current?.scrollIntoView({ behavior: "smooth", block: "start" });

  return (
    <Page title="Plans & pricing" subtitle={subtitle(info)}>
      <Layout>
        {error && (
          <Layout.Section>
            <Banner tone="critical" title="Your subscription wasn't changed" onDismiss={() => setError(null)}>
              {error}
            </Banner>
          </Layout.Section>
        )}
        {notice && (
          <Layout.Section>
            <Banner tone={notice.tone} onDismiss={() => setNotice(null)}>
              {notice.text}
            </Banner>
          </Layout.Section>
        )}
        {(sub.state === "frozen" || sub.state === "unknown") && (
          <Layout.Section>
            <SubscriptionStatusBanner subscription={sub} onRefresh={refresh} />
          </Layout.Section>
        )}

        <Layout.Section>
          <CurrentSubscriptionCard
            subscription={sub}
            busy={busy !== null}
            onChangePlan={scrollToPlans}
            onCancel={() => setConfirmCancel(true)}
            onResubscribe={() => {
              const plan = planByCode(sub.plan?.code);
              if (plan) setChangeTo(plan);
              else scrollToPlans();
            }}
            onContinueCheckout={() => {
              const plan = planByCode(sub.pending_change?.plan?.code ?? sub.plan?.code);
              if (plan) startCheckout(plan);
            }}
            onRefresh={refresh}
          />
        </Layout.Section>

        <Layout.Section>
          <div ref={plansRef}>
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
                            {intervalLabel(p.interval)}
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
                          <Feature ok>{`${cap(p.limits.total)} records total`}</Feature>
                          <Feature ok>Products, customers &amp; orders — your mix</Feature>
                        </>
                      ) : (
                        <>
                          <Feature ok>{`${cap(p.limits.products)} products`}</Feature>
                          <Feature ok>{`${cap(p.limits.customers)} customers`}</Feature>
                          <Feature ok={(p.limits.orders ?? 1) !== 0}>
                            {p.limits.orders === 0 ? "Orders not included" : `${cap(p.limits.orders)} orders`}
                          </Feature>
                        </>
                      )}
                      <Feature ok>Collections, variants &amp; images</Feature>
                      <Feature ok={p.limits.metafields !== false}>Metafields &amp; brands</Feature>
                      {/*
                        Content features are metered on their own — never out of the
                        record budget above. A 0 renders as an unticked line rather
                        than being hidden, because "this plan does not include
                        discount codes" is exactly what a merchant comparing plans
                        needs to see BEFORE they pick one.
                      */}
                      <Feature ok={p.limits.coupons !== 0}>Coupons &rarr; discount codes</Feature>
                      <Feature ok={p.limits.blogs !== 0}>Blogs &amp; blog posts</Feature>
                      <Feature ok={p.limits.reviews !== 0}>Product reviews</Feature>
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
                        <PlanButton plan={p} info={info} busy={busy} onChoose={choosePlan} />
                      )}
                    </Box>
                  </BlockStack>
                </Card>
              ))}
            </InlineGrid>
          </div>
        </Layout.Section>

        <Layout.Section>
          <BillingHistoryCard history={info.history} />
        </Layout.Section>

        <Layout.Section>
          <Card>
            <BlockStack gap="200">
              <Text as="h2" variant="headingMd">
                How billing works
              </Text>
              <Text as="p" tone="subdued" variant="bodySm">
                Paid plans are charged through Shopify every 30 days (or yearly) and appear on your regular Shopify
                bill. You approve every charge on Shopify's own screen — we never see your card. When you change
                plans, the new plan replaces the old one as soon as you approve it, and Shopify charges or credits
                the difference for the rest of the billing period.
              </Text>
              <Text as="p" tone="subdued" variant="bodySm">
                You can cancel at any time. Shopify stops the subscription right away and doesn't charge you again;
                if you've already paid for the current period, your plan keeps working until it ends. Uninstalling
                the app cancels the subscription the same way. Nothing you've migrated is ever removed.
              </Text>
            </BlockStack>
          </Card>
        </Layout.Section>
      </Layout>

      {sub.actions.can_cancel && (
        <CancelSubscriptionModal
          open={confirmCancel}
          subscription={sub}
          cancelling={busy === "cancel"}
          onClose={() => setConfirmCancel(false)}
          onConfirm={cancelSubscription}
        />
      )}

      <PlanChangeModal
        plan={changeTo}
        subscription={sub}
        working={busy === changeTo?.code}
        onClose={() => setChangeTo(null)}
        onConfirm={() => changeTo && startCheckout(changeTo)}
      />
    </Page>
  );
}

/** One plan card's button: only an action that is valid for the current state. */
function PlanButton({
  plan: p,
  info,
  busy,
  onChoose,
}: {
  plan: BillingPlan;
  info: BillingInfo;
  busy: string | null;
  onChoose: (plan: BillingPlan) => void;
}) {
  const sub = info.subscription;
  const pending = sub.pending_change;
  const paidLive = sub.state === "active" || sub.state === "frozen" || sub.state === "unknown";
  const disabled = busy !== null;

  if (p.price === 0) {
    if (paidLive && sub.actions.can_cancel) {
      return (
        <Button onClick={() => onChoose(p)} disabled={disabled} fullWidth>
          Downgrade to Free
        </Button>
      );
    }

    return (
      <Button disabled fullWidth>
        {sub.state === "cancelled" ? `Starts ${longDate(sub.access_until) ?? "when your plan ends"}` : "Current plan"}
      </Button>
    );
  }

  if (sub.state === "active" && sub.plan?.code === p.code) {
    return (
      <Button disabled fullWidth>
        Current plan
      </Button>
    );
  }

  if (pending?.plan?.code === p.code && pending.status !== "awaiting_approval") {
    return (
      <Button disabled fullWidth>
        {pending.status === "scheduled" ? `Starts ${longDate(pending.effective_at) ?? "next cycle"}` : "Confirming…"}
      </Button>
    );
  }

  if (!sub.actions.can_change_plan) {
    return (
      <Button disabled fullWidth>
        Unavailable while on hold
      </Button>
    );
  }

  const label =
    (sub.state === "cancelled" || sub.state === "ended") && sub.plan?.code === p.code
      ? `Resubscribe to ${p.name}`
      : p.change?.type === "upgrade"
        ? `Upgrade to ${p.name}`
        : p.change?.type === "downgrade"
          ? `Downgrade to ${p.name}`
          : p.change?.type === "switch"
            ? `Switch to ${p.name}`
            : `Choose ${p.name}`;

  return (
    <Button variant="primary" onClick={() => onChoose(p)} loading={busy === p.code} disabled={disabled} fullWidth>
      {pending?.plan?.code === p.code ? "Continue to approval" : label}
    </Button>
  );
}

function subtitle(info: BillingInfo): string | undefined {
  const sub = info.subscription;
  const plan = info.current_plan?.name;
  if (!plan) return undefined;

  switch (sub.state) {
    case "active":
      return sub.renews_at ? `You're on the ${plan} plan · renews ${longDate(sub.renews_at)}` : `You're on the ${plan} plan`;
    case "cancelled":
      return `You're on the ${plan} plan until ${longDate(sub.access_until)} · cancelled, won't renew`;
    default:
      return `You're on the ${plan} plan`;
  }
}

function cancelledNotice(result: BillingInfo): string {
  const sub = result.subscription;
  const name = sub.plan?.name ?? "Your";

  return sub.state === "cancelled"
    ? `${name} subscription cancelled. You won't be charged again, and ${name} stays available until ${longDate(sub.access_until)}.`
    : `${name} subscription cancelled. You won't be charged again, and your store is now on the Free plan.`;
}
