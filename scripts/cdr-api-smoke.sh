#!/usr/bin/env bash
# CDR API end-to-end smoke test (issue #15 steps 3, 5, 6).
#
# Usage:
#   TOKEN=... [HOST=https://app.voxra.uk] [DOMAIN_UUID=...] [TEST_429=1] \
#     scripts/cdr-api-smoke.sh
#
# TOKEN        bearer token (global admin token covers every check;
#              a tenant token skips the fleet-wide check)
# DOMAIN_UUID  required for the tenant-scoped list / CSV / header checks
# TEST_429     set to 1 to hammer the stats endpoint until a 429 appears

set -u

HOST="${HOST:-https://app.voxra.uk}"
TOKEN="${TOKEN:?set TOKEN to a CDR API bearer token}"
DOMAIN_UUID="${DOMAIN_UUID:-}"

if date -u -d '-7 days' >/dev/null 2>&1; then
    DATE_FROM=$(date -u -d '-7 days' +%Y-%m-%dT%H:%M:%SZ)
else
    DATE_FROM=$(date -u -v-7d +%Y-%m-%dT%H:%M:%SZ)   # BSD date
fi
DATE_TO=$(date -u +%Y-%m-%dT%H:%M:%SZ)
WINDOW="date_from=${DATE_FROM}&date_to=${DATE_TO}"
AUTH="Authorization: Bearer ${TOKEN}"

pass=0
fail=0

check() {
    local label="$1" expected="$2" url="$3"
    local status
    status=$(curl -s -o /tmp/cdr-smoke-body -w '%{http_code}' -H "$AUTH" "$url")
    if [ "$status" = "$expected" ]; then
        echo "PASS  ${label} (${status})"
        pass=$((pass + 1))
    else
        echo "FAIL  ${label} — expected ${expected}, got ${status}"
        head -c 300 /tmp/cdr-smoke-body; echo
        fail=$((fail + 1))
    fi
}

echo "== CDR API smoke: ${HOST} =="

# fleet-wide summary (global tokens only)
check "global stats summary" 200 \
    "${HOST}/api/v1/cdr/stats/summary?${WINDOW}&group_by_domain=true" \
    || true

if [ -n "$DOMAIN_UUID" ]; then
    check "tenant call list" 200 \
        "${HOST}/api/v1/domains/${DOMAIN_UUID}/cdr/calls?${WINDOW}&limit=5"
    check "tenant CSV export" 200 \
        "${HOST}/api/v1/domains/${DOMAIN_UUID}/cdr/calls.csv?${WINDOW}"

    echo "-- rate limit headers (expect x-ratelimit-limit: 30 on stats) --"
    curl -sI -H "$AUTH" \
        "${HOST}/api/v1/domains/${DOMAIN_UUID}/cdr/stats/summary?${WINDOW}" \
        | grep -i ratelimit || { echo "FAIL  no X-RateLimit-* headers"; fail=$((fail + 1)); }

    if [ "${TEST_429:-0}" = "1" ]; then
        echo "-- hammering stats endpoint for a 429 (31+ requests) --"
        got429=""
        for i in $(seq 1 35); do
            status=$(curl -s -o /dev/null -w '%{http_code}' -H "$AUTH" \
                "${HOST}/api/v1/domains/${DOMAIN_UUID}/cdr/stats/summary?${WINDOW}")
            if [ "$status" = "429" ]; then
                echo "PASS  429 received on request ${i}"
                pass=$((pass + 1)); got429=1; break
            fi
        done
        [ -z "$got429" ] && { echo "FAIL  no 429 after 35 requests"; fail=$((fail + 1)); }
    fi
else
    echo "SKIP  tenant checks (set DOMAIN_UUID to enable)"
fi

echo "== done: ${pass} passed, ${fail} failed =="
exit $((fail > 0))
