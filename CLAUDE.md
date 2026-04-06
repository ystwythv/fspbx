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

## ElevenLabs integration

- **TTS:** `app/Services/Tts/ElevenLabsTtsService.php` — text-to-speech for greetings
- **STT:** `app/Services/Stt/ElevenLabsSttService.php` — speech-to-text for transcription
- **Conversational AI agents:** `app/Services/ElevenLabsConvaiService.php` — creates agents + SIP trunk phone numbers via ElevenLabs API, FreeSWITCH bridges calls to `sip.rtc.elevenlabs.io:5060`
- API key configured via `ELEVENLABS_API_KEY` env var (needs voices_read, convai permissions)

## Deployment

Deployed via Ansible at `~/github/iqm-ansible/`. **Do not push directly to production — use the Ansible playbook.**

### Servers

| Role | Host | IP |
|------|------|----|
| Primary | voxra-pbx-lon1 | 172.236.17.39 |
| Secondary | voxra-pbx-eu1 | 139.162.195.218 |

Both run Ubuntu 24.04 on Linode. PostgreSQL streaming replication (primary → secondary). File sync via Syncthing.

### SSH access

```bash
# Primary server
ssh -i ~/.ssh/id_ed25519 root@172.236.17.39

# Secondary server
ssh -i ~/.ssh/id_ed25519 root@139.162.195.218
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

# Rebuild frontend assets
npm run build

# Check FreeSWITCH status
systemctl status freeswitch
```

### Ansible commands

```bash
cd ~/github/iqm-ansible

# Full deploy (all tasks)
ansible-playbook voxra.yml

# Deploy only fspbx code updates (git pull, composer, npm build, migrations)
ansible-playbook voxra.yml --tags fspbx

# Other tags: firewall, certbot, nginx, verto, postgres, syncthing
```

### What the fspbx Ansible task does (`tasks/voxra/install-fspbx.yml`)

On first run: clones repo, installs FreeSWITCH + PHP + dependencies.
On subsequent runs: `git fetch + reset --hard` to deploy latest code, then `composer install`, `npm run build`, `php artisan migrate`, config/route cache clear.

### Key Ansible vars (in `group_vars/voxra.yml`)

- `fspbx_repo`: GitHub repo URL
- `fspbx_branch`: `feature/elevenlabs`
- `fspbx_web_root`: `/var/www/fspbx`
- Secrets (API keys, DB passwords) in `secrets.yml` (vault-encrypted)

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
