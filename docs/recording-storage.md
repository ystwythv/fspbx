# Call Recording Storage — S3 archive (shared or customer-owned bucket)

How recordings move from the PBX's local disk into S3-compatible object
storage, per tenant, and how that interacts with the recording webhooks.
Built 2026-09-04 (issues #105, #106, #107). Companion to
`docs/recording-webhooks.md`.

## Why

- **Retention beyond local disk.** `DeleteOldCallRecordings` removes local
  files after `scheduled_jobs/days_keep_call_recordings` (90 days, global).
  Objects in S3 are never touched by that job — retention there is the
  bucket owner's lifecycle policy.
- **Customer-owned storage.** A tenant can point their domain at *their own*
  bucket; the recording (MP3) lands there within ~2 minutes of hangup and
  their CRM receives the bucket + object key in the webhook, so they hold a
  durable pointer to an object they own rather than a time-limited Voxra URL.

## Architecture

```
FreeSWITCH writes WAV → CDR row (record_path=<dir>, record_name=<file>.wav)
        │
        ▼  every minute (scheduled_jobs/recording_archive), both nodes
recordings:dispatch-archives            app/Console/Commands/DispatchRecordingArchives.php
  - only domains in S3StorageConfigService::getArchiveTargets()
  - only files present on THIS node (Horizon/Redis is node-local)
  - claim: insertOrIgnore into recording_archives unique(domain_uuid, record_name)
        │
        ▼  Horizon queue
ArchiveRecordingToS3                    app/Jobs/ArchiveRecordingToS3.php
  → RecordingArchiveService::archive()  app/Services/RecordingArchiveService.php
     - ffmpeg WAV→MP3 (16k mono), putObject, headObject size check
     - every CDR leg sharing the file → record_path='S3', record_name=<object key>
     - recording_webhook_deliveries.record_name renamed in step (dedupe key)
     - local WAV/MP3 deleted
        │
        ▼  next minute
webhooks:dispatch-recordings sees record_path='S3' → sends recording.available
  with a presigned URL against the tenant bucket + recording.storage block

Nightly (scheduled_jobs/s3_upload_calls_<mac>, 01:00 scheduled-jobs TZ):
fs:upload-call-recordings-to-s3-storage — sweeper for anything ≥6h old still
local in an archive-enabled domain (backlog before enablement, exhausted
retries). No-op with nothing to do; report email only when it did something.
```

## Settings

Category `s3_storage`, default settings with per-domain override:

| subcategory | type | default | meaning |
|---|---|---|---|
| `enabled` | boolean | `false` | archive this domain's recordings. Resolved per key: domain value wins over default |
| `access_key`, `secret_key`, `bucket_name` | text | — | required credential set. A domain override is used only when **all three** are present at domain level; otherwise the default set is used |
| `region` | text | `us-east-1` | |
| `endpoint` | text | — | S3-compatible endpoint (R2, Wasabi, MinIO…). Blank = AWS |
| `use_path_style_endpoint` | boolean | — | |
| `signature_version` | text | — | |
| `upload_notification_email` | text | — | nightly sweeper report recipient (default level only) |

Scheduler gates (category `scheduled_jobs`, default settings):

| subcategory | default | meaning |
|---|---|---|
| `recording_archive` | `true` | minute dispatcher on every node |
| `s3_upload_calls_<mac>` | seeded disabled | nightly sweeper, on the node with that MAC |
| `s3_upload_calls_time` | `01:00` | sweeper time |
| `s3_upload_limit` | `2000` | max recordings per sweeper run |

**Enabled without credentials is silently skipped** — `getArchiveTargets()`
omits a domain that has `enabled=true` but no resolvable bucket. Reading
recordings already on S3 uses `getSettingsForDomain()` (credentials only),
so turning a tenant's `enabled` off later does not break playback of what
was already archived.

### Enable a tenant's own bucket (tinker on the primary)

```php
$d = App\Models\Domain::where('domain_name', 'customer.example')->first();
foreach ([
    ['enabled',     'boolean', 'true'],
    ['access_key',  'text',    'AKIA…'],
    ['secret_key',  'text',    '…'],
    ['bucket_name', 'text',    'customer-call-recordings'],
    ['region',      'text',    'eu-west-2'],
    // ['endpoint', 'text', 'https://<account>.r2.cloudflarestorage.com'],
] as [$sub, $type, $value]) {
    App\Models\DomainSettings::updateOrCreate(
        ['domain_uuid' => $d->domain_uuid, 'domain_setting_category' => 's3_storage', 'domain_setting_subcategory' => $sub],
        ['domain_setting_uuid' => (string) Str::uuid(), 'domain_setting_name' => $type,
         'domain_setting_value' => $value, 'domain_setting_enabled' => true]
    );
}
```

The bucket needs `s3:PutObject`, `s3:GetObject` and `s3:HeadObject`
(`GetObject` is what presigned playback URLs use). Nothing is ever deleted
from the bucket by the PBX.

To archive a tenant into a Voxra-owned shared bucket instead, fill the
default `s3_storage` credentials and set only `enabled=true` on the domain.
Object keys are then prefixed by domain name (see below).

## Object key layout

| bucket type | key |
|---|---|
| tenant override (`type=custom`) | `recordings/YYYY/MM/DD/HHMMSS_<direction>_<caller>_<destination>.mp3` |
| shared default (`type=default`) | `<domain_name>/YYYY/MM/DD/HHMMSS_<direction>_<caller>_<destination>.mp3` |

Time components use the domain's `time_zone` setting. Non `[\w-+.]`
characters in number segments become `_`.

## Interaction with recording webhooks

- For an archiving domain, `webhooks:dispatch-recordings` **holds**
  `recording.available` for a fresh local file up to
  `DispatchRecordingWebhooks::ARCHIVE_GRACE_MINUTES` (30) so the archive job
  lands first; the webhook then carries a presigned S3 URL and
  `recording.storage = {type: s3, bucket, key, endpoint, region}`.
- If the archive hasn't happened within the grace window, the webhook goes
  out with the local URL (`storage.type = local`) — recordings are never
  withheld because of a storage problem.
- Domains subscribed to `recording.archived` (`recording_webhook/events`)
  get that event after the archive for files whose `recording.available`
  was sent while still local. Same payload shape.
- Both `recording.available` and `cdr.finalized` / `GET /cdr/calls/{uuid}`
  expose the storage block (`recording.storage` and `recording_storage`).

## Operations

State: `recording_archives` (`pending → archived | failed | skipped`).

```php
App\Models\RecordingArchive::orderByDesc('created_at')->limit(20)
    ->get(['xml_cdr_uuid','status','attempts','last_error','bucket','object_key','archived_at']);
```

- Re-queue failures: `php artisan recordings:dispatch-archives --retry-failed`
- Widen after an outage: `--lookback-hours=72`
- Backlog older than the lookback: the nightly sweeper, or run
  `php artisan fs:upload-call-recordings-to-s3-storage` by hand on the node
  holding the files.
- After deploying: `supervisorctl restart horizon:*` on both nodes (new job class).

| Symptom | Cause |
|---|---|
| archive `skipped`, "disabled or unconfigured" | `enabled` true but credentials missing, or turned off between claim and run |
| archive `failed`, "not present on this node" | claimed on one node, file on the other (should not happen — claim checks `is_file`); re-run `--retry-failed` on the right node |
| archive `failed`, `AccessDenied` | bucket policy lacks PutObject/HeadObject for the key prefix |
| `recording.available` arrives with `storage.type=local` for an archiving domain | archive took >30 min or failed — check `recording_archives`; `recording.archived` follows once it lands if subscribed |
| playback 404 after enabling override creds | override bucket differs from where older objects live — keep the default creds valid for old objects, or migrate them |
