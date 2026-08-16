#!/usr/bin/env bash
#
# Store Shift — one-shot health check for a VPS deployment.
#
# Answers, in one screen, the only question that matters after a deploy: is
# every moving part actually running? A migration is entirely queue-driven, so
# "the site loads" proves almost nothing — the worker pool, the watchdog cron and
# Redis are the parts that silently stop and take every merchant's run with them.
#
# Install:
#   sudo cp deploy/healthcheck.sh /usr/local/bin/ss-health && sudo chmod +x /usr/local/bin/ss-health
#
# Run:
#   sudo ss-health
#
# Needs root: supervisorctl and `crontab -u www-data -l` both do.

set -uo pipefail

APP="${APP_DIR:-/var/www/store-shift/app/backend}"
FAILED=""

g() { printf '  \e[32m OK \e[0m %s\n' "$1"; }
r() { printf '  \e[31mFAIL\e[0m %s\n' "$1"; FAILED=1; }
w() { printf '  \e[33mWARN\e[0m %s\n' "$1"; }

if [ ! -d "$APP" ]; then
    echo "Cannot find the app at $APP — set APP_DIR=/path/to/app/backend"
    exit 1
fi

# The domain is read from .env rather than hard-coded, so this script keeps
# working when the domain changes.
DOMAIN=$(grep -m1 '^APP_URL=' "$APP/.env" 2>/dev/null | sed -E 's#^APP_URL=https?://##; s#/.*$##')
: "${DOMAIN:=localhost}"

echo
echo "=============== Store Shift health — $(date '+%F %T') ==============="

# ---------------------------------------------------------------- services
echo
echo "--- Services ---"
for s in apache2 php8.3-fpm mysql redis-server supervisor; do
    if systemctl is-active --quiet "$s" 2>/dev/null; then
        g "$s"
    else
        r "$s is not running"
    fi
done

# ----------------------------------------------------------- worker pool
# The single biggest factor in how long a large migration takes. Eight workers
# turn a ~14-hour 20k-product run into ~3-4 hours; zero workers turn it into
# never, with the merchant watching a progress bar that cannot move.
echo
echo "--- Queue workers ---"
STAT=$(supervisorctl status 'store-shift-worker:*' 2>/dev/null)
if [ -z "$STAT" ]; then
    r "supervisor has no store-shift-worker group — migrations cannot progress"
else
    RUN=$(grep -c 'RUNNING' <<<"$STAT")
    TOT=$(grep -c . <<<"$STAT")
    if [ "$RUN" -eq "$TOT" ]; then
        g "$RUN/$TOT workers RUNNING"
    else
        r "$RUN/$TOT workers RUNNING"
        grep -v 'RUNNING' <<<"$STAT" | sed 's/^/         /'
    fi
fi

# ------------------------------------------------------------------- cron
# Supervisor restarts a dead WORKER. The watchdog recovers a dead MIGRATION —
# a batch whose worker was OOM-killed mid-page, a run whose dispatches were
# lost, a run whose batches all finished but was never closed out.
echo
echo "--- Cron ---"
CRON=$(crontab -u www-data -l 2>/dev/null)
grep -q 'migration:watchdog' <<<"$CRON" && g "watchdog cron installed" || r "watchdog cron MISSING — a stalled run will never recover"
grep -q 'migration:prune'    <<<"$CRON" && g "prune cron installed"    || w "prune cron missing (recommended, not required)"
if grep -q 'queue:keepalive' <<<"$CRON"; then
    w "queue:keepalive cron present — remove it, supervisor already runs the pool (two pools = double the workers)"
fi

# -------------------------------------------------------------------- app
echo
echo "--- App ---"
cd "$APP" || exit 1

[ -f .env ] && g ".env present" || r ".env MISSING"

if sudo -u www-data test -w storage 2>/dev/null; then
    g "storage writable by www-data"
else
    r "storage NOT writable by www-data — every request will 500"
fi

if grep -q '^QUEUE_AUTO_WORKER=false' .env 2>/dev/null; then
    g "QUEUE_AUTO_WORKER=false (correct with supervisor)"
else
    w "QUEUE_AUTO_WORKER is not false — the app will start its own workers ON TOP of supervisor's"
fi

# retry_after below a job's own timeout is the worst bug this engine has had:
# the queue declares a live batch dead and runs a second copy of it.
RETRY=$(php -r '$e=parse_ini_file(".env"); echo $e["REDIS_QUEUE_RETRY_AFTER"] ?? $e["DB_QUEUE_RETRY_AFTER"] ?? 0;' 2>/dev/null)
if [ "${RETRY:-0}" -ge 600 ] 2>/dev/null; then
    g "queue retry_after = ${RETRY}s"
else
    r "queue retry_after = ${RETRY:-unset}s — must be >= 600 or a running batch gets a second worker"
fi

echo
echo "--- Numbers ---"
php artisan tinker --execute="
echo 'pending jobs       : '.\App\Support\QueueKeeper::pending().PHP_EOL;
echo 'failed jobs        : '.DB::table('failed_jobs')->count().PHP_EOL;
echo 'installed shops    : '.\App\Models\Shop::whereNull('uninstalled_at')->count().PHP_EOL;
echo 'active migrations  : '.\App\Models\StoreMigration::whereIn('status',['pending','processing'])->count().PHP_EOL;
echo 'stalled batches    : '.\App\Models\MigrationBatch::where('status','processing')->where('updated_at','<',now()->subMinutes(15))->count().PHP_EOL;
" 2>/dev/null | sed 's/^/         /'

# ------------------------------------------------------------- public URLs
echo
echo "--- Public URLs (https://$DOMAIN) ---"
code() { curl -s -o /dev/null -w '%{http_code}' --max-time 15 "https://$DOMAIN/$1" 2>/dev/null; }

for p in "" "app/" "app/admin"; do
    c=$(code "$p")
    [ "$c" = "200" ] && g "/$p -> $c" || r "/$p -> $c"
done

# The backend folder sits INSIDE the web root because app/index.php boots the
# framework from ./backend/. If Apache ever serves it, .env goes public — and
# .env holds APP_KEY, the database password and the Shopify API secret.
echo
echo "--- Must NOT be public ---"
for p in "app/backend/.env" "app/backend/composer.json" ".git/config" "CLAUDE.md" "deploy/queue-workers.sh"; do
    c=$(code "$p")
    if [ "$c" = "200" ]; then
        r "/$p -> $c  ** LEAKING **"
    else
        g "/$p -> $c"
    fi
done

# ------------------------------------------------------------------ system
echo
echo "--- System ---"
df -h / | tail -1 | awk '{printf "         disk : %s used of %s (%s)\n", $3, $2, $5}'
free -m  | awk '/^Mem:/{printf "         ram  : %sMB used of %sMB\n", $3, $2}'
uptime | sed 's/^/         load :/'

echo
if [ -z "$FAILED" ]; then
    printf '\e[32mAll good.\e[0m\n\n'
    exit 0
else
    printf '\e[31mSomething is wrong — see the FAIL lines above.\e[0m\n\n'
    exit 1
fi
