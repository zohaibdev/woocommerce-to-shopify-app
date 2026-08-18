import { useCallback, useEffect, useState } from "react";
import {
  Badge,
  Banner,
  BlockStack,
  Box,
  Button,
  Card,
  DataTable,
  InlineStack,
  SkeletonBodyText,
  Text,
} from "@shopify/polaris";
import { adminApi, type MigrationDebug } from "../api";

/**
 * Why a run is behaving the way it is — the operator's view, not the merchant's.
 *
 * The merchant's own log answers "what happened to my data". This answers the
 * question that actually comes up when a production migration crawls: what is it
 * doing with the time? A run at four records a minute looks identical whether it
 * is waiting on a slow WordPress host, asleep on Shopify throttles,
 * re-downloading the same chunk because the cache will not hold it, or simply
 * running two workers where eight were expected. Those have four different
 * fixes, so the panel leads with the verdict and keeps the raw numbers
 * underneath it.
 *
 * Polls while the run is active, because the most valuable time to read this is
 * while the thing is still going wrong.
 */
export default function MigrationDebugPanel({ migrationId }: { migrationId: number }) {
  const [data, setData] = useState<MigrationDebug | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [downloading, setDownloading] = useState(false);

  const load = useCallback(
    () =>
      adminApi
        .migrationDebug(migrationId)
        .then((d) => {
          setData(d);
          setError(null);
          return d;
        })
        .catch((e: Error) => {
          setError(e.message);
          return null;
        }),
    [migrationId],
  );

  useEffect(() => {
    // `cancelled` rather than clearing on unmount alone: StrictMode mounts,
    // unmounts and remounts, and an interval created after an await outlives
    // the effect that made it. That leak is already a known bug on the merchant
    // dashboard; it is not being reintroduced here.
    let cancelled = false;
    let timer: ReturnType<typeof setInterval> | undefined;

    load().then((d) => {
      if (cancelled || !d) return;

      const active = d.status === "processing" || d.status === "pending";
      if (active) timer = setInterval(load, 10_000);
    });

    return () => {
      cancelled = true;
      if (timer) clearInterval(timer);
    };
  }, [load]);

  if (error) {
    return <Banner tone="critical">{error}</Banner>;
  }

  if (!data) {
    return <SkeletonBodyText lines={8} />;
  }

  const t = data.totals;
  const wall = Math.max(1, t.elapsed_seconds ?? 0);
  const share = (seconds: number) => Math.round(((seconds ?? 0) / wall) * 100);

  const cacheReads = (t.page_cache_hits ?? 0) + (t.page_cache_misses ?? 0);

  // Deliberately the same thresholds the text report uses, so the panel and the
  // downloaded file never tell an operator two different stories.
  const findings: string[] = [];
  if (share(t.throttle_seconds) >= 20)
    findings.push(
      `${share(t.throttle_seconds)}% of job time was spent asleep waiting out Shopify throttles. ` +
        `Shopify is pacing the store — orderCreate has its own small rate limit, separate from the ` +
        `GraphQL point budget.`,
    );
  if (share(t.woo_seconds) >= 30)
    findings.push(
      `${share(t.woo_seconds)}% of job time was spent waiting on the merchant's WooCommerce store ` +
        `(${t.woo_calls} calls). Their host is the bottleneck.`,
    );
  if (share(t.shopify_seconds) >= 50)
    findings.push(
      `${share(t.shopify_seconds)}% of job time was spent inside Shopify calls (${t.shopify_calls}). ` +
        `Large product images are the usual reason — productSet downloads each one before returning.`,
    );
  if (cacheReads > 5 && t.page_cache_misses / cacheReads > 0.4)
    findings.push(
      `Chunk pages are mostly not being cached (${t.page_cache_misses} of ${cacheReads} reads missed), ` +
        `so every resume re-downloads its whole chunk. Raise max_allowed_packet or lower MIGRATION_PER_PAGE.`,
    );
  if (share(t.pace_seconds) >= 15)
    findings.push(
      `${share(t.pace_seconds)}% of job time was self-paced backoff to stay inside the shop's GraphQL ` +
        `point budget, which is set by the merchant's Shopify plan.`,
    );
  if ((t.woo_failures ?? 0) > 0)
    findings.push(`${t.woo_failures} WooCommerce request(s) failed outright.`);

  const recentWorkers = data.workers.filter((w) => w.recent).length;

  return (
    <BlockStack gap="400">
      {!data.debug_logging_enabled && (
        <Banner tone="warning" title="Debug logging is off">
          <Text as="p">
            Set <code>MIGRATION_DEBUG_LOG=true</code> and run{" "}
            <code>php artisan config:cache</code>. Anything that ran while it was off has no
            per-job diagnostics.
          </Text>
        </Banner>
      )}

      <InlineStack align="space-between" blockAlign="center">
        <Text as="h3" variant="headingSm">
          Diagnostics · {t.jobs ?? 0} job(s) recorded
        </Text>
        <Button
          loading={downloading}
          onClick={() => {
            setDownloading(true);
            adminApi
              .downloadMigrationDebugLog(migrationId)
              .catch((e: Error) => setError(e.message))
              .finally(() => setDownloading(false));
          }}
        >
          Download full developer log
        </Button>
      </InlineStack>

      {t.jobs === 0 ? (
        <Banner tone="info">
          No per-job diagnostics recorded for this run yet.
        </Banner>
      ) : (
        <Banner tone={findings.length ? "warning" : "success"} title="What the time went on">
          <BlockStack gap="100">
            {(findings.length
              ? findings
              : ["Nothing stands out — no single stage dominates this run's time."]
            ).map((f) => (
              <Text as="p" key={f}>
                {f}
              </Text>
            ))}
          </BlockStack>
        </Banner>
      )}

      {data.source_health && (
        <Banner tone={data.source_health.status === "healthy" ? "info" : "warning"}>
          <Text as="p">{data.source_health.message}</Text>
        </Banner>
      )}

      <Card>
        <BlockStack gap="200">
          <Text as="h3" variant="headingSm">
            Workers
          </Text>
          <Text as="p" tone="subdued" variant="bodySm">
            {data.workers.length} distinct process(es) over the run; {recentWorkers} active in the
            last 15 minutes. If fewer processes are busy than workers are running, the run is
            ceilinged rather than starved — see “effective now” below.
          </Text>
          <DataTable
            columnContentTypes={["text", "numeric", "numeric", "text"]}
            headings={["host:pid", "Jobs", "Busy (s)", "Last seen"]}
            rows={data.workers.map((w) => [
              w.recent ? `${w.worker} ●` : w.worker,
              w.jobs,
              Math.round(w.seconds),
              new Date(w.last_seen).toLocaleTimeString(),
            ])}
          />
        </BlockStack>
      </Card>

      <Card>
        <BlockStack gap="200">
          <Text as="h3" variant="headingSm">
            Concurrency
          </Text>
          <KeyValues values={data.concurrency} />
        </BlockStack>
      </Card>

      <Card>
        <BlockStack gap="200">
          <Text as="h3" variant="headingSm">
            Time breakdown
          </Text>
          <DataTable
            columnContentTypes={["text", "numeric", "numeric", "text"]}
            headings={["Stage", "Calls", "Seconds", "Share of job time"]}
            rows={[
              ["Shopify API", t.shopify_calls ?? 0, Math.round(t.shopify_seconds ?? 0), `${share(t.shopify_seconds)}%`],
              ["WooCommerce API", t.woo_calls ?? 0, Math.round(t.woo_seconds ?? 0), `${share(t.woo_seconds)}%`],
              ["Throttle pauses", t.throttle_waits ?? 0, Math.round(t.throttle_seconds ?? 0), `${share(t.throttle_seconds)}%`],
              ["Self-paced backoff", t.pace_waits ?? 0, Math.round(t.pace_seconds ?? 0), `${share(t.pace_seconds)}%`],
              ["Inside migrators", t.records ?? 0, Math.round(t.records_seconds ?? 0), `${share(t.records_seconds)}%`],
            ]}
          />
          <InlineStack gap="200" wrap>
            <Badge tone={t.page_cache_misses ? "attention" : "success"}>
              {`Page cache: ${t.page_cache_hits ?? 0} hit / ${t.page_cache_misses ?? 0} miss`}
            </Badge>
            <Badge>{`Shopify 429s: ${t.shopify_429 ?? 0}`}</Badge>
            <Badge>{`Cost throttles: ${t.shopify_cost_throttled ?? 0}`}</Badge>
            <Badge tone={t.shopify_soft_throttled ? "attention" : undefined}>
              {`Soft throttles: ${t.shopify_soft_throttled ?? 0}`}
            </Badge>
          </InlineStack>
        </BlockStack>
      </Card>

      <Card>
        <BlockStack gap="200">
          <Text as="h3" variant="headingSm">
            Throughput
          </Text>
          <DataTable
            columnContentTypes={["text", "numeric", "numeric", "numeric", "numeric"]}
            headings={["Entity", "Done / total", "OK", "Failed", "Records/min"]}
            rows={data.throughput.map((e) => [
              e.entity,
              `${e.processed}/${e.total}`,
              e.successful,
              e.failed,
              e.records_per_minute ?? "—",
            ])}
          />
        </BlockStack>
      </Card>

      {Object.keys(data.batches).length > 0 && (
        <Card>
          <BlockStack gap="200">
            <Text as="h3" variant="headingSm">
              Chunks
            </Text>
            <DataTable
              columnContentTypes={["text", "text"]}
              headings={["Entity", "By status"]}
              rows={Object.entries(data.batches).map(([entity, counts]) => [
                entity,
                Object.entries(counts)
                  .map(([status, n]) => `${status}=${n}`)
                  .join("  "),
              ])}
            />
          </BlockStack>
        </Card>
      )}

      {data.slowest_records.length > 0 && (
        <Card>
          <BlockStack gap="200">
            <Text as="h3" variant="headingSm">
              Slowest single records
            </Text>
            <Text as="p" tone="subdued" variant="bodySm">
              An average hides the record that took ninety seconds, and that record is usually the
              whole story.
            </Text>
            <DataTable
              columnContentTypes={["numeric", "numeric", "text"]}
              headings={["Woo ID", "Seconds", "When"]}
              rows={data.slowest_records.map((r) => [
                r.id,
                r.seconds,
                new Date(r.at).toLocaleTimeString(),
              ])}
            />
          </BlockStack>
        </Card>
      )}

      {Object.keys(data.outcomes).length > 0 && (
        <Card>
          <BlockStack gap="200">
            <Text as="h3" variant="headingSm">
              How jobs ended
            </Text>
            <DataTable
              columnContentTypes={["text", "numeric"]}
              headings={["Outcome", "Jobs"]}
              rows={Object.entries(data.outcomes)}
            />
          </BlockStack>
        </Card>
      )}

      <Card>
        <BlockStack gap="200">
          <Text as="h3" variant="headingSm">
            Runtime & queue
          </Text>
          <KeyValues
            values={{
              ...data.runtime,
              "pending jobs": data.queue.pending_jobs,
              "failed jobs": data.queue.failed_jobs,
            }}
          />
        </BlockStack>
      </Card>
    </BlockStack>
  );
}

function KeyValues({ values }: { values: Record<string, string | number> }) {
  return (
    <Box>
      {Object.entries(values).map(([label, value]) => (
        <InlineStack key={label} align="space-between" gap="400" wrap={false}>
          <Text as="span" variant="bodySm" tone="subdued">
            {label}
          </Text>
          <Text as="span" variant="bodySm" fontWeight="medium">
            {String(value)}
          </Text>
        </InlineStack>
      ))}
    </Box>
  );
}
