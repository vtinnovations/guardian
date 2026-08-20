# Guardian

*[🇩🇪 Diese Seite auf Deutsch lesen](README.md)*

**Update, Backup & Recovery bundle for Contao 5** by [V&T Innovations](https://v-t.one)

Guardian keeps your Contao installation up to date and makes sure you can always get back — even if an update completely wrecks the site. Composer updates run as a background job, backups are available both manually and on a schedule, and a standalone recovery panel keeps working even when Contao itself no longer boots.

## Status

Version 1.0.0, production-ready. Every feature described below is fully implemented (backend controllers, CLI background worker, standalone recovery panel) and covered by a PHPUnit test suite.

## Feature overview

| Feature | Free | Pro |
|---|---|---|
| Manual backup (DB, composer.json/lock, vendor/, templates/, files/, assets/) | ✅ | ✅ |
| Pre-update analysis & package overview | ✅ | ✅ |
| Update jobs (Composer Full / Conservative / Selective) | — | ✅ |
| Restore / rollback from snapshots | — | ✅ |
| Scheduled backups (Mini + Full, cron/web-cron) | — | ✅ |
| Standalone recovery panel | — | ✅ |
| Recovery e-mail notifications | — | ✅ |

Without an activated licence, only the Dashboard and Settings tabs are reachable — even Free and Trial require a key signed by V&T. Licences are available at [v-t.one](https://v-t.one).

## Requirements

- PHP ≥ 8.2 (a CLI binary is required; its path is configurable in the backend)
- Contao ≥ 5.3
- PHP extensions `json` and `sodium` (licence verification fails closed without `sodium` — see [Security model](#security-model))
- `composer.phar` reachable (project root, Plesk path, or configured)
- For database backups/restores: `mysqldump`/`mysql` CLI tools recommended (a PHP-based fallback runs if these are missing)
- For file backups/restores: `tar` (a `ZipArchive` fallback runs if `tar` is unavailable)

## Installation

```bash
composer require vtinnovations/guardian
```

Contao Manager registers the bundle automatically (`ContaoManager\Plugin`). Then clear the cache and update the database:

```bash
vendor/bin/contao-console cache:clear
vendor/bin/contao-console contao:migrate
```

The **Guardian** menu item appears in the Contao backend under "Content" (shows the number of available package updates in parentheses once any exist).

## Getting started

1. **Activate the licence:** Contao → Settings → **Guardian Licence management** → enter the key → save settings. The key is verified against the V&T licence server; the signed result is stored locally and bound to the configured domain(s).
2. **Set the PHP CLI path** (if auto-detection fails): Settings tab → PHP CLI settings. On Plesk, for example, `/opt/plesk/php/8.3/bin/php`. Updates and restores run as a separate background process and need this path.
3. **First backup:** Backup tab → choose components → "Create backup now".
4. **Enable the recovery panel** (Pro, recommended before risky updates) — see [Standalone recovery panel](#standalone-recovery-panel-pro).

## Backend interface

Access requires a logged-in Contao administrator (`ROLE_ADMIN`). The interface is organised into five tabs, in this order:

1. **Dashboard** — status figures (Contao version, installed packages, available backups), status of the last operation, pre-update analysis, package overview.
2. **Update** *(Pro)* — start an update job (dry run or real update), live progress, job history.
3. **Backup** *(Free for manual backups, Pro for scheduled)* — manual backup, list of existing backups, scheduled Mini/Full backups.
4. **Recovery** *(Pro)* — standalone recovery panel settings, access-token management.
5. **Settings** — link to licence management, recovery e-mail configuration, PHP CLI settings.

Without a licence, Update, Backup and Recovery are locked (only Dashboard + Settings remain). A Free licence additionally unlocks manual backup; Update, scheduled backups, restore and the recovery panel stay Pro-only.

## Pre-update analysis

Read-only, changes nothing. Checks: PHP version (≥ 8.2), whether a `composer` binary can be found, write permissions on `vendor/`, `var/` and `public/`, free disk space (warns below 500 MB), abandoned Composer packages, whether `DATABASE_URL` is set in `.env`/`.env.local`, and whether legacy Contao 3 modules exist under `system/modules/`.

## Package overview

Shows all installed Composer packages and, on request, queries **Packagist directly** for each package's latest stable version — independent of the current `composer.json` constraints, so updates that the existing constraints don't (yet) allow are still visible. Results are cached for 24 hours; a "Check for updates" button forces a fresh query. Whether a shown update can actually be installed depends on other packages' dependency constraints (tagged "blocked").

## Backup

Backups live under `var/updater/backup/<timestamp>` and are **never overwritten** — if a timestamp collides, the operation fails with an error instead of replacing anything.

| Component | Included | Note |
|---|---|---|
| `composer.json` + `composer.lock` + database (gzip) | always | small, a few MB |
| `vendor/` | default: on | typically 100–500 MB |
| `templates/` + `contao/templates/` | default: on | your own Twig/HTML5 templates, small |
| `files/` | default: off | uploads, can be very large |
| `assets/` | default: off | usually regenerable |

Directories are archived with `tar` (gzip-compressed); if `tar`/`exec()` is unavailable, a `ZipArchive` fallback takes over. Database dumps use `mysqldump`, with a PHP-based fallback if the tool is missing. If `DATABASE_URL` can't be resolved, only the database portion is marked "skipped" — the rest of the backup still completes.

## Update modes

| Mode | Behaviour |
|---|---|
| **Full** | `composer update` with all dependencies — everything within the composer.json constraints |
| **Conservative** | Same as Full, plus `--prefer-stable`. Not a true patch-only mode in the semantic sense — for strict patch-only behaviour, pin `~X.Y.Z` in `composer.json` itself |
| **Selective** | Only the selected packages (+ their dependencies) |

Every real update job runs in its own background process, detached from the web server:

```
Backup → Maintenance mode ON → composer update → Clear cache → Migrations → Maintenance mode OFF
```

The browser tab doesn't need to stay open; progress and the live log are fetched via polling. A **dry run** first creates a real backup (without `vendor/`) as a safety net, then genuinely calls `composer update --dry-run` and `contao:migrate --dry-run` — the cache-clear step is simulated only, nothing is actually cleared.

If a real update fails, the pre-update snapshot created beforehand is available for a rollback with one click — a deliberate, manual step, not an automatic action. Whether the snapshot includes the `vendor/` directory depends on the option chosen at start time (default: included); without `vendor/` in the snapshot, a rollback covers only Composer files and the database. The restore itself processes the selected components one after another; if a step fails, already-completed steps are **not automatically reverted** — in that case, the [standalone recovery panel](#standalone-recovery-panel-pro) is the way forward.

A "Force abort" button marks a stuck job as cancelled but does not necessarily terminate the underlying background process — only use it when the job genuinely stops responding.

## Scheduled backups (Pro)

- **Mini backup:** database + Composer files only — fast, suitable for daily runs
- **Full backup:** additionally selectable: vendor/, templates/, files/, assets/
- Frequency (5-/15-minute and hourly intervals for testing, daily/weekly/monthly for production), time of day, retention per type
- Runs through Contao's built-in cron system (web-cron with no setup, or a real server cron for punctual execution — instructions for Plesk/cPanel/DirectAdmin/SSH are right in the Backup tab)
- A file lock prevents two scheduled or manually-triggered schedule runs from running at the same time
- Optional e-mail notification on success/failure

**On retention:** it only removes older backups **of the same schedule type** (Mini or Full). Backups created manually via the Backup tab, and automatic pre-update/pre-restore snapshots, are not covered and must be deleted manually if needed.

## Standalone recovery panel (Pro)

A single PHP file with no framework dependencies, served directly by the web server — it keeps working even when Symfony/Contao no longer boots after a broken update. It can list backups, restore components selectively (including a database import), and control maintenance mode. Unlike the regular in-app restore, it cannot restore `contao/templates/` separately from `templates/`.

**Deployment is opt-in for security reasons.** In `.env.local`:

```
VTINNOVATIONS_GUARDIAN_DEPLOY_RECOVERY_PANEL=1
```

Then clear the cache — the file is copied into `public/` on the next kernel boot. Deployment is additionally tied to a valid Pro licence: if entitlement is missing or the flag is removed, the file is automatically removed from the webroot again on the next boot.

- **Configurable filename** (Recovery tab): default `_updater-recovery.php`; a custom name makes it harder for scanners to find. Renaming automatically removes the old file.
- **Authentication:** HTTP Basic Auth (any username, token as password) or `Authorization: Bearer`. A token in the URL query string is deliberately rejected (leak risk via logs/referrer). Token sources, in this order: `VTINNOVATIONS_GUARDIAN_TOKEN` in `.env.local` (preferred), or an automatically generated 96-character hex token in `var/updater/access.token`, rotatable from the backend.
- **Brute-force protection:** 8 failed attempts per IP address within 15 minutes → a 15-minute lockout. Clients sharing a proxy/CDN with a single outbound IP share the same lockout budget.
- **Path-traversal protection:** archive entries are validated before extraction (no absolute paths, no `..`, no escaping the target directory).
- **Recommendation:** enable the panel only for the duration of a risky update, and additionally protect it at the web-server level (IP allowlist / HTTP auth). **Test access before an update** — there's no catching up on this once you actually need it.
- **Recovery e-mail:** before starting a real update, Guardian can e-mail the panel URL and the full access token to a configured address. If sending fails, the update is **not started**, for safety. This e-mail is equivalent to a full access key to the site — delete it after a successful update, never forward it.

## Licensing

Central interface: **Contao → Settings → Guardian Licence management** (server-rendered, inside Contao's own settings form, no bundle route of its own). Three buttons: "Verify & activate licence", "Update licence", "Remove licence" — the last one asks for confirmation first.

- Verification against `https://www.v-t.one/api/v1/verify`, product `vt-guardian`, project slug `guardian`.
- **Every** tier (Trial, Free, Pro) requires an activated, signed licence key. There is no anonymous free mode and no locally-startable trial period — validity periods come exclusively from signed server data and cannot be reset by reinstalling, or clearing cache/files.
- The licence record is stored as exact bytes under `var/updater/registration.json`, together with the signed integrity envelope (`registration.seal`) and the bound domain (`registration.scope`). Any change to any of these files results in "unlicensed" on the next read — there is no read path around the signature check.
- Binding is to the exact hostname(s) configured on the website root pages (signed). No wildcard, no suffix matching, no automatic `www`/apex equivalence. For installations without a configured root domain, the domain inventory can be extended via `VTINNOVATIONS_GUARDIAN_DOMAINS` (comma-separated list).
- Network errors, timeouts and 5xx responses **never delete** a valid licence. Only a successfully verified response, or an explicit removal by the administrator, changes the state.
- Signatures: Ed25519, pinned key `vtone-2026a`. Without PHP `sodium`, nothing can be verified — the installation stays unlicensed (fail closed).

### Effective licence states

| State | Available features |
|---|---|
| No licence activated | Dashboard and Settings only |
| Trial active | Full feature set (time-limited) |
| Free active | Manual backup only |
| Pro active | Full feature set |
| Pro expired, Free fallback permitted | Manual backup only — exclusively when the signed record explicitly allows it |
| Licence expired (no fallback) | All licensed features disabled |
| Licence not yet valid | All licensed features disabled |
| Domain not covered by the licence | All licensed features disabled |
| Licence withdrawn by the server / package not supported / invalid | All licensed features disabled |

Trial and Pro are entitlement-equivalent (full feature set); the only difference is the time limit on the signed record.

### Server-initiated updates

V&T can actively deliver a new licence package to:

```
POST /rest/api/v1/guardian-license-updater
```

The endpoint sits deliberately outside the backend login (server-to-server) and is instead cryptographically authenticated: signed method, path, request ID, timestamp, nonce and body hash. Replays are blocked via a replay ledger (`var/updater/exchange.journal`); an exact retry returns `already_processed`, older versions are rejected.

> **Multi-node deployments:** the ledger and licence state live on the filesystem under `var/`. Multiple nodes without a shared `var/` need a transactional, shared store.

### Signals to v-t.one

- at most once per backend request: `{"project":"Guardian","domain":"<host>"}`
- once per authenticated backend session, the first time the licence management screen is opened: `{"domain":"<host>","key":"<key>"}`

Both are sent exclusively server-side to `https://www.v-t.one/rest/api/v1/log-envoke`, after the response has already been sent to the browser — they never delay a page. The key leaves the server only in this one signal and during activation/refresh — never to the browser, never into logs. Both signals are best-effort: their success or failure has no effect whatsoever on licence verification.

## Architecture (brief overview)

```
src/
├── Guardian.php                  Bundle class; deploys/removes the recovery panel on boot
├── ContaoManager/Plugin.php      Contao Manager integration (bundle + routes)
├── Controller/                   Backend API (jobs, backups, schedule, panel, runtime) + registration actions
├── Job/                          UpdateJob, JobRunner, steps (backup, composer, migrate, …)
├── Backup/  Restore/             BackupManager / RestoreManager
├── Schedule/  Cron/              Scheduled backups (config, state, lock, runner, evaluator) + Contao cron job
├── Checker/                      Pre-update checks, package verification, licence-check trust anchors
├── External/                     Endpoints, registry transport, signals, replay ledger, panel token management
├── Security/                     Admin gate, CSRF, request authentication
├── Service/                      Runtime configuration, platform checks, registration state, package overview, …
├── EventListener/                Backend menu, system messages, DCA panel, usage-signal delivery
├── Notifier/                     Backup & recovery e-mails
└── Command/RunJobCommand.php     CLI worker (`guardian:run-job`, internal; also callable manually for diagnostics)

contao/dca/tl_settings.php        Licence section in Contao → Settings
public/_updater-recovery.php      Standalone recovery panel (zero dependencies)
templates/backend/…twig           Backend interface (tabs: Dashboard, Update, Backup, Recovery, Settings)
```

Working data lives under `var/updater/` (jobs, logs, backups, runtime configuration, registration state, replay ledger, recovery token, schedule state, backup lock).

## Security model

- Every backend endpoint requires a **Contao administrator** (`ROLE_ADMIN`).
- A same-origin CSRF check (Origin, with a Referer fallback) applies to every state-changing request; the updater endpoint is exempt and is instead authenticated by request signature.
- Licence gates are enforced server-side at every feature boundary — including the CLI worker, the cron runner, recovery e-mails and panel deployment at bundle boot. UI locks are convenience only.
- No private signing keys and no reusable shared secrets ship in the package; only the public verification key is embedded.
- Licence keys, payloads, digests, signatures and nonces never appear in logs or browser responses (structurally enforced by a dedicated test suite).
- Composer always runs as `<configured PHP> composer.phar` — never through a shell wrapper (avoids an extension mismatch between CLI and web PHP, typical on Plesk).
- Database passwords are passed via the `MYSQL_PWD` environment variable, never as a command-line argument.
- Both the regular restore and the standalone recovery panel validate every archive entry before extraction (no zip-slip, no escaping the target directory).
- Restore/rollback is **best-effort**, not transactional: components are restored one after another; if a step fails, no already-completed step is automatically reverted.

## Runtime directories

Everything lives under `var/updater/`, including:

- `registration.json` / `.seal` / `.scope` — licence record, integrity envelope, bound domain
- `exchange.journal` — replay ledger for server-initiated updates
- `job.json` / `job.log` — the current update/restore job and its live log
- `backup/<timestamp>/` — backup archives and manifest
- `access.token` — the standalone recovery panel's access token (only if not set via `.env`)
- `runtime.json` — PHP CLI path, recovery panel filename, and other runtime settings

## Logging

Important events from updates and scheduled backups are additionally written to Contao's system log (System → System log, action `VTINNOVATIONS_GUARDIAN`). Security-sensitive values (licence keys, signatures, tokens, nonces) are never written there.

## Deployment

Standard Composer-based deployment of a Contao bundle (see [Installation](#installation)). The standalone recovery panel is a separate, explicit opt-in step (see above) and is deliberately not installed automatically.

## Clearing the cache

```bash
vendor/bin/contao-console cache:clear
```

## Tests

The PHPUnit test suite (`phpunit.xml.dist`, suite "Guardian") covers `src/` via `tests/Audit`, `tests/Checker`, `tests/Controller`, `tests/External`, `tests/Security` and `tests/Service`, including structural checks against accidentally logging sensitive data and against a single-switch-unlocks-everything flaw in licence verification. Test and configuration files are excluded from the distributed Composer package via `.gitattributes` (`export-ignore`).

```bash
vendor/bin/phpunit
```

## Troubleshooting

- **Update/restore won't start, worker error:** check the PHP CLI path under Settings → PHP CLI settings and set it manually if needed (e.g. `/opt/plesk/php/8.4/bin/php` on Plesk).
- **Recovery e-mail fails, update won't start:** fix the mail configuration under Settings → Recovery e-mail, or deselect "Send recovery URLs by e-mail" in the update dialog for that run.
- **Database backup is skipped:** `DATABASE_URL` is missing from `.env`/`.env.local` — the pre-update analysis flags this as a warning.
- **A job looks stuck:** try "Clean up stale job" first (it automatically detects a crashed/hung worker); only use "Force abort" when you're sure the worker genuinely stopped responding — it does not necessarily terminate the underlying process.
- **Standalone recovery panel unreachable:** check the deployment flag and licence entitlement (see [Standalone recovery panel](#standalone-recovery-panel-pro)); clear the cache after changes so the next boot redeploys the panel.

## Known limitations

- Restore/rollback is sequential and best-effort, not transactional — a failed step does not automatically revert already-completed steps.
- Rollback after a failed update is a manual step (one-click button), not an automatic action.
- "Conservative" update mode is not a true patch-only mode in the semantic sense (`composer update --prefer-stable` within existing constraints, not restricted to patch versions).
- Maintenance mode is only activated automatically during a restore when `vendor/` is among the components being restored.
- Scheduled-backup retention only cleans up backups of the same schedule type; manual backups and pre-update/pre-restore snapshots must be deleted manually if needed.
- "Force abort" marks a job as cancelled but does not necessarily terminate its background process.
- The standalone recovery panel cannot restore `contao/templates/` separately from `templates/`; the regular in-app restore supports both separately.
- Registration, job and replay storage are not designed for multi-node deployments without a shared `var/` directory.

## Licence

LGPL-3.0-or-later · © V&T Innovations

---

*[🇩🇪 Deutsche Version: README.md](README.md)*
