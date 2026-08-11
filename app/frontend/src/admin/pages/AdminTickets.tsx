import { useCallback, useEffect, useState } from "react";
import {
  Page,
  Card,
  IndexTable,
  Badge,
  Text,
  Select,
  Pagination,
  BlockStack,
  InlineStack,
  Box,
  SkeletonBodyText,
  Modal,
  TextField,
  Button,
  Divider,
  EmptyState,
} from "@shopify/polaris";
import { adminApi, type Ticket, type PageMeta } from "../api";

const STATUS_FILTER = [
  { label: "All", value: "" },
  { label: "Open", value: "open" },
  { label: "Pending", value: "pending" },
  { label: "Resolved", value: "resolved" },
  { label: "Closed", value: "closed" },
];

const STATUS_SET = [
  { label: "Open", value: "open" },
  { label: "Pending", value: "pending" },
  { label: "Resolved", value: "resolved" },
  { label: "Closed", value: "closed" },
];

const tone = (s: string) =>
  s === "resolved" ? "success" : s === "closed" ? undefined : s === "open" ? "attention" : "info";

export default function AdminTickets() {
  const [rows, setRows] = useState<Ticket[]>([]);
  const [meta, setMeta] = useState<PageMeta | null>(null);
  const [page, setPage] = useState(1);
  const [status, setStatus] = useState("");
  const [loading, setLoading] = useState(true);

  const [open, setOpen] = useState<Ticket | null>(null);
  const [reply, setReply] = useState("");
  const [replyStatus, setReplyStatus] = useState("pending");
  const [busy, setBusy] = useState(false);

  const load = useCallback((p: number, st: string) => {
    setLoading(true);
    adminApi
      .tickets(p, st)
      .then(({ data, meta }) => {
        setRows(data);
        setMeta(meta);
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    load(page, status);
  }, [load, page, status]);

  const send = async () => {
    if (!open || !reply.trim()) return;
    setBusy(true);
    try {
      const updated = await adminApi.replyTicket(open.id, reply.trim(), replyStatus);
      setOpen(updated);
      setReply("");
      load(page, status);
    } finally {
      setBusy(false);
    }
  };

  return (
    <Page title="Support tickets" subtitle={meta ? `${meta.total} total` : undefined}>
      <BlockStack gap="400">
        <Card>
          <Select
            label="Filter by status"
            options={STATUS_FILTER}
            value={status}
            onChange={(v) => {
              setPage(1);
              setStatus(v);
            }}
          />
        </Card>

        <Card padding="0">
          {loading ? (
            <Box padding="400">
              <SkeletonBodyText lines={6} />
            </Box>
          ) : rows.length === 0 ? (
            <EmptyState heading="No tickets" image="">
              <p>No support tickets have been submitted.</p>
            </EmptyState>
          ) : (
            <IndexTable
              resourceName={{ singular: "ticket", plural: "tickets" }}
              itemCount={rows.length}
              selectable={false}
              headings={[
                { title: "Subject" },
                { title: "Store" },
                { title: "Status" },
                { title: "Priority" },
                { title: "Replies" },
                { title: "Created" },
              ]}
            >
              {rows.map((t, i) => (
                <IndexTable.Row
                  id={String(t.id)}
                  key={t.id}
                  position={i}
                  onClick={() =>
                    adminApi.ticket(t.id).then((full) => {
                      setOpen(full);
                      setReplyStatus(full.status === "open" ? "pending" : full.status);
                    })
                  }
                >
                  <IndexTable.Cell>
                    <Text as="span" fontWeight="semibold">
                      {t.subject}
                    </Text>
                  </IndexTable.Cell>
                  <IndexTable.Cell>{t.shop ?? "—"}</IndexTable.Cell>
                  <IndexTable.Cell>
                    <Badge tone={tone(t.status)}>{t.status}</Badge>
                  </IndexTable.Cell>
                  <IndexTable.Cell>{t.priority}</IndexTable.Cell>
                  <IndexTable.Cell>{t.replies_count}</IndexTable.Cell>
                  <IndexTable.Cell>
                    <Text as="span" variant="bodySm">
                      {new Date(t.created_at).toLocaleString()}
                    </Text>
                  </IndexTable.Cell>
                </IndexTable.Row>
              ))}
            </IndexTable>
          )}
        </Card>

        {meta && meta.last_page > 1 && (
          <InlineStack align="center">
            <Pagination
              hasPrevious={meta.current_page > 1}
              onPrevious={() => setPage((p) => p - 1)}
              hasNext={meta.has_more}
              onNext={() => setPage((p) => p + 1)}
              label={`Page ${meta.current_page} of ${meta.last_page}`}
            />
          </InlineStack>
        )}
      </BlockStack>

      {open && (
        <Modal open onClose={() => setOpen(null)} title={open.subject}>
          <Modal.Section>
            <BlockStack gap="300">
              <InlineStack gap="200">
                <Badge tone={tone(open.status)}>{open.status}</Badge>
                <Badge>{open.priority}</Badge>
                {open.shop && <Text as="span" tone="subdued">{open.shop}</Text>}
              </InlineStack>
              <Text as="p">{open.message}</Text>
              {open.contact_email && (
                <Text as="p" tone="subdued" variant="bodySm">
                  Contact: {open.contact_email}
                </Text>
              )}
            </BlockStack>
          </Modal.Section>

          <Modal.Section>
            <BlockStack gap="300">
              <Text as="h3" variant="headingSm">
                Conversation
              </Text>
              {(open.replies ?? []).length === 0 ? (
                <Text as="p" tone="subdued">
                  No replies yet.
                </Text>
              ) : (
                (open.replies ?? []).map((r) => (
                  <Box
                    key={r.id}
                    padding="300"
                    borderRadius="200"
                    background={r.author_type === "admin" ? "bg-surface-secondary" : "bg-surface"}
                  >
                    <BlockStack gap="100">
                      <Text as="span" variant="bodySm" fontWeight="semibold">
                        {r.author_type === "admin" ? `${r.author_name ?? "Admin"} (support)` : "Merchant"}
                      </Text>
                      <Text as="p">{r.message}</Text>
                      <Text as="span" variant="bodySm" tone="subdued">
                        {new Date(r.created_at).toLocaleString()}
                      </Text>
                    </BlockStack>
                  </Box>
                ))
              )}
            </BlockStack>
          </Modal.Section>

          <Modal.Section>
            <BlockStack gap="300">
              <Divider />
              <TextField
                label="Reply"
                value={reply}
                onChange={setReply}
                multiline={4}
                autoComplete="off"
                placeholder="Write a response to the merchant…"
              />
              <InlineStack align="space-between" blockAlign="center">
                <div style={{ minWidth: 200 }}>
                  <Select
                    label="Set status"
                    options={STATUS_SET}
                    value={replyStatus}
                    onChange={setReplyStatus}
                  />
                </div>
                <Button variant="primary" loading={busy} disabled={!reply.trim()} onClick={send}>
                  Send reply
                </Button>
              </InlineStack>
            </BlockStack>
          </Modal.Section>
        </Modal>
      )}
    </Page>
  );
}
