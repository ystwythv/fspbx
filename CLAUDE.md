# Voxra (fspbx fork)

Fork of [nemerald-voip/fspbx](https://github.com/nemerald-voip/fspbx) — a Laravel/Vue.js frontend for FreeSWITCH PBX (originally FusionPBX).

- **Origin:** `upstream` remote points to `nemerald-voip/fspbx`
- **This fork:** `origin` at `ystwythv/fspbx`, branch `feature/elevenlabs`
- **Branding:** Rebranded as "Voxra" (domain: `app.voxra.uk`)

## Stack

- **Backend:** Laravel 10, PHP 8.4, PostgreSQL 17, Redis
- **Frontend:** Vue.js 3 with Inertia.js, Tailwind CSS, SyncFusion DataTable components, Vueform
- **PBX:** FreeSWITCH with dialplan XML generated via Blade templates
- **Multi-tenant:** All queries scoped by `domain_uuid`

## Key patterns

- UUIDs as primary keys (`TraitUuid` mixin), tables prefixed `v_`
- String booleans (`'true'`/`'false'`) in DB columns matching FusionPBX convention
- Routing destinations defined in `app/Services/CallRoutingOptionsService.php` — any new destination type must be added there, plus `app/helpers.php` `buildDestinationAction()`, and the relevant controllers (VirtualReceptionist, RingGroup, BusinessHours, Extensions)
- Dialplan templates in `resources/views/layouts/xml/`
- Vue pages in `resources/js/Pages/`, following existing patterns (e.g. `VirtualReceptionists.vue`)
- Permissions seeded in `database/seeders/DatabaseSeeder.php`
- Menu items stored in `v_menu_items` / `v_menu_item_groups` DB tables

## Call recording webhooks

Per-domain webhooks fire when a call recording becomes available (signed
POST with time-limited recording URLs). **See `docs/recording-webhooks.md`**
for the full contract, how to enable a domain, how to build a receiver, and
operations/troubleshooting. Reference receiver: iqcrm
(`src/app/api/webhooks/voxra/recording/route.ts`). Per-domain S3 archive
(shared or customer-owned bucket) and the `recording.archived` event:
**`docs/recording-storage.md`**.

## ElevenLabs integration

- **TTS:** `app/Services/Tts/ElevenLabsTtsService.php` — text-to-speech for greetings
- **STT:** `app/Services/Stt/ElevenLabsSttService.php` — speech-to-text for transcription
- **Conversational AI agents:** `app/Services/ElevenLabsConvaiService.php` — creates agents + SIP trunk phone numbers via ElevenLabs API, FreeSWITCH bridges calls to `sip.rtc.elevenlabs.io:5060`
- API key configured via `ELEVENLABS_API_KEY` env var (needs voices_read, convai permissions)

## Deployment

Deployed via Ansible at `~/github/iqm-ansible/` (`voxra.yml --tags fspbx`). **Merging to main does NOT deploy.** No GitHub Action deploys to the PBXs. Someone has to run the playbook, preferably out of hours. **Do not push directly to production. Use the Ansible playbook.**

### Servers

| Role | Host | Tailscale IP (use this) | Public IP (firewalled) |
|------|------|----|----|
| Primary | voxra-pbx-lon1 | 100.109.255.53 | 172.236.17.39 |
| Secondary | voxra-pbx-eu1 | 100.108.17.88 | 139.162.195.218 |

Both run Ubuntu 24.04 on Linode (2 vCPU / 4 GB). PostgreSQL replication runs between them, and Syncthing syncs `storage/` except `logs/` and the Vite bundle. fail2ban bans an IP for 10 minutes after a few failed ssh auths, so don't retry in a loop.

### SSH access

```bash
ssh -i ~/.ssh/id_ed25519 root@100.109.255.53   # lon1
ssh -i ~/.ssh/id_ed25519 root@100.108.17.88    # eu1
```

App root on servers: `/var/www/fspbx`

Useful server-side commands:
```bash
cd /var/www/fspbx

# Check Laravel logs
tail -100 storage/logs/laravel.log

# Laravel tinker (interactive REPL)
php artisan tinker

# Clear caches
php artisan config:cache && php artisan route:cache

# Check FreeSWITCH status
systemctl status freeswitch
```

**Never run `npm install` / `npm run build` on a PBX** (voxragtm#148). A Vite build on these 2-vCPU boxes starved FreeSWITCH, and inbound calls failed for about 4 minutes. `update.sh` and `php artisan app:update` (upstream FS PBX tooling) still call `npm run build`. Don't use them on Voxra servers.

### Frontend assets (built in CI)

`.github/workflows/assets.yml` runs `npm ci && npm run build` on every push to main and every PR. It uploads the bundle as the workflow artifact `fspbx-assets-<commit sha>`. Vite writes to `storage/app/public/vite` (see `vite.config.js` `build.outDir`, and `AppServiceProvider` `useBuildDirectory('storage/vite')`), which is served through the `public/storage` symlink. That's why there is no `public/build`.

On the servers, `storage/app/public/vite` is a symlink to `storage/app/public/vite-releases/<sha>/`. The deploy swaps the symlink atomically. If a merge's frontend build fails, that commit can't be deployed. Fix the build first.

### Ansible commands

```bash
cd ~/github/iqm-ansible

# Deploy fspbx code (one server at a time, eu1 first)
ansible-playbook -i hosts.ini voxra.yml --tags fspbx -u root --private-key ~/.ssh/id_ed25519

# Deploy / roll back to a specific main commit
ansible-playbook -i hosts.ini voxra.yml --tags fspbx -u root --private-key ~/.ssh/id_ed25519 -e fspbx_deploy_sha=<40-char sha>

# Other tags: firewall, certbot, nginx, verto, postgres, syncthing
```

Afterwards, run the SIP harness: `cd ~/github/iqm-ansible/tests/sip-test && node run-tests.js -v`.

### What the fspbx Ansible task does (`tasks/voxra/install-fspbx.yml`)

On first run, it clones the repo and installs FreeSWITCH, PHP and dependencies.

On later runs:
1. Pins the `main` head sha once for the whole run.
2. Waits for that sha's CI asset bundle, then downloads it to the controller. If the bundle isn't there, the run fails before either server is touched.
3. Then, one server at a time:
   - unpacks the bundle
   - `git fetch <sha>` + `reset --hard`
   - `composer install`
   - swaps the assets symlink
   - `php artisan migrate`
   - caches the config and routes, and restarts the queue
   - `chown` of `storage/` and `bootstrap/cache` to www-data

### Key Ansible vars (in `group_vars/voxra.yml`)

- `fspbx_repo`: GitHub repo URL
- `fspbx_branch`: `main`
- `fspbx_web_root`: `/var/www/fspbx`
- Secrets (API keys, DB passwords, `github_token`) are in the gitignored `secrets.yml`. It's plaintext, not vault-encrypted.

## Development

```bash
# Install dependencies
composer install
npm install

# Dev server
npm run dev

# Build for production
npm run build

# Run migrations
php artisan migrate

# Seed permissions and providers
php artisan db:seed --class=DatabaseSeeder
```
