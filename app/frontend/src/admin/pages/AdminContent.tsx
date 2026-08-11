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
  EmptyState,
  Banner,
  Pagination,
} from "@shopify/polaris";
import { adminApi, type Content } from "../api";

const TYPE_LABELS: Record<string, string> = {
  tutorial_video: "Tutorial video",
  useful_link: "Useful link",
  documentation_link: "Documentation link",
  support_link: "Support link",
  email_info: "Email info",
  announcement: "Announcement",
  other_resource: "Other resource",
};

const PER_PAGE = 10;

// Where each content type actually surfaces in the merchant app.
const TYPE_SURFACE: Record<string, string> = {
  tutorial_video: "Dashboard → Video tutorial card",
  support_link: "Dashboard → Support card + footer link",
  announcement: "Dashboard → banner at the top",
  documentation_link: "Footer link",
  useful_link: "Not displayed yet",
  email_info: "Not displayed yet",
  other_resource: "Not displayed yet",
};

const empty: Partial<Content> = {
  type: "tutorial_video",
  title: "",
  body: "",
  url: "",
  enabled: true,
};

export default function AdminContent() {
  const [items, setItems] = useState<Content[] | null>(null);
  const [types, setTypes] = useState<string[]>([]);
  const [editing, setEditing] = useState<Partial<Content> | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [page, setPage] = useState(1);

  const load = useCallback(() => {
    adminApi
      .content()
      .then(({ data, types }) => {
        setItems(data);
        setTypes(types);
      })
      .catch((e) => setError(e.message));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const save = async () => {
    if (!editing?.title) return;
    setBusy(true);
    setError(null);
    try {
      if (editing.id) {
        await adminApi.updateContent(editing.id, editing);
      } else {
        await adminApi.createContent(editing);
      }
      setEditing(null);
      load();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setBusy(false);
    }
  };

  const remove = async (id: number) => {
    await adminApi.deleteContent(id);
    load();
  };

  const move = async (index: number, dir: -1 | 1) => {
    if (!items) return;
    const next = [...items];
    const target = index + dir;
    if (target < 0 || target >= next.length) return;
    [next[index], next[target]] = [next[target], next[index]];
    setItems(next);
    await adminApi.reorderContent(next.map((c) => c.id));
    load();
  };

  const lastPage = items ? Math.max(1, Math.ceil(items.length / PER_PAGE)) : 1;
  const pageItems = items ? items.slice((page - 1) * PER_PAGE, page * PER_PAGE) : [];

  return (
    <Page
      title="Dashboard content"
      subtitle="Everything shown to merchants in the Shopify app dashboard"
      primaryAction={{ content: "Add content", onAction: () => setEditing({ ...empty }) }}
    >
      <BlockStack gap="400">
        {error && (
          <Banner tone="critical" title="Something went wrong">
            {error}
          </Banner>
        )}

        <Card padding="0">
          {!items ? (
            <Box padding="400">
              <SkeletonBodyText lines={6} />
            </Box>
          ) : items.length === 0 ? (
            <EmptyState
              heading="No content yet"
              action={{ content: "Add content", onAction: () => setEditing({ ...empty }) }}
              image=""
            >
              <p>Add tutorial videos, links and resources for merchants.</p>
            </EmptyState>
          ) : (
            <IndexTable
              resourceName={{ singular: "item", plural: "items" }}
              itemCount={pageItems.length}
              selectable={false}
              headings={[
                { title: "Title" },
                { title: "Type" },
                { title: "URL" },
                { title: "Status" },
                { title: "Order" },
                { title: "Actions" },
              ]}
            >
              {pageItems.map((c, i) => (
                <IndexTable.Row id={String(c.id)} key={c.id} position={i}>
                  <IndexTable.Cell>
                    <Text as="span" fontWeight="semibold">
                      {c.title}
                    </Text>
                  </IndexTable.Cell>
                  <IndexTable.Cell>
                    <BlockStack gap="050">
                      <Text as="span">{TYPE_LABELS[c.type] ?? c.type}</Text>
                      <Text as="span" variant="bodySm" tone="subdued">
                        {TYPE_SURFACE[c.type] ?? ""}
                      </Text>
                    </BlockStack>
                  </IndexTable.Cell>
                  <IndexTable.Cell>
                    <Text as="span" variant="bodySm" tone="subdued">
                      {c.url ? (c.url.length > 40 ? c.url.slice(0, 40) + "…" : c.url) : "—"}
                    </Text>
                  </IndexTable.Cell>
                  <IndexTable.Cell>
                    <Badge tone={c.enabled ? "success" : undefined}>
                      {c.enabled ? "Enabled" : "Disabled"}
                    </Badge>
                  </IndexTable.Cell>
                  <IndexTable.Cell>
                    <InlineStack gap="100">
                      <Button size="micro" onClick={() => move((page - 1) * PER_PAGE + i, -1)} disabled={(page - 1) * PER_PAGE + i === 0}>
                        ↑
                      </Button>
                      <Button
                        size="micro"
                        onClick={() => move((page - 1) * PER_PAGE + i, 1)}
                        disabled={(page - 1) * PER_PAGE + i === items.length - 1}
                      >
                        ↓
                      </Button>
                    </InlineStack>
                  </IndexTable.Cell>
                  <IndexTable.Cell>
                    <InlineStack gap="200">
                      <Button size="micro" onClick={() => setEditing(c)}>
                        Edit
                      </Button>
                      <Button
                        size="micro"
                        onClick={() => adminApi.updateContent(c.id, { enabled: !c.enabled }).then(load)}
                      >
                        {c.enabled ? "Disable" : "Enable"}
                      </Button>
                      <Button size="micro" tone="critical" onClick={() => remove(c.id)}>
                        Delete
                      </Button>
                    </InlineStack>
                  </IndexTable.Cell>
                </IndexTable.Row>
              ))}
            </IndexTable>
          )}
        </Card>

        {lastPage > 1 && (
          <InlineStack align="center">
            <Pagination
              hasPrevious={page > 1}
              onPrevious={() => setPage((p) => p - 1)}
              hasNext={page < lastPage}
              onNext={() => setPage((p) => p + 1)}
              label={`Page ${page} of ${lastPage}`}
            />
          </InlineStack>
        )}
      </BlockStack>

      {editing && (
        <Modal
          open
          onClose={() => setEditing(null)}
          title={editing.id ? "Edit content" : "Add content"}
          primaryAction={{ content: "Save", onAction: save, loading: busy }}
          secondaryActions={[{ content: "Cancel", onAction: () => setEditing(null) }]}
        >
          <Modal.Section>
            <BlockStack gap="400">
              <Select
                label="Type"
                options={(types.length ? types : Object.keys(TYPE_LABELS)).map((t) => ({
                  label: TYPE_LABELS[t] ?? t,
                  value: t,
                }))}
                value={editing.type}
                onChange={(v) => setEditing({ ...editing, type: v })}
                helpText={editing.type ? TYPE_SURFACE[editing.type] : undefined}
              />
              <TextField
                label="Title"
                value={editing.title ?? ""}
                onChange={(v) => setEditing({ ...editing, title: v })}
                autoComplete="off"
              />
              <TextField
                label="URL"
                value={editing.url ?? ""}
                onChange={(v) => setEditing({ ...editing, url: v })}
                placeholder={
                  editing.type === "tutorial_video"
                    ? "https://www.youtube.com/embed/VIDEO_ID"
                    : "https://…  or  mailto:support@…"
                }
                helpText={
                  editing.type === "tutorial_video"
                    ? "Use the EMBED url so it can play inside the app."
                    : undefined
                }
                autoComplete="off"
              />
              <TextField
                label="Description"
                value={editing.body ?? ""}
                onChange={(v) => setEditing({ ...editing, body: v })}
                multiline={3}
                autoComplete="off"
              />
              <Checkbox
                label="Enabled (visible to merchants)"
                checked={editing.enabled ?? true}
                onChange={(v) => setEditing({ ...editing, enabled: v })}
              />
            </BlockStack>
          </Modal.Section>
        </Modal>
      )}
    </Page>
  );
}
