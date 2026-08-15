import { useCallback, useEffect, useState } from "react";
import {
  Page,
  Card,
  IndexTable,
  Badge,
  Text,
  BlockStack,
  InlineStack,
  Box,
  SkeletonBodyText,
  Modal,
  TextField,
  Select,
  Button,
  Checkbox,
  Banner,
  EmptyState,
  FormLayout,
} from "@shopify/polaris";
import { adminApi, type Plan } from "../api";

/** "" (empty) means unlimited; a number is a hard cap; 0 means not included. */
const capToField = (v: number | null | undefined) =>
  v === null || v === undefined ? "" : String(v);
const fieldToCap = (v: string) => (v.trim() === "" ? null : Math.max(0, parseInt(v, 10) || 0));
const capLabel = (v: number | null | undefined) =>
  v === null || v === undefined ? "Unlimited" : v === 0 ? "Not included" : v.toLocaleString();

interface Draft {
  id?: number;
  code: string;
  name: string;
  price: string;
  interval: string;
  active: boolean;
  products: string;
  customers: string;
  orders: string;
  metafields: boolean;
  /** One shared record budget across all three. Blank = meter each separately. */
  total: string;
  /** Arranged with us directly; not buyable through Shopify billing. */
  contactOnly: boolean;
  /** Content features. Blank = unlimited, 0 = not included in this plan. */
  coupons: string;
  blogs: string;
  reviews: string;
}

const emptyDraft: Draft = {
  code: "",
  name: "",
  price: "0",
  interval: "monthly",
  active: true,
  products: "",
  customers: "",
  orders: "",
  metafields: true,
  total: "",
  contactOnly: false,
  coupons: "",
  blogs: "",
  reviews: "",
};

const toDraft = (p: Plan): Draft => ({
  id: p.id,
  code: p.code,
  name: p.name,
  price: String(p.price),
  interval: p.interval,
  active: p.active,
  products: capToField(p.limits?.products),
  customers: capToField(p.limits?.customers),
  orders: capToField(p.limits?.orders),
  metafields: p.limits?.metafields !== false,
  total: capToField(p.limits?.total),
  contactOnly: p.limits?.contact_only === true,
  coupons: capToField(p.limits?.coupons),
  blogs: capToField(p.limits?.blogs),
  reviews: capToField(p.limits?.reviews),
});

