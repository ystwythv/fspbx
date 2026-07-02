# Call Recording Webhooks — Implementation & Configuration Guide

This guide is for agents/developers who need to (a) point a domain's call
recordings at a webhook receiver, or (b) build a new receiver endpoint that
consumes them. The system was built 2026-07-02 (PR #86); the reference
receiver lives in the iqcrm repo (`crm.iqmobile.biz`).

## Architecture

```
FreeSWITCH records call → CDR row lands in v_xml_cdr (record_name set)
        │
        ▼  every minute (scheduler, gated by scheduled_jobs/recording_webhooks)
webhooks:dispatch-recordings          app/Console/Commands/DispatchRecordingWebhooks.php
  - scans recorded CDRs (24h lookback) in webhook-enabled domains
  - one webhook per recording FILE (ring-group legs share a file;
    primary leg = longest billsec)
  - claims work atomically: insertOrIgnore + unique(domain_uuid, record_name)
    in recording_webhook_deliveries — safe with BOTH cluster nodes running it
        │
        ▼  Horizon queue
SendRecordingWebhook                  app/Jobs/SendRecordingWebhook.php
  - builds payload with time-limited recording URLs (CallRecordingUrlService)
  - HMAC-signs the body, POSTs to the domain's configured URL
  - 5 tries, backoff 60s/5m/15m/30m; state in recording_webhook_deliveries
        │
        ▼  HTTPS POST
Receiver (your endpoint)
  - verifies signature, acks fast, fetches the recording URL before it expires
```

Key files in this repo:

| File | Role |
|---|---|
| `app/Console/Commands/DispatchRecordingWebhooks.php` | minute-cron scanner/dispatcher |
| `app/Jobs/SendRecordingWebhook.php` | signs + delivers, retries |
| `app/Services/RecordingWebhookConfigService.php` | per-domain config resolution |
| `app/Services/CallRecordingUrlService.php` | builds recording URLs (local signed routes or presigned S3) |
| `app/Models/RecordingWebhookDelivery.php` | `recording_webhook_deliveries` table |
| `database/migrations/2026_07_02_100000_create_recording_webhook_deliveries.php` | table + default settings seed |
| `app/Console/Kernel.php` | scheduler entry (search `recording_webhooks`) |

## 1. Configuring a domain to send webhooks

Config lives in the standard FusionPBX settings tables, category
`recording_webhook`. Domain settings override defaults per-key. A domain is
active only when the *effective* settings have `enabled=true` **and** a
non-empty `url` **and** `secret`.

| subcategory | type | default | meaning |
|---|---|---|---|
| `enabled` | boolean | `false` | per-domain opt-in |
| `url` | text | — | receiver endpoint (HTTPS POST) |
| `secret` | text | — | HMAC-SHA256 signing key, shared with the receiver |
| `url_ttl` | numeric | `3600` | seconds the recording URLs in the payload stay valid (min 60) |
| `directions` | text | `inbound,outbound,local` | comma-separated CDR directions that trigger a webhook |

Via the UI: Domain Settings → add settings under category `recording_webhook`.

Via tinker (run on the primary, currently voxra-pbx-lon1):

```php
$d = App\Models\Domain::where('domain_name', 'example.com')->first();
foreach ([
    ['subcat' => 'enabled', 'name' => 'boolean', 'value' => 'true'],
    ['subcat' => 'url',     'name' => 'text',    'value' => 'https://receiver.example/api/webhooks/voxra/recording'],
    ['subcat' => 'secret',  'name' => 'text',    'value' => '<64-hex-char shared secret>'],
] as $r) {
    App\Models\DomainSettings::updateOrCreate(
        [
            'domain_uuid' => $d->domain_uuid,
            'domain_setting_category' => 'recording_webhook',
            'domain_setting_subcategory' => $r['subcat'],
        ],
        [
            'domain_setting_uuid' => (string) Illuminate\Support\Str::uuid(),
            'domain_setting_name' => $r['name'],
            'domain_setting_value' => $r['value'],
            'domain_setting_enabled' => true,
        ]
    );
}
```

Generate the secret with `openssl rand -hex 32` and give the same value to the
receiver. Never commit it; on the receiver side it belongs in an env var
(e.g. `VOXRA_RECORDING_WEBHOOK_SECRET` in iqcrm).

The global scheduler gate is the `scheduled_jobs` / `recording_webhooks`
default setting (seeded `true` by the migration). If webhooks aren't firing at
all, check that first, then `php artisan schedule:list | grep dispatch-recordings`.

## 2. The webhook contract (what receivers must implement)

### Request

`POST <url>` with `Content-Type: application/json` and headers:

| Header | Example | Meaning |
|---|---|---|
| `X-Voxra-Event` | `recording.available` | event type (only this one today) |
| `X-Voxra-Delivery` | `<uuid>` | delivery attempt group — stable across retries |
| `X-Voxra-Timestamp` | `1751500000` | unix seconds when signed |
| `X-Voxra-Signature` | `t=1751500000,v0=<hex>` | HMAC signature (below) |

### Signature verification (mandatory)

`v0` is `HMAC-SHA256(secret, "<timestamp>.<raw request body>")` as lowercase
hex. This is the same scheme ElevenLabs webhooks use. Verify like this:

1. Read the **raw body bytes first** — verify before parsing JSON. Any
   re-serialisation will break the signature.
2. Parse `t=` and `v0=` out of `X-Voxra-Signature`.
3. Reject if `|now − t|` > 30 minutes (replay protection).
4. Compute the HMAC over `"<t>.<rawBody>"` and compare with a
   **timing-safe** comparison. Reject with 401 on mismatch.

TypeScript reference: `src/lib/voxra/verify-recording-webhook.ts` in iqcrm.

### Payload

```json
{
  "event": "recording.available",
  "delivery_uuid": "0d63…",
  "cdr_uuid": "b50b24b1-fc3a-4b1e-a3f0-8fb7f596f5bb",
  "domain": "iqmobile.uk",
  "direction": "outbound",
  "extension": "810",
  "extension_name": "Peter",
  "caller_id_name": "810",
  "caller_id_number": "447970030010",
  "caller_destination": "+447722772227",
  "destination_number": "07722772227",
  "start": "2026-07-02T15:58:20+00:00",
  "end": "2026-07-02T16:04:57+00:00",
  "duration": 397,
  "billsec": 393,
  "hangup_cause": "NORMAL_CLEARING",
  "recording": {
    "url": "https://app.voxra.uk/…signed…",
    "download_url": "https://app.voxra.uk/…signed…",
    "filename": "b50b24b1-….wav",
    "expires_at": "2026-07-02T17:04:00+00:00",
    "format": "wav"
  }
}
```

Field notes:

- **`cdr_uuid` is the idempotency key.** Retries resend the same payload
  (with a fresh signature). Dedupe on it.
- For **outbound** calls, `caller_id_number` is the outbound CLI (e.g.
  `447970030010`), *not* the extension — use `extension` for "whose call is
  this" and `destination_number` for the other party. For **inbound**,
  `caller_id_number` is the other party. `caller_id_name` describes the
  caller, so it's only a useful display label for inbound.
- `direction` can be `inbound`, `outbound`, or `local` (internal calls).
- `format` is `wav` for fresh recordings; recordings archived to S3 (nightly
  job) become `mp3`. Receivers must handle both.
- `recording.url` / `download_url` are **time-limited** (`url_ttl`, default
  1h) — either a Laravel signed route (local file) or a presigned S3 URL.
  Fetch promptly; persist the audio yourself if you need it long-term.

### Response & retry semantics

- Respond **2xx within 30 seconds** to acknowledge. Do heavy work (download,
  transcription) asynchronously after acking — the sender's HTTP timeout is
  30s and a timeout counts as a failure.
- Any non-2xx or timeout → the job retries up to 5 times with 60s/5m/15m/30m
  backoff, then the delivery is marked `failed`.
- Because retries exist, your endpoint **must be idempotent** on `cdr_uuid`.

### Receiver checklist

- [ ] Raw-body-first HMAC verification, timing-safe compare, 30-min tolerance
- [ ] 401 on bad/missing signature; 422 on unknown `event`
- [ ] Ack < 30s; heavy work async
- [ ] Idempotent on `cdr_uuid`
- [ ] Fetch recording before `expires_at`; handle `wav` and `mp3`
- [ ] If you do async work after acking, make it crash-safe: persist the
      recording URL + expiry, track a status that can never get stuck
      (see "lessons" below), and sweep/retry stale work on a schedule

## 3. Reference receiver: iqcrm (crm.iqmobile.biz)

`ystwythv/iqcrm` — start here when building a new receiver:

- `src/app/api/webhooks/voxra/recording/route.ts` — verify → upsert a
  `Communication` keyed `externalId = voxra:<cdr_uuid>` → ack → `waitUntil`
  background processing.
- `src/lib/voxra/process-recording.ts` — transcribe + summarise; guarantees
  every exit path reaches a terminal status (`completed` / `skipped_*` /
  `failed`), never a permanent `processing`.
- `src/lib/voxra/transcribe-recording.ts` — grok-stt via Vercel AI Gateway.
  Caps: 30 minutes of audio, 80 MB payload.
- `src/app/api/cron/voxra-recording-retry/route.ts` — every 5 min, re-runs
  stale `processing` (>10 min) and `failed` rows while the stored recording
  URL is still valid; parks rows as `abandoned` after 3 attempts.

Hard-won lessons baked into that implementation:

1. **Background work on serverless dies silently** (instance recycling, OOM).
   Never trust a single in-request attempt; persist enough state (URL +
   expiry + attempt count + started-at) for a cron to finish the job.
2. **Concurrent large-audio jobs OOM the function** — the sweeper retries
   sequentially, which is what actually rescued the first live batch.
3. **grok-stt through the AI Gateway requires AI SDK v7 + `@ai-sdk/gateway` v4**
   (`"Provider xai does not support transcription models"` on the v3/ai@6
   pair; there is no REST `/v1/audio/transcriptions` on the gateway either).
   iqcrm npm-aliases them (`ai-v7`, `gateway-v4`) to avoid a repo-wide
   upgrade.
4. **Some recordings are huge** — one 11.5-min call was a 131 MB WAV
   (high sample rate), over the gateway's request limit. Fail fast on size;
   transcoding at the PBX before webhook is the eventual fix (iqcrm#73).

## 4. Operations

Delivery state lives in `recording_webhook_deliveries`
(status: `pending` → `sent` | `failed` | `skipped`):

```php
// recent deliveries
App\Models\RecordingWebhookDelivery::orderByDesc('created_at')->limit(20)
    ->get(['xml_cdr_uuid','status','attempts','last_error','sent_at']);
```

- Re-queue everything that failed: `php artisan webhooks:dispatch-recordings --retry-failed`
- Re-send a specific recording: delete its delivery row, the next minute-cron
  re-dispatches it (receivers must tolerate the repeat — idempotency again).
- Widen the scan window after an outage: `--lookback-hours=72`.

Common failure signatures:

| Symptom | Cause |
|---|---|
| deliveries all `failed`, `HTTP 401` | secret mismatch between domain setting and receiver env |
| delivery `skipped`, "disabled at send time" | domain settings changed between dispatch and send |
| no deliveries created at all | domain not enabled/url/secret missing, or `scheduled_jobs`/`recording_webhooks` off |
| job class errors after deploy | **Horizon must be restarted after deploying new job code**: `supervisorctl restart horizon:horizon_00` on both nodes |
| receiver got the webhook but can't fetch audio | receiver processed after `url_ttl` expired — raise `url_ttl` or fetch sooner |

Deploys: `ansible-playbook -i hosts.ini voxra.yml --tags fspbx --private-key
~/.ssh/id_ed25519` from iqm-ansible (check PLAY RECAP, not exit code), then
restart Horizon on both nodes.

## 5. Adding a new event type (future work)

Follow the same pattern: a scanner (or hook) that claims work atomically in a
deliveries table, a queued job that signs with the same header scheme, and a
new `X-Voxra-Event` value. Keep the signature scheme identical so receivers
can share one verifier. Candidate next event: `transcription.completed`
(fired from `CallTranscriptionService` when AssemblyAI transcripts land).
