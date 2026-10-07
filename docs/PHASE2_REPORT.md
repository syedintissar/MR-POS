# Phase 2 Report: Foundation

**Date:** 2026-10-07 · **Status:** complete and validated · **Waiting for:** owner approval before Phase 3

---

## 1. What was implemented

**Environment (your decisions Q1, Q2, Q4, Q5, Q8)**
- Everything was backed up before any change (all databases, plus the PHP, MariaDB and Apache config files).
- **MariaDB 11.4.13 LTS** installed next to the old 10.4 (port 3308, this PC only). The four other projects' databases were copied into 11.4 and compared table by table: identical (305 tables, 53,872 rows). The old server was not touched.
- PHP `intl` and `sodium` enabled and verified as loaded (ICU 77.1; formats `Rs 1,234.50`).
- `git init`, initial commit of the Phase 1 documents, then the Phase 2 work.

**Application (Laravel 13.35, PHP 8.5, Blade + Tailwind + Alpine, all bundled offline)**
- **Login and security:** username login, passwords hashed, 5-failure lockout (60 s) plus a per-computer limit, failed logins audited, disabled accounts refused and signed out on their next click, CSRF on every form, forced password change for new/reset users, password re-entry (valid 10 minutes) before sensitive actions, optional allowed-computers list that cannot lock you out.
- **Users, roles, permissions:** 91 permissions in 4 built-in roles (Admin, Manager, Cashier, Technician) exactly as in RBAC.md, custom roles, a tick-box permission screen. **Managers and Admins see cost and profit; Cashiers and Technicians cannot** (enforced on the server and checked by tests). Safeguards: you cannot disable yourself, one active Admin is always kept, only an Admin can change an Admin.
- **Settings screens:** shop details and logo; currency (PKR / Rs.); **sales tax (off, switch on later with rate, label and inclusive/exclusive; no code change)**; sales rules; inventory; **printing (printer name/model, 58/72/80 mm paper, margins, font size, copies, auto-print on sale / job slip / payment, cash-drawer flag)**; receipt text; security; editable document-number prefixes.
- **Audit trail:** append-only (the database itself refuses UPDATE and DELETE), each entry chained to the previous one by a SHA-256 hash so tampering is detectable, passwords masked, and a daily JSON-lines copy outside the database that a restore cannot erase. Viewer with filters and an "Verify integrity" button.
- **Document numbering:** gap-free numbers (a failed posting gives its number back), custom prefixes, yearly restart option.
- **Backup and restore (reported clearly, never silent):** full archive of database + uploaded files with checksums; optional AES-256; daily / weekly / monthly / manual / safety tiers with retention; quick check after every backup and a weekly **restore test** into a scratch database; second copies to a USB drive or another PC with retry when it is unplugged; catch-up if the PC was off at 21:00; failures recorded, audited and shown on the dashboard, in the menu and on the Backups page; **safe command-line restore** (verify, safety copy first, maintenance mode, automatic rollback). Cloud destinations plug into the same engine in Phase 9.
- **Operations tools:** `pos:install`, `pos:doctor` and a dashboard "System checks" card (shows what the *web server's* PHP sees), Windows scripts for the MariaDB service, the scheduled task, the firewall and the Apache virtual host.

## 2. Files created / modified

149 new or changed files in the project (about 6,200 lines of application code and 2,800 lines of tests):

| Area | Files | Highlights |
|---|---|---|
| `app/Services` | 14 | `SettingsService`, `AuditLogger`, `DocumentNumberService`, `SystemCheck`, and `Backup/` (`BackupService`, `DatabaseDumper`, `BackupVerifier`, `RestoreService`, `RetentionService`, `TransferService`, `BackupSchedule`, `BackupHealth`, `ToolLocator`) |
| `app/Http` | 13 | 10 controllers (Auth, Dashboard, Users, Roles, Settings, Backups, Audit), 3 middleware (active user, forced password change, allowed computers) |
| `app/Console/Commands` | 8 | `pos:install`, `pos:doctor`, `pos:backup:run / list / verify / restore / retry-transfers`, `pos:audit:verify` |
| `app/Models`, `app/Support`, `app/Providers`, `app/Exceptions` | 12 | 8 models; permission and settings catalogues |
| `database/` | 12 | 8 migrations, 3 seeders, user factory |
| `resources/` | 20 | 17 Blade views and components, Tailwind CSS, Alpine (toasts, confirmation dialogs, double-submit protection) |
| `routes/`, `bootstrap/`, `config/` | 17 | routes, scheduler, `config/mrpos.php`, timezone and filesystem fixes |
| `deploy/windows/` | 4 | `install-mariadb-service.ps1`, `install-scheduler.ps1`, `setup-firewall.ps1`, `httpd-vhosts.mrpos.conf` |
| `tests/` | 17 | see §6 |
| `docs/` | 9 | new: `ENVIRONMENT_CHANGES.md`, this report. Updated: `ARCHITECTURE`, `BACKUP_RESTORE`, `DATABASE`, `DESIGN_REVIEW`, `INSTALLATION`, `ROADMAP`, `USER_GUIDE`, `README` |

Outside the project (full list and undo steps in [ENVIRONMENT_CHANGES.md](ENVIRONMENT_CHANGES.md)): `C:\xampp\php\php.ini` (two lines), `C:\mariadb-11.4\` (new), `D:\_env_backups\` (backups, downloads, credentials, archived drill backups).

## 3. Database changes

MariaDB 11.4, schema `mrpos`, 21 tables, all InnoDB, utf8mb4. No float columns.

| Migration | Tables |
|---|---|
| `0001_01_01_000000_create_users_table` | `users` (username, active flag, must-change-password, last login), `password_reset_tokens`, `sessions` |
| `0001_01_01_000001_create_cache_table` | `cache`, `cache_locks` |
| `0001_01_01_000002_create_jobs_table` | `jobs`, `job_batches`, `failed_jobs` |
| `2026_10_06_100000_create_settings_table` | `settings` |
| `2026_10_06_100100_create_document_sequences_table` | `document_sequences` |
| `2026_10_06_100200_create_audit_logs_table` | `audit_logs` + triggers `audit_logs_no_update` and `audit_logs_no_delete` |
| `2026_10_06_100300_create_backup_tables` | `backups`, `backup_destinations`, `backup_transfers`, `backup_restores` |
| `2026_10_07_043737_create_permission_tables` | `permissions`, `roles`, `model_has_permissions`, `model_has_roles`, `role_has_permissions` |

Reference data loaded by the seeders: 91 permissions, 4 roles, 12 document sequences. No users and no default password: the first Admin is created by `php artisan pos:install`.

## 4. Commands you need to run

On the Server PC, in this order. Items 1 to 4 need an **Administrator** PowerShell (right-click, Run as administrator).

```powershell
cd D:\MR-POS
# 1. Start MariaDB 11.4 automatically with Windows (it currently dies when the PC restarts)
powershell -ExecutionPolicy Bypass -File .\deploy\windows\install-mariadb-service.ps1
# 2. Run the scheduler every minute, logged in or not (needed for automatic backups)
powershell -ExecutionPolicy Bypass -File .\deploy\windows\install-scheduler.ps1 -RunAsSystem
# 3. (When you want other computers to connect) allow the LAN to reach the website
powershell -ExecutionPolicy Bypass -File .\deploy\windows\setup-firewall.ps1
```
4. Restart **Apache** in the XAMPP Control Panel (so its PHP loads `intl`). To use the LAN address, first add `deploy\windows\httpd-vhosts.mrpos.conf` to Apache (see INSTALLATION.md §5).

Then, in a normal terminal:
```powershell
cd D:\MR-POS
php artisan pos:install     # asks for the administrator's name, username and password (you choose)
php artisan pos:doctor      # every line should say OK
```
Open `http://localhost` (or `http://<server-ip>`), log in, then **Backups → Backup Now → Test restore**, and add a USB drive or second PC as the second copy.

To look at it before Apache is configured: `php artisan serve --host=127.0.0.1 --port=8000`, then open `http://127.0.0.1:8000`.

## 5. Manual setup needed from you

| What | Why |
|---|---|
| Run the three Administrator scripts above and restart Apache | I could not (this session is not elevated) and Apache also serves your other projects |
| Choose the administrator's username and password (`pos:install`) | There is no default password, by design |
| Give the Server PC a fixed IP address (router reservation or Windows) | Other computers need a stable address |
| Plug in a USB drive or point to a second PC, then add it under Backups | A backup only on this PC does not survive a disk failure or theft |
| Decide what to do with the old MariaDB 10.4 (INSTALLATION.md §10) | It is end-of-life, has no root password and listens on the whole network |
| Tell me when you want the real printer added | Model still unknown; settings are ready, receipts arrive in Phase 5 |

## 6. Tests and checks performed

**Automated: 206 tests, 1,002 assertions, all passing** (about 3 to 4 minutes) (PHPUnit against a real MariaDB 11.4 schema, never SQLite). Code style (Laravel Pint) passes.

| Area | What is proven |
|---|---|
| Login | valid / wrong / unknown / disabled user, same message for unknown and wrong (no username leak), lockout after 5 failures, signed out when disabled, logout, hashed passwords, session regenerated |
| Passwords | forced change blocks everything else, rules enforced, other devices signed out, password re-entry expires after 10 minutes |
| Permissions | every default role against every protected route, exact permission lists per role, **cashier and technician have no cost/profit permission, manager and admin do**, an admin's role changes survive re-seeding |
| Users and roles | creation, uniqueness, role changes audited with old/new values, no self-lockout, one Admin always kept (race-safe), only Admins change Admins, built-in roles protected |
| Settings | owner defaults (tax off, PKR, 80 mm), tax switch-on without code, validation, printer settings, audit of every change, allowed-computers list cannot lock you out, logo is re-encoded and a disguised script is refused |
| Audit | database refuses edit and delete, hash chain detects forged rows, passwords masked, daily copy written, filters, Admin only |
| Numbering | sequential, gap-free after rollback, custom prefix, yearly restart |
| Backup | archive contents, manifest, checksum, damaged / edited / truncated / incomplete archives detected, deep restore test (and row-count mismatch caught), encryption, failures recorded and shown, never deletes older good backups after a failure, only one backup at a time, tiers and retention, schedule and catch-up, health warnings |
| Second copies | copy verified, unplugged drive waits and retries with backoff, flagged after 24 h, encrypted-only destinations never get a plain backup, corrupt copy rejected |
| Restore | data and files come back, safety copy holds the pre-restore state (restore can be undone), automatic rollback when the import fails midway, refuses corrupt / newer-version / unsafe archives, works onto an empty database (replacement PC), command needs an Admin login and typed confirmation |
| Environment | `intl` loaded, MariaDB 11.4+, PHP and database clocks agree (Asia/Karachi), all tables InnoDB, no float columns, no page loads anything from the internet |

**Checks on the real system (not just tests):** real backup with the read-only backup account; deep verification (21 tables restored into the scratch schema); logging in over HTTP, 12 pages all render, CSRF rejection (419); Backup Now and a password-protected download from the web page; a restore drill on the real database with maintenance mode (marker user and file came back in 3.6 s, audit chain intact); one scheduler run (heartbeat written, daily backup made, weekly and monthly copies promoted, no duplicate on a second call).

**Real bugs these checks found and fixed:** Laravel 13 ignored the time zone setting (PHP and the database were five hours apart); web-triggered backups failed under PHP's built-in server (Windows error 10106; reproduced in a test); restore onto an empty database failed; a "running" backup row in a restored database looked like a failure; two admins could demote each other at the same moment; usernames with dots were refused. Details in [DESIGN_REVIEW.md §5](DESIGN_REVIEW.md).

**Also observed:** the PC restarted during the session and **MariaDB 11.4 died with it** (it is not a service yet). It was restarted by hand, recovered cleanly (all 21 tables checked OK, data intact). This is why step 1 above matters.

## 7. Remaining issues and decisions before Phase 3

| # | Item | Needed from you |
|---|---|---|
| 1 | **Approval of Phase 2** | Yes / changes, so I can start Phase 3 (products, categories, brands, units, suppliers, inventory) |
| 2 | **Old MariaDB 10.4** (no root password, open to the whole network, other projects use it) | Choose A (leave and secure it), B (move those projects to 11.4 after testing them) or C (later: move MR-POS to 3306) |
| 3 | **Product and stock data for go-live** (Q6) | Do you have your product list, prices, stock counts and customer/supplier balances in Excel? Phase 3 includes an import, so a sample file would let me match its columns |
| 4 | **Barcodes** | Do your products already have barcodes (EAN) or should the system print its own labels? What is the label printer? |
| 5 | **Phase 2 commit** | The Phase 2 work is not committed yet. Say the word and I will commit it, or review it first with `git status` and `git diff` |
| 6 | Minor, can wait: printer model (Phase 5), cloud destination for off-site backups (Phase 9), HTTPS on the LAN if you will use Wi-Fi tablets |  |

Known limits (deliberate, tracked): restore from the web page and cloud backup drivers are Phase 9; receipts and the POS screen are Phase 5; the Windows service and scheduled task need Administrator rights (steps above).
