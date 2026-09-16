import { useState, type ReactNode } from "react";
import {
  Badge,
  Banner,
  BlockStack,
  Box,
  Button,
  Card,
  ChoiceList,
  DataTable,
  Divider,
  InlineStack,
  Modal,
  Text,
  TextField,
} from "@shopify/polaris";
import { adminApi, type AdminBillingPayload } from "./api";
import { dateTime, eventLabel, longDate, priceLabel, reasonText, shortDate, STATE_LABEL } from "../billing/format";

function Row({ label, children }: { label: string; children: ReactNode }) {
  return (
    <InlineStack align="space-between" blockAlign="start" gap="400" wrap={false}>
      <Box minWidth="35%">
        <Text as="span">{label}</Text>
      </Box>
      <Text as="span" tone="subdued" alignment="end" breakWord>
        {children}
      </Text>
    </InlineStack>
  );
}

/**
 * Everything support needs to understand a store's billing without opening the
 * database: the reconciled state, every subscription row, and the audit log —
 * plus a Shopify sync and a real (Shopify-side) cancellation.
 */
export default function StoreBilling({
  storeId,
  domain,
  installed,
  initial,
}: {
  storeId: number;
  domain: string;
  installed: boolean;
  initial: AdminBillingPayload;
}) {
  const [payload, setPayload] = useState<AdminBillingPayload>(initial);
  const [busy, setBusy] = useState<"sync" | "cancel" | null>(null);
  const [message, setMessage] = useState<{ tone: "success" | "critical"; text: string } | null>(null);

  const [cancelOpen, setCancelOpen] = useState(false);
  const [prorate, setProrate] = useState<string[]>(["no"]);
  const [reason, setReason] = useState("");
  const [confirmDomain, setConfirmDomain] = useState("");

  const b = payload.billing;
  const state = STATE_LABEL[b.state] ?? { label: b.state, tone: undefined };

  const apply = (next: AdminBillingPayload) =>
    setPayload({ billing: next.billing, subscriptions: next.subscriptions, billing_events: next.billing_events });

  const sync = async () => {
    setBusy("sync");
    setMessage(null);
    try {
      const result = await adminApi.syncStoreBilling(storeId);
      apply(result);
      setMessage({ tone: "success", text: result.message });
    } catch (e) {
      setMessage({ tone: "critical", text: (e as Error).message });
    } finally {
      setBusy(null);
    }
  };

  const cancel = async () => {
    setBusy("cancel");
    setMessage(null);
    try {
      const result = await adminApi.cancelStoreBilling(storeId, {
        confirm_domain: confirmDomain,
        reason,
        prorate: prorate[0] === "yes",
      });
      apply(result);
      setMessage({ tone: "success", text: result.message });
      setCancelOpen(false);
      setReason("");
      setConfirmDomain("");
    } catch (e) {
      setMessage({ tone: "critical", text: (e as Error).message });
      setCancelOpen(false);
    } finally {
      setBusy(null);
    }
  };

  const canSubmitCancel = confirmDomain.trim() === domain && reason.trim().length >= 5;

  return (
    <BlockStack gap="400">
      <Card>
        <BlockStack gap="300">
          <InlineStack align="space-between" blockAlign="center" gap="200">
            <InlineStack gap="200" blockAlign="center">
              <Text as="h2" variant="headingMd">
                Billing
              </Text>
              <Badge tone={state.tone}>{state.label}</Badge>
              {b.test && <Badge tone="info">Test charge</Badge>}
            </InlineStack>
            <InlineStack gap="200">
              {installed && (
                <Button onClick={sync} loading={busy === "sync"} disabled={busy !== null}>
                  Sync with Shopify
                </Button>
              )}
              {installed && b.actions.can_cancel && (
                <Button tone="critical" onClick={() => setCancelOpen(true)} disabled={busy !== null}>
                  Cancel subscription
                </Button>
              )}
            </InlineStack>
          </InlineStack>

          {message && (
            <Banner tone={message.tone} onDismiss={() => setMessage(null)}>
              {message.text}
            </Banner>
          )}
          {b.sync.failing && (
            <Banner tone="warning" title="Last sync with Shopify failed">
              Last failure {dateTime(b.admin.last_sync_failure_at)}; last success {dateTime(b.sync.last_synced_at) ?? "never"}.
              The details below are the last state Shopify confirmed. See the billing events for the error.
            </Banner>
          )}
          {b.state === "frozen" && (
            <Banner tone="critical">
              Shopify froze this subscription for non-payment. Paid features are paused until the merchant pays their
              Shopify bill; it resumes automatically.
            </Banner>
          )}
          {b.state === "unknown" && (
            <Banner tone="warning">
              The billing period ended {longDate(b.current_period_end)} and the renewal hasn't been confirmed with
              Shopify since. Paid features are paused until a sync succeeds.
            </Banner>
          )}
          {b.admin.open_checkouts > 1 && (
            <Banner tone="info">
              {b.admin.open_checkouts} unapproved checkouts are open. Only an approved one is ever charged; Shopify
              expires the rest after two days.
            </Banner>
          )}

          <Divider />

          <BlockStack gap="200">
            <Row label="Plan">{b.plan ? `${b.plan.name} (${b.plan.code})` : "None"}</Row>
            <Row label="Price">{b.price !== null ? priceLabel(b.price, b.interval, b.currency ?? "USD") : "—"}</Row>
            <Row label="Plan in use now">
              {b.entitled_plan?.name ?? "—"} · {b.admin.entitlement_basis.replace("_", " ")}
            </Row>
            <Row label="Shopify subscription ID">{b.admin.shopify_subscription_id ?? "—"}</Row>
            <Row label="Shopify status">
              {b.shopify_status ?? "—"}
              {b.admin.shopify_updated_at ? ` · changed ${dateTime(b.admin.shopify_updated_at)}` : ""}
            </Row>
            <Row label="Local status">{b.status ?? "—"}</Row>
            <Row label="Created">{dateTime(b.created_at) ?? "—"}</Row>
            <Row label="Approved">{dateTime(b.admin.approved_at) ?? "—"}</Row>
            <Row label="Started">{dateTime(b.started_at) ?? "—"}</Row>
            <Row label="Current period ends">{longDate(b.current_period_end) ?? "Not reported by Shopify"}</Row>
            <Row label="Renews">{b.renews_at ? longDate(b.renews_at) : "No"}</Row>
            {b.cancelled_at && (
              <Row label="Cancelled">
                {dateTime(b.cancelled_at)} · {reasonText(b.cancellation_reason)}
              </Row>
            )}
            {b.access_until && <Row label="Access until">{longDate(b.access_until)}</Row>}
            {b.ended_at && <Row label="Ended">{dateTime(b.ended_at)}</Row>}
            {b.trial_days ? <Row label="Trial">{`${b.trial_days} days`}</Row> : null}
            <Row label="Previous plan">
              {b.previous?.plan
                ? `${b.previous.plan.name} · ${reasonText(b.previous.reason)}${b.previous.ended_at ? ` · ended ${dateTime(b.previous.ended_at)}` : ""}`
                : "—"}
            </Row>
            <Row label="Pending change">
              {b.pending_change?.plan
                ? `${b.pending_change.plan.name} · ${b.pending_change.status.replace(/_/g, " ")}${
                    b.pending_change.effective_at ? ` · from ${longDate(b.pending_change.effective_at)}` : ""
                  }`
                : "—"}
            </Row>
            <Row label="Last synced">{dateTime(b.sync.last_synced_at) ?? "Never"}</Row>
          </BlockStack>
        </BlockStack>
      </Card>

      <Card padding="0">
        <div style={{ padding: 16 }}>
          <Text as="h2" variant="headingMd">
            Subscriptions
          </Text>
        </div>
        {payload.subscriptions.length === 0 ? (
          <div style={{ padding: "0 16px 16px" }}>
            <Text as="p" tone="subdued">
              No subscriptions recorded.
            </Text>
          </div>
        ) : (
          <DataTable
            columnContentTypes={["text", "text", "text", "text", "text", "text", "text", "text"]}
            headings={["Plan", "Shopify ID", "Status", "Price", "Started", "Period end", "Cancelled", "Access ends"]}
            rows={payload.subscriptions.map((r) => [
              `${r.plan ?? "—"}${r.test ? " (test)" : ""}`,
              r.shopify_subscription_id?.replace("gid://shopify/AppSubscription/", "") ?? "—",
              `${r.status}${r.shopify_status ? ` / ${r.shopify_status}` : ""}`,
              r.price !== null ? priceLabel(r.price, r.interval, r.currency ?? "USD") : "—",
              shortDate(r.started_at) ?? "—",
              shortDate(r.current_period_end) ?? "—",
              r.cancelled_at ? `${shortDate(r.cancelled_at)} · ${reasonText(r.cancellation_reason)}` : "—",
              shortDate(r.ends_at) ?? "—",
            ])}
          />
        )}
      </Card>

      <Card padding="0">
        <div style={{ padding: 16 }}>
          <BlockStack gap="100">
            <Text as="h2" variant="headingMd">
              Billing events
            </Text>
            <Text as="p" tone="subdued" variant="bodySm">
              Every lifecycle change, sync failure and billing action, newest first. References match the server
              logs.
            </Text>
          </BlockStack>
        </div>
        {payload.billing_events.length === 0 ? (
          <div style={{ padding: "0 16px 16px" }}>
            <Text as="p" tone="subdued">
              No billing events.
            </Text>
          </div>
        ) : (
          <DataTable
            columnContentTypes={["text", "text", "text", "text", "text"]}
            headings={["When", "Event", "Plan", "Source", "Details"]}
            rows={payload.billing_events.map((e) => [
              dateTime(e.occurred_at) ?? "—",
              eventLabel(e.event),
              e.plan ?? "—",
              e.source ?? "—",
              <span key={e.id} style={{ whiteSpace: "normal", display: "block", minWidth: 260 }}>
                {e.message ?? ""}
                {e.access_until ? ` Access until ${longDate(e.access_until)}.` : ""}
                {e.next_billing ? ` Next billing ${longDate(e.next_billing)}.` : ""}
                {typeof e.context?.reference === "string" ? ` [ref ${e.context.reference.slice(0, 8)}]` : ""}
                {typeof e.context?.error === "string" ? ` — ${e.context.error}` : ""}
              </span>,
            ])}
          />
        )}
      </Card>

      <Modal
        open={cancelOpen}
        onClose={() => busy !== "cancel" && setCancelOpen(false)}
        title={`Cancel ${b.plan?.name ?? "this"} subscription on Shopify?`}
        primaryAction={{
          content: "Cancel subscription",
          destructive: true,
          loading: busy === "cancel",
          disabled: !canSubmitCancel,
          onAction: cancel,
        }}
        secondaryActions={[{ content: "Keep subscription", onAction: () => setCancelOpen(false), disabled: busy === "cancel" }]}
      >
        <Modal.Section>
          <BlockStack gap="400">
            <Text as="p">
              This cancels Shopify subscription <Text as="span" fontWeight="semibold">{b.admin.shopify_subscription_id}</Text>{" "}
              for <Text as="span" fontWeight="semibold">{domain}</Text> through Shopify's Billing API. It can't be
              undone — the merchant would have to approve a new subscription. Nothing is recorded as cancelled unless
              Shopify confirms it.
            </Text>

            <ChoiceList
              title="Remaining days"
              choices={[
                {
                  label: `No credit — merchant keeps ${b.plan?.name ?? "the plan"} until ${longDate(b.current_period_end) ?? "now (no period end known)"}`,
                  value: "no",
                },
                {
                  label: "Prorated credit — plan ends now; Shopify credits the unused part and deducts it from the Partner payout",
                  value: "yes",
                },
              ]}
              selected={prorate}
              onChange={setProrate}
            />

            <TextField
              label="Reason (recorded in the billing events)"
              value={reason}
              onChange={setReason}
              multiline={2}
              autoComplete="off"
              requiredIndicator
            />

            <TextField
              label={`Type ${domain} to confirm`}
              value={confirmDomain}
              onChange={setConfirmDomain}
              autoComplete="off"
              requiredIndicator
            />
          </BlockStack>
        </Modal.Section>
      </Modal>
    </BlockStack>
  );
}
