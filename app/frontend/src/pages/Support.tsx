import { useCallback, useEffect, useState } from "react";
import {
  Page,
  Layout,
  Card,
  FormLayout,
  TextField,
  Button,
  Banner,
  BlockStack,
  InlineStack,
  Text,
  Badge,
  Box,
  Pagination,
  SkeletonBodyText,
  EmptyState,
  Divider,
} from "@shopify/polaris";
import { api, type MerchantTicket } from "../api/client";

const PER_PAGE = 5;

const tone = (s: string) =>
  s === "resolved" ? "success" : s === "closed" ? undefined : s === "open" ? "attention" : "info";

export default function Support() {
  const [subject, setSubject] = useState("");
  const [message, setMessage] = useState("");
  const [email, setEmail] = useState("");
  const [sending, setSending] = useState(false);
  const [sent, setSent] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [tickets, setTickets] = useState<MerchantTicket[]>([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [replyDrafts, setReplyDrafts] = useState<Record<number, string>>({});
  const [replyingTo, setReplyingTo] = useState<number | null>(null);

  const load = useCallback(() => {
    setLoading(true);
    api
      .myTickets()
      .then(({ data }) => setTickets(data))
      .catch(() => undefined)
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    load();
  }, [load]);

  const sendReply = async (ticketId: number) => {
    const message = (replyDrafts[ticketId] ?? "").trim();
    if (!message) return;

    setReplyingTo(ticketId);
    setError(null);
    try {
      await api.replyToTicket(ticketId, message);
      setReplyDrafts((d) => ({ ...d, [ticketId]: "" }));
      load();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setReplyingTo(null);
    }
  };

  const submit = async () => {
    setSending(true);
    setError(null);
    try {
      await api.createTicket({
        subject: subject.trim(),
        message: message.trim(),
        ...(email.trim() ? { contact_email: email.trim() } : {}),
      });
      setSubject("");
      setMessage("");
      setSent(true);
      setPage(1);
      load();
    } catch (e) {
      setError((e as Error).message);
    } finally {
      setSending(false);
    }
  };

  const lastPage = Math.max(1, Math.ceil(tickets.length / PER_PAGE));
  const pageItems = tickets.slice((page - 1) * PER_PAGE, page * PER_PAGE);

  return (
    <Page title="Support" subtitle="Ask us anything about your migration">
      <Layout>
        <Layout.Section>
          <BlockStack gap="400">
            {sent && (
              <Banner tone="success" title="Ticket submitted" onDismiss={() => setSent(false)}>
                Our team will reply here — check back for updates.
              </Banner>
            )}
            {error && (
              <Banner tone="critical" title="Could not submit ticket">
                {error}
              </Banner>
            )}

            <Card>
              <BlockStack gap="300">
                <Text as="h2" variant="headingMd">
                  New support request
                </Text>
                <FormLayout>
                  <TextField
                    label="Subject"
                    value={subject}
                    onChange={setSubject}
                    placeholder="e.g. Product images not migrating"
                    autoComplete="off"
                  />
                  <TextField
                    label="How can we help?"
                    value={message}
                    onChange={setMessage}
                    multiline={5}
                    placeholder="Describe the issue, and include a migration number if relevant."
                    autoComplete="off"
                  />
                  <TextField
                    label="Reply-to email (optional)"
                    type="email"
                    value={email}
                    onChange={setEmail}
                    placeholder="you@yourstore.com"
                    autoComplete="email"
                  />
                  <InlineStack align="end">
                    <Button
                      variant="primary"
                      loading={sending}
                      disabled={!subject.trim() || !message.trim()}
                      onClick={submit}
                    >
                      Submit ticket
                    </Button>
                  </InlineStack>
                </FormLayout>
              </BlockStack>
            </Card>
          </BlockStack>
        </Layout.Section>

        <Layout.Section>
          <BlockStack gap="400">
            <Card padding="0">
              <Box padding="400" borderBlockEndWidth="025" borderColor="border">
                <Text as="h2" variant="headingMd">
                  Your tickets
                </Text>
              </Box>

              {loading ? (
                <Box padding="400">
                  <SkeletonBodyText lines={4} />
                </Box>
              ) : tickets.length === 0 ? (
                <EmptyState heading="No tickets yet" image="">
                  <p>When you submit a request it will appear here with our replies.</p>
                </EmptyState>
              ) : (
                <BlockStack>
                  {pageItems.map((t) => (
                    <Box key={t.id} padding="400" borderBlockEndWidth="025" borderColor="border">
                      <BlockStack gap="200">
                        <InlineStack align="space-between" blockAlign="center">
                          <Text as="h3" fontWeight="semibold">
                            {t.subject}
                          </Text>
                          <Badge tone={tone(t.status)}>{t.status}</Badge>
                        </InlineStack>
                        <Text as="span" variant="bodySm" tone="subdued">
                          {new Date(t.created_at).toLocaleString()}
                        </Text>

                        {t.replies.length > 0 && (
                          <BlockStack gap="200">
                            <Divider />
                            {t.replies.map((r, i) => (
                              <Box
                                key={i}
                                padding="300"
                                borderRadius="200"
                                background={
                                  r.author_type === "admin"
                                    ? "bg-surface-secondary"
                                    : "bg-surface"
                                }
                              >
                                <BlockStack gap="100">
                                  <Text as="span" variant="bodySm" fontWeight="semibold">
                                    {r.author_type === "admin"
                                      ? `${r.author_name ?? "Support"} (Store Shift)`
                                      : "You"}
                                  </Text>
                                  <Text as="p">{r.message}</Text>
                                  <Text as="span" variant="bodySm" tone="subdued">
                                    {new Date(r.created_at).toLocaleString()}
                                  </Text>
                                </BlockStack>
                              </Box>
                            ))}
                          </BlockStack>
                        )}

                        {/* Support used to be one-way: a follow-up meant opening
                            a whole new ticket. */}
                        {t.status !== "closed" && (
                          <BlockStack gap="200">
                            <Divider />
                            <TextField
                              label="Reply"
                              labelHidden
                              multiline={2}
                              value={replyDrafts[t.id] ?? ""}
                              onChange={(v) => setReplyDrafts((d) => ({ ...d, [t.id]: v }))}
                              placeholder="Write a reply…"
                              autoComplete="off"
                            />
                            <InlineStack align="end">
                              <Button
                                size="slim"
                                loading={replyingTo === t.id}
                                disabled={!(replyDrafts[t.id] ?? "").trim()}
                                onClick={() => sendReply(t.id)}
                              >
                                Send reply
                              </Button>
                            </InlineStack>
                          </BlockStack>
                        )}
                      </BlockStack>
                    </Box>
                  ))}
                </BlockStack>
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
        </Layout.Section>
      </Layout>
    </Page>
  );
}
