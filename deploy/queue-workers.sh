#!/usr/bin/env bash
#
# Keeps a fixed number of queue workers alive on a host with no process manager.
#
# WHY THIS EXISTS
# ---------------
# Production ran ONE cron line:
#
#     php artisan queue:work --stop-when-empty --max-time=55
#
# which has three problems on a large migration:
#
#   1. NO PARALLELISM. ShopifyPlan works out from the shop's real API budget that
#      it may run up to eight batches at once, and MigrationRunner::advance()
#      duly dispatches eight jobs — but with a single worker they execute one
#      after another. At roughly 3s per product (productSet is synchronous and
#      waits for Shopify to download every image) a 50k catalogue is ~42 hours
#      serial and ~5-6 hours across eight workers. This is the single biggest
#      lever on how long a big store takes.
#
#   2. NO --timeout. The worker's default is 60s, which becomes the timeout for
#      any job that does not set its own. Every migration job now does, but the
#      default should not be the thing standing between a merchant and a working
#      migration.
#
#   3. --stop-when-empty EXITS THE MOMENT THE QUEUE LOOKS EMPTY, which happens
#      routinely mid-run: every batch is briefly the only job, and while it is
#      being worked the table is empty. The worker quits and the run waits for
#      the next cron minute.
#
# USAGE
# -----
# Put this in your HOME directory, NOT inside public_html — anything under the
# web root is downloadable, and this file names your paths and process layout:
#
#     ~/queue-workers.sh
#
# Cron, every minute:
#
#     * * * * * /bin/bash ~/queue-workers.sh >> ~/worker-cron.log 2>&1
#
# It starts workers only up to the target count, so running it every minute is
# how dead workers get replaced. Tune with MIGRATION_WORKERS (see below); do not
# exceed what the host's process/memory limits allow — on cheap shared hosting
# four is usually the practical ceiling.

set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Find the Laravel root. BACKEND_DIR wins; otherwise try the repo layout (this
# script living in deploy/) and then the deployed layout (this script living in
# the home directory, which is where it belongs on shared hosting).
if [ -z "${BACKEND_DIR:-}" ]; then
    for candidate in \
        "$SCRIPT_DIR" \
        "$SCRIPT_DIR/../app/backend" \
        "$HOME/public_html/app/backend" \
        "$SCRIPT_DIR/public_html/app/backend"
    do
        if [ -f "$candidate/artisan" ]; then
            BACKEND_DIR="$candidate"
            break
        fi
    done
fi

if [ -z "${BACKEND_DIR:-}" ] || [ ! -f "$BACKEND_DIR/artisan" ]; then
    echo "$(date '+%F %T') no artisan found — set BACKEND_DIR=/path/to/app/backend"
    exit 1
fi

cd "$BACKEND_DIR" || {
    echo "$(date '+%F %T') cannot cd to $BACKEND_DIR"
    exit 1
}

PHP_BIN="${PHP_BIN:-php}"
WORKERS="${MIGRATION_WORKERS:-4}"

# Must stay BELOW config/queue.php's retry_after (900) or the queue will hand a
# still-running job to a second worker and every item on the page runs twice.
TIMEOUT="${WORKER_TIMEOUT:-600}"

# Workers recycle on this interval so a deploy's new code is picked up (PHP holds
# the old classes in memory for the life of the process) and so any slow leak is
# swept up rather than accumulating.
MAX_TIME="${WORKER_MAX_TIME:-3600}"

MATCH="artisan queue:work"

count_workers() {
    if command -v pgrep >/dev/null 2>&1; then
        pgrep -fc -- "$MATCH" 2>/dev/null || echo 0
    else
        # Busybox / restricted shells: fall back to ps. grep -v excludes the grep
        # itself, which would otherwise always count as one running worker.
        ps -eo args 2>/dev/null | grep -F -- "$MATCH" | grep -vc grep || echo 0
    fi
}

running="$(count_workers)"
running="${running:-0}"

if [ "$running" -ge "$WORKERS" ]; then
    exit 0
fi

started=0

while [ "$running" -lt "$WORKERS" ]; do
    nohup "$PHP_BIN" artisan queue:work \
        --queue=default \
        --sleep=3 \
        --tries=3 \
        --timeout="$TIMEOUT" \
        --max-time="$MAX_TIME" \
        >> storage/logs/worker.log 2>&1 &

    running=$((running + 1))
    started=$((started + 1))
done

echo "$(date '+%F %T') started $started worker(s), target $WORKERS"