export default function AdminPlans() {
  const [plans, setPlans] = useState<Plan[] | null>(null);
  const [draft, setDraft] = useState<Draft | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const load = useCallback(() => {
    adminApi
      .plans()
      .then(({ data }) => setPlans(data))
      .catch((e) => setError((e as Error).message));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const set = <K extends keyof Draft>(k: K, v: Draft[K]) =>
    setDraft((d) => (d ? { ...d, [k]: v } : d));

  const save = async () => {
    if (!draft) return;
    setBusy(true);
    setError(null);
    try {
      const payload = {
        code: draft.code.trim(),
        name: draft.name.trim(),
        price: Number(draft.price) || 0,
        interval: draft.interval,
        active: draft.active,
        limits: {
          products: fieldToCap(draft.products),
          customers: fieldToCap(draft.customers),
          orders: fieldToCap(draft.orders),
          metafields: draft.metafields,
          total: fieldToCap(draft.total),
          contact_only: draft.contactOnly,
          coupons: fieldToCap(draft.coupons),
          blogs: fieldToCap(draft.blogs),
          reviews: fieldToCap(draft.reviews),
        },
      };

      if (draft.id) {
        await adminApi.updatePlan(draft.id, payload as never);
        setNotice(`${payload.name} updated — new limits apply to the next migration.`);
      } else {
        await adminApi.createPlan(payload as never);
        setNotice(`${payload.name} created.`);
      }
      setDraft(null);
      load();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const remove = async (p: Plan) => {
    setError(null);
    try {
      await adminApi.deletePlan(p.id);
      setNotice(`${p.name} deleted.`);
      load();
    } catch (e) {
      setError((e as Error).message);
    }
  };

  const toggle = async (p: Plan) => {
    try {
      await adminApi.updatePlan(p.id, {
        code: p.code,
        name: p.name,
        price: p.price,
        active: !p.active,
        limits: p.limits,
      } as never);
      load();
    } catch (e) {
      setError((e as Error).message);
    }
  };

  return (
    <Page
      title="Plans"
      subtitle="Pricing and limits shown to merchants — changes apply immediately"
      primaryAction={{ content: "Add plan", onAction: () => setDraft({ ...emptyDraft }) }}
    >
      <BlockStack gap="400">
        {error && (
          <Banner tone="critical" title="Could not save" onDismiss={() => setError(null)}>
            {error}
          </Banner>
        )}
        {notice && (
          <Banner tone="success" onDismiss={() => setNotice(null)}>
            {notice}
          </Banner>
        )}

        <Card padding="0">
          {!plans ? (
            <Box padding="400">
              <SkeletonBodyText lines={5} />
            </Box>
          ) : plans.length === 0 ? (
            <EmptyState
              heading="No plans"
              action={{ content: "Add plan", onAction: () => setDraft({ ...emptyDraft }) }}
              image=""
            >
              <p>Create the plans merchants can subscribe to.</p>
            </EmptyState>
          ) : (
            <IndexTable
              resourceName={{ singular: "plan", plural: "plans" }}
              itemCount={plans.length}
              selectable={false}
              headings={[
                { title: "Plan" },
                { title: "Price" },
                { title: "Products" },
                { title: "Customers" },
                { title: "Orders" },
                { title: "Status" },
                { title: "Stores" },
                { title: "Actions" },
              ]}
            >
              {plans.map((p, i) => (
                <IndexTable.Row id={String(p.id)} key={p.id} position={i}>
                  <IndexTable.Cell>
                    <BlockStack gap="050">
                      <Text as="span" fontWeight="semibold">
                        {p.name}
                      </Text>
                      <Text as="span" variant="bodySm" tone="subdued">
                        {p.code}
                      </Text>
                    </BlockStack>
                  </IndexTable.Cell>
                  <IndexTable.Cell>
                    {p.price === 0 ? "Free" : `$${p.price}/${p.interval === "yearly" ? "yr" : "mo"}`}
                  </IndexTable.Cell>
                  <IndexTable.Cell>{capLabel(p.limits?.products)}</IndexTable.Cell>
                  <IndexTable.Cell>{capLabel(p.limits?.customers)}</IndexTable.Cell>
                  <IndexTable.Cell>{capLabel(p.limits?.orders)}</IndexTable.Cell>
                  <IndexTable.Cell>
                    <Badge tone={p.active ? "success" : undefined}>
                      {p.active ? "Active" : "Hidden"}
                    </Badge>
                  </IndexTable.Cell>
                  <IndexTable.Cell>{p.active_subscriptions}</IndexTable.Cell>
                  <IndexTable.Cell>
                    <InlineStack gap="200">
                      <Button size="micro" onClick={() => setDraft(toDraft(p))}>
                        Edit
                      </Button>
                      <Button size="micro" onClick={() => toggle(p)}>
                        {p.active ? "Hide" : "Show"}
                      </Button>
                      <Button size="micro" tone="critical" onClick={() => remove(p)}>
                        Delete
                      </Button>
                    </InlineStack>
                  </IndexTable.Cell>
                </IndexTable.Row>
              ))}
            </IndexTable>
          )}
        </Card>

        <Card>
          <BlockStack gap="200">
            <Text as="h2" variant="headingMd">
              How limits work
            </Text>
            <Text as="p" tone="subdued" variant="bodySm">
              Leave a limit blank for <b>unlimited</b>, enter <b>0</b> to exclude that data type
              entirely, or a number to cap it. A migration stops at the cap and tells the merchant
              to upgrade. Existing paid subscribers keep the price they approved on Shopify — a
              price change only affects new subscriptions.
            </Text>
          </BlockStack>
        </Card>
      </BlockStack>

      {draft && (
        <Modal
          open
          onClose={() => setDraft(null)}
          title={draft.id ? `Edit ${draft.name || "plan"}` : "Add plan"}
          primaryAction={{
            content: "Save",
            onAction: save,
            loading: busy,
            disabled: !draft.code.trim() || !draft.name.trim(),
          }}
          secondaryActions={[{ content: "Cancel", onAction: () => setDraft(null) }]}
        >
          <Modal.Section>
            <FormLayout>
              <FormLayout.Group>
                <TextField
                  label="Name"
                  value={draft.name}
                  onChange={(v) => set("name", v)}
                  autoComplete="off"
                />
                <TextField
                  label="Code"
                  value={draft.code}
                  onChange={(v) => set("code", v.toLowerCase().replace(/[^a-z0-9_-]/g, ""))}
                  helpText="Lowercase id, e.g. pro"
                  disabled={!!draft.id}
                  autoComplete="off"
                />
              </FormLayout.Group>

              <FormLayout.Group>
                <TextField
                  label="Price (USD)"
                  type="number"
                  value={draft.price}
                  onChange={(v) => set("price", v)}
                  prefix="$"
                  autoComplete="off"
                  helpText="0 = free plan (no Shopify charge)"
                />
                <Select
                  label="Billing interval"
                  options={[
                    { label: "Monthly", value: "monthly" },
                    { label: "Yearly", value: "yearly" },
                  ]}
                  value={draft.interval}
                  onChange={(v) => set("interval", v)}
                />
              </FormLayout.Group>

              <Text as="h3" variant="headingSm">
                Shared allowance
              </Text>
              <TextField
                label="Total records"
                type="number"
                value={draft.total}
                onChange={(v) => set("total", v)}
                placeholder="Leave blank to cap each entity separately"
                helpText={
                  draft.total
                    ? `One budget of ${Number(draft.total).toLocaleString()} records spent across products, customers and orders together — not ${Number(draft.total).toLocaleString()} of each. Re-migrating a record already migrated does not spend it again.`
                    : "Set this to sell one shared record budget instead of a separate cap per entity."
                }
                autoComplete="off"
              />

              <Text as="h3" variant="headingSm">
                Per-entity limits
              </Text>
              {/*
                Both kinds of metering compose and the tighter one wins, but a
                pooled plan usually wants these blank — otherwise the merchant is
                held to whichever number is smaller and the headline budget is a
                promise the app will not keep.
              */}
              {draft.total !== "" && (
                <Banner tone="info">
                  <p>
                    This plan is metered by the shared allowance above. Leave the three fields below
                    blank unless you also want a hard ceiling on a specific entity — where both
                    apply, the smaller one wins.
                  </p>
                </Banner>
              )}
              <FormLayout.Group>
                <TextField
                  label="Products"
                  type="number"
                  value={draft.products}
                  onChange={(v) => set("products", v)}
                  placeholder="Unlimited"
                  autoComplete="off"
                />
                <TextField
                  label="Customers"
                  type="number"
                  value={draft.customers}
                  onChange={(v) => set("customers", v)}
                  placeholder="Unlimited"
                  autoComplete="off"
                />
                <TextField
                  label="Orders"
                  type="number"
                  value={draft.orders}
                  onChange={(v) => set("orders", v)}
                  placeholder="Unlimited"
                  autoComplete="off"
                />
              </FormLayout.Group>
              <Text as="p" variant="bodySm" tone="subdued">
                Blank = unlimited · 0 = not included in this plan
              </Text>

              <Text as="h3" variant="headingSm">
                Content features
              </Text>
              {/*
                Separate from the three above because they are not catalogue
                volume — they are features a plan either sells or does not. They
                never draw on the shared record budget: charging a merchant's
                product allowance for their six blog posts would be a surprise.
              */}
              <FormLayout.Group>
                <TextField
                  label="Coupons"
                  type="number"
                  value={draft.coupons}
                  onChange={(v) => set("coupons", v)}
                  autoComplete="off"
                />
                <TextField
                  label="Blogs & posts"
                  type="number"
                  value={draft.blogs}
                  onChange={(v) => set("blogs", v)}
                  helpText="Covers blogs and their posts together"
                  autoComplete="off"
                />
                <TextField
                  label="Product reviews"
                  type="number"
                  value={draft.reviews}
                  onChange={(v) => set("reviews", v)}
                  autoComplete="off"
                />
              </FormLayout.Group>
              <Text as="p" tone="subdued" variant="bodySm">
                Blank = unlimited · 0 = not included in this plan
              </Text>

              <Checkbox
                label="Arranged with us directly (not sold through Shopify billing)"
                checked={draft.contactOnly}
                onChange={(v) => set("contactOnly", v)}
                helpText="Merchants see a 'Contact us' button instead of Upgrade, and the subscribe API refuses this plan."
              />

              <Checkbox
                label="Include metafields & brands"
                checked={draft.metafields}
                onChange={(v) => set("metafields", v)}
              />
              <Checkbox
                label="Visible to merchants on the pricing page"
                checked={draft.active}
                onChange={(v) => set("active", v)}
              />
            </FormLayout>
          </Modal.Section>
        </Modal>
      )}
    </Page>
  );
}
