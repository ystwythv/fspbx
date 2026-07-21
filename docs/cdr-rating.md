# CDR rating engine

Populates the `cost` / `cost_currency` fields that the CDR API exposes on
call records, stats summaries and top-destinations (issue #8, first slice:
tariffs + rating; provider feed imports and a tariff admin UI are follow-ups).

## Data model

- `v_call_tariffs` — one row per price list. `domain_uuid = NULL` is the
  global default; a row bound to a domain overrides it for that tenant.
  `currency` is a 3-letter code stamped onto each rated CDR.
- `v_call_rates` — per-destination-prefix pricing inside a tariff:
  `rate_per_minute`, `setup_fee`, `min_duration_sec`,
  `billing_increment_sec`, and an `effective_from`/`effective_to` window.
  **Versioning:** never edit a live rate — close its window and insert a
  new row. Rating picks the rate whose window contains the call start, so
  historical CDRs always re-rate identically.
- `v_xml_cdr.call_cost` (numeric 10,4), `call_cost_currency`,
  `call_cost_rate_uuid` (which rate produced the figure).

## How a call is rated

Outbound CDRs only. The dialled number is normalised (`+44…`/`0044…` →
`44…`), matched longest-prefix-first against the tenant's tariff (falling
back to the global tariff). Unanswered calls cost 0. Answered calls pay
`setup_fee + rate_per_minute × billable/60` where billable is `billsec`
raised to `min_duration_sec` and rounded up to `billing_increment_sec`.

## Operations

```bash
# load a rate deck (creates the tariff on first import)
php artisan tariffs:import-rates "Standard UK" rates.csv --create --currency=GBP

# rate the last 2 hours (what the scheduler runs)
php artisan cdr:rate

# backfill history once tariffs exist
php artisan cdr:rate --from=2026-01-01T00:00:00Z --to=2026-07-20T00:00:00Z

# re-rate after fixing a rate deck
php artisan cdr:rate --from=... --to=... --rerate
```

CSV columns: `prefix,rate_per_minute[,setup_fee,min_duration_sec,billing_increment_sec,effective_from,effective_to]`.

The scheduler entry (`cdr:rate` every 5 minutes) is gated by the
`scheduled_jobs` / `cdr_rating` default setting, seeded **disabled**.
Enable it after loading the first tariff:

```sql
UPDATE v_default_settings SET default_setting_value = 'true'
WHERE default_setting_category = 'scheduled_jobs'
  AND default_setting_subcategory = 'cdr_rating';
```

Stats endpoints report a cost total only while every rated call in the
grouping shares one currency; mixed-currency groups return `null` rather
than a meaningless sum.
