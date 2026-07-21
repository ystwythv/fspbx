# CDR webhooks (cdr.finalized)

Per-domain webhooks that fire when a CDR is finalized, so tenants can
build real-time dashboards without polling the CDR API (issue #9).
Companion to `docs/recording-webhooks.md` — the signing scheme is
identical, so one receiver-side verifier covers both event families.

## Subscribing

Webhooks live in `v_api_webhooks` (up to 10 per domain) and are managed
by tenants themselves, gated by the `api_webhook_manage` permission:

- **UI**: Settings → API Webhooks (`/api-webhooks`) — create, rotate
  secret, delete, and inspect recent delivery attempts.
- **API**: `GET/POST /api/v1/domains/{domain_uuid}/webhooks`,
  `POST .../webhooks/{uuid}/rotate-secret`, `DELETE .../webhooks/{uuid}`,
  `GET .../webhooks/{uuid}/deliveries`.

The signing secret (`whsec_…`) is returned exactly once on create/rotate.

## Payload

```json
{
  "event": "cdr.finalized",
  "delivery_uuid": "…",
  "domain_uuid": "…",
  "created_at": "2026-07-20T12:00:00Z",
  "call": { …same shape as GET /api/v1/domains/{d}/cdr/calls/{uuid}… }
}
```

`call` is built by the same code path as the CDR detail endpoint
(`CdrCallDetailService`), including quality metrics, cost (if the rating
engine has run) and a 30-minute signed `recording_url` when a recording
exists. Idempotency key: `call.xml_cdr_uuid` (or `delivery_uuid` per
attempt-set).

## Signing / verification

Headers: `X-Voxra-Event`, `X-Voxra-Delivery`, `X-Voxra-Timestamp`,
`X-Voxra-Signature: t=<ts>,v0=<hex>` where
`v0 = HMAC-SHA256(secret, "<timestamp>.<raw body>")`. Verify against the
raw body, timing-safe compare, reject timestamps older than 30 minutes.
See `docs/recording-webhooks.md` §3 for reference receiver code.

## Delivery + retries

Deliveries are queued (Horizon `default` queue): initial attempt + 6
retries with backoff 1m / 5m / 30m / 2h / 6h / 16h (~24h total), then
marked `failed`. A 2xx within 30s acks.

Every attempt-set is a row in `v_api_webhook_deliveries`
(status/attempts/last_error/sent_at), and each webhook row tracks
`last_success_at`, `last_failure_at` and `consecutive_failures` — so a
flapping endpoint is visible per domain in the UI/API without tailing
the queue worker.

## Dispatch mechanics / ops

`webhooks:dispatch-cdr-events` runs every minute (gate:
`scheduled_jobs`/`cdr_webhooks` default setting, seeded enabled; it
no-ops when no webhooks exist). It scans finalized primary-leg CDRs
(same exclusions as the CDR API list) over a 24h lookback and claims
work via the unique index on `v_api_webhook_deliveries` — safe to run on
both cluster nodes. The scan is used instead of a model observer because
`cdr_import` writes CDRs without booting Laravel.

Re-queue exhausted deliveries after a receiver outage:

```bash
php artisan webhooks:dispatch-cdr-events --retry-failed
```
