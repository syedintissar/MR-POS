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
