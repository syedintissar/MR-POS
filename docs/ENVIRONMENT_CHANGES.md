# Environment Change Log

Every change made to the server PC outside the `D:\MR-POS` project folder is recorded here, with how to undo it.
Machine: DESKTOP-PBAH6IF · Windows 10 Pro · user `SA` (**not an Administrator session**: no Windows services were installed or changed).

## 2026-10-06: Phase 2 preparation

### 0. State found before any change
| Item | Finding |
|---|---|
| XAMPP MariaDB | 10.4.32 on `0.0.0.0:3306`, started as a normal process (`--standalone`), **not** a Windows service. `root` has **no password**. Data in `C:\xampp\mysql\data` |
| Databases on it | `bajk_banking`, `mis_erp`, `nexus`, `updated_dfms` (305 tables, 53,872 rows, about 150 MB) belonging to **other projects**, plus `phpmyadmin`. None belong to MR-POS |
| MySQL 8.0 | A second server, on `127.0.0.1:3307`, data dir `D:/mis-mysql-test/data` (another project's test instance). **Not touched.** |
| Apache | XAMPP Apache on 80/443, normal process, not a service |
| PHP | 8.5.10, `php.ini` at `C:\xampp\php\php.ini`. `intl` and `sodium` were disabled |

### 1. Backup taken before any change
Location: `D:\_env_backups\2026-10-06_pre-phase2\`
- `all-databases-mariadb10.4.sql`: every database including system tables (112 MB, ends with "Dump completed")
- One dump each for `bajk_banking`, `mis_erp`, `nexus`, `updated_dfms`, with `SHA256SUMS.txt`
- Original `php.ini`, `my.ini`, `httpd.conf`, `httpd-vhosts.conf` (`*.orig`)

The 10.4 data folder itself was **not modified, moved or deleted**. It is still in use by the old server.

### 2. PHP: `intl` and `sodium` enabled
- Edit: `C:\xampp\php\php.ini`, two lines (`;extension=intl` → `extension=intl`, same for `sodium`). The rest of the file is untouched.
- Verified from the CLI: `intl` loaded (ICU 77.1; `NumberFormatter('en_PK')` formats `Rs 1,234.50`). `sodium` loaded.
- **Apache's PHP needs an Apache restart to pick it up.** I did not restart Apache because it also serves your other projects. Restart it from the XAMPP Control Panel when convenient.
- Undo: restore `php.ini.orig` from the backup folder.

### 3. MariaDB 11.4.13 LTS installed (portable, side by side)
- Source: `https://downloads.mariadb.org/rest-api/mariadb/11.4.13/mariadb-11.4.13-winx64.zip`. SHA-256 `d62986d4…257e2` **verified against the published checksum**.
- Installed to `C:\mariadb-11.4\` (data in `C:\mariadb-11.4\data`, config `C:\mariadb-11.4\my.ini`).
- Port **3308**, bound to **127.0.0.1 only**, utf8mb4 / utf8mb4_unicode_ci, time zone +05:00, strict SQL mode, `innodb_flush_log_at_trx_commit=1`.
- Started with `C:\mariadb-11.4\bin\mariadbd.exe --defaults-file=C:\mariadb-11.4\my.ini --standalone`. Because this session is not elevated it is not a Windows service yet; see "Manual steps" in the Phase 2 report.
- `root` password is random, stored in `C:\mariadb-11.4\.root-pw.txt` (readable only by user `SA`). The default `test` database and its PUBLIC grants were removed.
- Created schemas `mrpos`, `mrpos_test`, `mrpos_verify`, and users `mrpos_app` (full rights on `mrpos`, `mrpos_test`) and `mrpos_backup` (read on `mrpos`, full on `mrpos_verify`). Passwords are random, stored in `D:\_env_backups\mrpos-db-credentials.txt` and in the project's `.env`.
- An empty folder `C:\mariadb-extract` is left from unpacking. It is safe to delete.
- Undo: stop the server, delete `C:\mariadb-11.4`.

### 4. Compatibility test: other projects' databases on 11.4
The four other databases were imported from the backup dumps into the 11.4 server and compared to the live 10.4 server:
- 305 of 305 tables imported with **no errors**.
- Exact `COUNT(*)` per table is **identical** on both servers (53,872 rows). Views, routines and triggers: none on either.
- This is a data-level check only. It does **not** prove those applications' code runs correctly on 11.4. Test each one before switching them over.

### 5. Why the 10.4 server was not replaced
MR-POS now runs on MariaDB 11.4, as decided. But the 10.4 server also hosts four other projects' databases that were not part of this decision, and it cannot be replaced safely without your say-so. The cutover choices are in the Phase 2 report. Until you decide, **both servers run side by side** (10.4 on 3306, 11.4 on 3308).

### 6. Security observation (not changed)
XAMPP MariaDB 10.4 listens on all interfaces (`0.0.0.0:3306`) with a **blank root password**. Anyone on the shop LAN can connect with full rights to the other projects' data. Fix: set a root password and `bind-address=127.0.0.1` in `C:\xampp\mysql\bin\my.ini`, then update the other projects' `.env` files. I did not change it because it could break those projects.

### 7. Project tooling
- `D:\_laravel_tmp` is a temporary Laravel scaffold folder, removed after its files are moved into `D:\MR-POS`.
- Downloaded installer kept at `D:\_env_backups\downloads\mariadb-11.4.13-winx64.zip`.

---

## 2026-10-06 / 07: Phase 2 additions

### 8. Database accounts (added while building the tests)
- `mrpos_app` also has ALL privileges on the scratch schema `mrpos_verify` (needed for "Test restore").
- `mrpos_test` schema was created for the automated tests. `mrpos_app` has ALL on it and `mrpos_backup` has read access. **Do not create `mrpos_test` on the live shop server.**
- Credentials: `D:\_env_backups\mrpos-db-credentials.txt` (app and backup accounts) and `C:\mariadb-11.4\.root-pw.txt` (root). Both are readable only by the Windows user `SA`. The app and backup passwords are also in the project's `.env`.

### 9. Project-level settings that differ from the Laravel defaults (all inside `D:\MR-POS`)
| Change | Why |
|---|---|
| `config/app.php`: timezone now follows `APP_TIMEZONE` (default Asia/Karachi). Laravel 13 ships it hard-coded to UTC | PHP was in UTC while MariaDB used +05:00, so stored times and the "21:00" backup would have disagreed by five hours |
| `config/database.php`: `timezone` = `DB_TIMEZONE` (default +05:00) on the MariaDB/MySQL connections | PHP and the database always share one clock |
| `config/filesystems.php`: the `local` disk no longer serves files over a `storage/{path}` route | Uploaded files and logos live in that disk; nothing should be reachable by URL |
| `composer.json`: `optimize-autoloader` is `false` | The optimized scan took many minutes while antivirus inspected every file. Production installs pass `--optimize-autoloader` |
| `config/auth.php`: password confirmation lasts 10 minutes (`AUTH_PASSWORD_TIMEOUT=600`) | Sensitive actions ask for the password again |

### 10. Real-database drills, then reset
The real `mrpos` database was used for the end-to-end checks (install, real backups with the `mrpos_backup` account, deep verification, web Backup Now and download, a restore drill, one scheduler run) with a throw-away administrator. Afterwards it was reset (`migrate:fresh` + seed): **0 users, 0 audit rows, 0 backups**, ready for the real administrator. The backups made during the drills were **moved, not deleted**, to `D:\_env_backups\mrpos-smoke-test` (14 files). The PHP development server used for the check was stopped.

### 11. What was deliberately NOT done (needs you, or needs Administrator rights)
| Not done | Why | How |
|---|---|---|
| MariaDB 11.4 is **not** a Windows service | This session is not elevated | `deploy\windows\install-mariadb-service.ps1` as Administrator. Until then it only runs while the hand-started process is alive |
| **No scheduled task** registered | Persistent background job on your PC; and the best mode needs Administrator | `deploy\windows\install-scheduler.ps1` (add `-RunAsSystem` as Administrator) |
| **Apache not restarted**, so the web server's PHP does not have `intl` yet | It also serves your other projects | Restart Apache in the XAMPP Control Panel, then check the dashboard "System checks" card |
| Apache virtual host, firewall rule, LAN access | Not requested yet / Administrator | `deploy\windows\httpd-vhosts.mrpos.conf`, `deploy\windows\setup-firewall.ps1` |
| Old MariaDB 10.4 left running, unsecured (no root password, all interfaces) | Other projects depend on it | Options A/B/C in INSTALLATION.md §10 |

---

## 2026-10-07: Phase 3 additions

| Change | Detail |
|---|---|
| Project packages | `openspout/openspout` 5.12 (reads/writes Excel files) and `picqer/php-barcode-generator` 3.3 (Code 128 barcodes) added with Composer. Both work offline. No change outside `D:\MR-POS` |
| Real database `mrpos` | Backed up (`mrpos_2026-10-07_121950_manual.zip`), then migrated with the four Phase 3 migrations. After the drills it was reset to a clean install (0 users, 0 products). The drill backups were **moved, not deleted**, to `D:\_env_backups\mrpos-phase3-smoke` (6 files) |
| Temporary files | `D:\_env_backups\2026-10-06_pre-phase2` and `mrpos-smoke-test` from Phase 2 are untouched. A temporary PHP development server on port 8099 was used for the HTTP checks and stopped afterwards |
| MariaDB 11.4 | **Still a hand-started process** (not a Windows service). During the session the PC restarted once and MariaDB had to be started again by hand; it recovered cleanly. Run `deploy\windows\install-mariadb-service.ps1` as Administrator to make this permanent |
| Not done | Windows service, scheduled task, Apache restart, firewall and vhost: all still need an Administrator (see the Phase 2 section above) |

---

## 2026-10-07: Phase 4 additions

| Change | Detail |
|---|---|
| Project packages | None added. No change outside `D:\MR-POS` apart from the items below |
| Real database `mrpos` | **Backed up first** (`mrpos_2026-10-07_151617_daily.zip`, deep-verified), then migrated with the three Phase 4 migrations (`payment_methods`, `supplier_ledger_entries`, `purchase tables`). After the drills it was reset to a clean install (0 users, 0 products, 0 suppliers, 7 payment methods). The drill backups (10 files: daily/weekly/monthly of the clean state, the manual backup that held the drill data, and the safety backup taken by the restore drill) were **moved, not deleted**, to `D:\_env_backups\mrpos-phase4-smoke` |
| Front-end assets | `npm run build` was run so the new screens' styles are in `public/build` (git-ignored). **Run it again after copying a new release** (INSTALLATION §11) |
| Temporary processes | A PHP development server on port 8099 was used for the HTTP drill and **stopped** afterwards. The other PHP servers on this PC (ports 8000 and 3306/3307 projects) were not touched |
| Restore drill | Done on the real `mrpos` database through the restore service: data entered after the backup disappeared, everything before it came back, the append-only protections (supplier ledger, stock ledger, audit log) still rejected edits, integrity check and audit chain passed. The interactive password prompt of `pos:backup:restore` cannot be scripted on Windows (it reads the real console), so the drill called the same service the command calls once the administrator is authenticated; the prompt path itself is covered by the Phase 2 tests |
| Throwaway accounts | Users `drill` and `drillcash` existed only in the drill and were removed with the reset |
| Not done | Windows service, scheduled task, Apache restart, firewall and vhost: still need an Administrator (see the Phase 2 section above) |

---

## 2026-10-07: Phase 5 additions

| Change | Detail |
|---|---|
| Project packages | `dompdf/dompdf` 3.1 added with Composer (A4 invoice as a PDF; works offline, nothing is fetched from the internet when a PDF is made). No change outside `D:\MR-POS` |
| Real database `mrpos` | **Backed up first** (`mrpos_2026-10-07_162405_manual.zip`), then migrated with the two Phase 5 migrations (`customers`, `sales tables`). After the drills it was reset to a clean install (0 users, 0 products, 0 sales, 7 payment methods, the one walk-in customer). The drill backups were **moved, not deleted**, to `D:\_env_backups\mrpos-phase5-smoke` |
| Front-end assets | `npm run build` was run (the till is a new script); `public/build` is git-ignored: **run it again after copying a new release** |
| Tools used for the browser drill | The existing Microsoft Edge (headless, remote-debugging port 9333 on 127.0.0.1 only, throw-away profile in the Windows temp folder, deleted by Windows cleanup) and Node.js 24 (already installed). A temporary PHP server on port 8099 served the drill and was **stopped**; a second one on port 8123 (to view a PDF) was stopped too. `pdfjs-dist` was installed **only in the session's scratch folder** (outside the project) to look at the PDF. The other PHP servers on this PC (port 8000) and the other MariaDB / MySQL servers were not touched |
| A mistake, and its repair | While checking how a setting is stored I ran a one-line command that used a test factory against the **real** database and created one stray user. It was noticed immediately and removed (users 0, audit entries 0, audit chain verified). No data was affected |
| Throwaway accounts | Users `drill`, `drillboss`, `drillcash` and a few products existed only in the drills and were removed with the reset |
| Not done | Windows service, scheduled task, Apache restart, firewall and vhost: still need an Administrator (see the Phase 2 section above) |

---

## 2026-10-08: Phase 6 additions

| Change | Detail |
|---|---|
| Project packages | None added. No change outside `D:\MR-POS` apart from the items below |
| Real database `mrpos` | **Backed up first** (`mrpos_2026-10-08_123743_manual.zip`), then migrated with the two Phase 6 migrations (`customer_ledger_entries`, `sale_id` on `customer_payments`). After the drills it was reset to a clean install (0 users, 0 products, 0 sales, 7 payment methods, the one walk-in customer). The drill backups (a manual backup that held the drill data, the safety backup taken by the restore drill, and the pre-migration backup, with their `.sha256` files) were **moved, not deleted**, to `D:\_env_backups\mrpos-phase6-smoke` |
| Front-end assets | `npm run build` was run (the till has the customer features); `public/build` is git-ignored: **run it again after copying a new release** |
| Temporary processes | A PHP development server on port 8099 (started by me) served the browser drill and was **stopped** afterwards; headless Edge used a throw-away profile and remote-debugging port 9333 on 127.0.0.1 only. The PHP server on port 8000 and the other MariaDB / MySQL servers on this PC were not touched |
| Restore drill | Done on the real `mrpos` database through the restore service: the backup passed deep verification (42 tables imported into a scratch database); money entered after the backup disappeared, everything before it came back (balances, ledger, advance, triggers), the customer-ledger protections (no edit, no delete, one side only, known types only) and the audit and stock-ledger protections still refused hand edits, and the integrity check and audit chain passed |
| Throwaway accounts | Users `drill`, `drillboss`, `drillcash`, four products and five customers existed only in the drills and were removed with the reset |
| **`.env` was overwritten while the tests were running, and repaired** | At about 13:08-13:09 on 2026-10-08 the project's `.env` was replaced by a byte-for-byte copy of `.env.example`, which emptied `APP_KEY`, `DB_PASSWORD` and `BACKUP_DB_PASSWORD`; the application and the test suite then failed with "Access denied for user 'mrpos_app' (using password: NO)" (220 tests of a full run). Nothing that I ran does this (no code or test writes `.env`). The traces match the steps of INSTALLATION.md §5 carried out by hand in `D:\MR-POS` while my full test run was going: a Windows command prompt was opened at 13:06:57, Composer's `vendor/composer/installed.php` was rewritten at 13:07:09 (`composer install`), the application log shows web requests in `production` mode with the blank configuration at 13:08-13:12, the front-end files were rebuilt at 13:09:22 and again at 13:17:57 (`npm run build`) and `.env` was re-saved at 13:09:44 and 13:22:15 (the second time only the line endings changed). **If that was you: no harm was done, but please do not install or edit in `D:\MR-POS` while I am running tests (they use the same folder and the same database server), or tell me and I will wait.** `pos:install` has **not** been run: the real database still has no users. Repair: `.env` was rebuilt from `.env.example` with the two passwords from `D:\_env_backups\mrpos-db-credentials.txt`, `APP_ENV=local`, `APP_DEBUG=true`, `APP_URL=http://localhost` (the values it had), and a **new `APP_KEY`** (`php artisan key:generate`; no saved copy of the old key could be found; this only logs everybody out, and the real database is a clean install with no encrypted data). A copy of the repaired file is kept at `D:\_env_backups\mrpos.env.2026-10-08` (same sensitivity as the credentials file next to it). `pos:doctor` and `pos:integrity:check` pass again. For a production install set `APP_ENV=production`, `APP_DEBUG=false` and the shop's real address in `APP_URL` (INSTALLATION.md) |
| Not done | Windows service, scheduled task, Apache restart, firewall and vhost: still need an Administrator (see the Phase 2 section above) |