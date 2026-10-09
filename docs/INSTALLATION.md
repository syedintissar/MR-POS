# Installation & Deployment Guide

> **Status: tested in Phase 2** on the shop PC (Windows 10 Pro, XAMPP PHP 8.5, MariaDB 11.4.13). Printer and thermal-receipt specifics are finalized in Phase 5. Every change made to this PC so far is listed in [ENVIRONMENT_CHANGES.md](ENVIRONMENT_CHANGES.md).

**Target:** one Windows 10/11 PC as the server ("Server PC"), with other PCs and tablets on the same LAN as browser terminals. Nothing in normal operation needs the internet.

---

## 1. Hardware & network recommendations
- Server PC: Intel i5 or better, 8 GB RAM, **SSD**, a second disk or USB drive for backups, and a **UPS** (protects the database during load-shedding).
- A wired connection for the Server PC and a **fixed IP address** (Windows adapter settings, or a DHCP reservation on the router), for example `192.168.1.10`.
- Password-protected Wi-Fi (WPA2/WPA3) if tablets are used. Keep customer guest Wi-Fi on a different network.

## 2. Software

| Component | Version | Notes |
|---|---|---|
| PHP + Apache | XAMPP, PHP 8.3 or newer (8.5.10 installed) | `C:\xampp` |
| Database | **MariaDB 11.4 LTS** (11.4.13 installed) | Portable build in `C:\mariadb-11.4`, port **3308**, localhost only |
| Composer | 2.x | |
| Node.js | 20+ | Only to build the page styles; not needed while the shop is running |

### 2.1 PHP (`C:\xampp\php\php.ini`)
Required extensions (no leading `;`): `intl`, `zip`, `gd`, `mbstring`, `bcmath`, `fileinfo`, `openssl`, `pdo_mysql`, `curl`. Recommended: `sodium`.
Settings used: `memory_limit = 512M`, `upload_max_filesize = 128M`, `post_max_size = 128M`, `max_execution_time = 600`.

**Apache must be restarted after any `php.ini` change** (XAMPP Control Panel → Apache → Stop, Start). The command line and Apache read the same file but only Apache's running copy needs the restart. To confirm, open the dashboard as an administrator and look at the **System checks** card (it shows what the *web server's* PHP has loaded), or run `php artisan pos:doctor` for the command line.

### 2.2 MariaDB 11.4

The PC already had XAMPP's MariaDB 10.4 (end-of-life) holding other projects' databases, so 11.4 was installed **next to it** instead of replacing it. See §10 for the cutover choice.

Install on a fresh PC (no administrator rights needed for these steps):
1. Download `mariadb-11.4.x-winx64.zip` from mariadb.org and check its SHA-256 against the published value.
2. Unzip into `C:\mariadb-11.4`.
3. Create `C:\mariadb-11.4\my.ini`:
   ```ini
   [mysqld]
   basedir=C:/mariadb-11.4
   datadir=C:/mariadb-11.4/data
   port=3308
   bind-address=127.0.0.1
   character-set-server=utf8mb4
   collation-server=utf8mb4_unicode_ci
   default-time-zone='+05:00'
   innodb_flush_log_at_trx_commit=1
   sql_mode=STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION
   max_allowed_packet=64M
   log_error=C:/mariadb-11.4/data/mariadb-error.log
   [client]
   port=3308
   default-character-set=utf8mb4
   ```
4. Initialise with a root password of your choice and start the server:
   ```powershell
   C:\mariadb-11.4\bin\mariadb-install-db.exe --datadir=C:/mariadb-11.4/data --password=<ROOT-PASSWORD>
   C:\mariadb-11.4\bin\mariadbd.exe --defaults-file=C:\mariadb-11.4\my.ini --standalone
   ```
5. **Make it start with Windows** (needs an Administrator PowerShell): `deploy\windows\install-mariadb-service.ps1`. Until you do this, MariaDB 11.4 only runs while the process started in step 4 is alive, and the POS cannot open after a reboot.

### 2.3 Databases and accounts
Run in the MariaDB client as root (`C:\mariadb-11.4\bin\mariadb.exe --user=root -p --port=3308 --host=127.0.0.1`). Generate your own passwords:
```sql
CREATE DATABASE mrpos        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE mrpos_verify CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;  -- scratch area where "Test restore" proves a backup works
CREATE USER 'mrpos_app'@'localhost'    IDENTIFIED BY '<APP-PASSWORD>';
CREATE USER 'mrpos_app'@'127.0.0.1'    IDENTIFIED BY '<APP-PASSWORD>';
CREATE USER 'mrpos_backup'@'localhost' IDENTIFIED BY '<BACKUP-PASSWORD>';
CREATE USER 'mrpos_backup'@'127.0.0.1' IDENTIFIED BY '<BACKUP-PASSWORD>';

GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,ALTER,INDEX,DROP,REFERENCES,TRIGGER,CREATE TEMPORARY TABLES,LOCK TABLES
      ON mrpos.* TO 'mrpos_app'@'localhost','mrpos_app'@'127.0.0.1';
GRANT ALL PRIVILEGES ON mrpos_verify.* TO 'mrpos_app'@'localhost','mrpos_app'@'127.0.0.1';
GRANT SELECT,SHOW VIEW,TRIGGER,LOCK TABLES,EVENT
      ON mrpos.* TO 'mrpos_backup'@'localhost','mrpos_backup'@'127.0.0.1';   -- read-only: used for the dump
FLUSH PRIVILEGES;
```
Also remove the sample database MariaDB creates: `DROP DATABASE IF EXISTS test;` and revoke the `test` privileges from PUBLIC.

**Development machines only** (to run the automated tests): `CREATE DATABASE mrpos_test ...;` with `GRANT ALL ON mrpos_test.* TO 'mrpos_app'@...` and `GRANT SELECT,SHOW VIEW,TRIGGER,LOCK TABLES,EVENT ON mrpos_test.* TO 'mrpos_backup'@...`. Never create it on the shop's live server.

## 3. Install the application
```powershell
cd D:\MR-POS
composer install --no-dev --optimize-autoloader
copy .env.example .env
php artisan key:generate
# edit .env (section 4)
npm ci
npm run build
php artisan pos:install        # migrates, loads roles/permissions/numbering, asks for the first Admin's password
php artisan pos:doctor         # everything required must say OK
```
`pos:install` can be run again safely. There is **no default password**: the first administrator chooses theirs.

> Development note: `composer.json` sets `optimize-autoloader` to `false` because the optimized scan is very slow while antivirus checks every file. Production installs pass `--optimize-autoloader` as above.

## 4. `.env` essentials (production)
```
APP_NAME="Mr Printer & Tuner"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://192.168.1.10
APP_TIMEZONE=Asia/Karachi

DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3308
DB_DATABASE=mrpos
DB_USERNAME=mrpos_app
DB_PASSWORD=<APP-PASSWORD>
DB_TIMEZONE=+05:00

SESSION_DRIVER=database
SESSION_LIFETIME=480
SESSION_SECURE_COOKIE=false      # true only when HTTPS is enabled
CACHE_STORE=file
QUEUE_CONNECTION=database

BACKUP_DB_USERNAME=mrpos_backup
BACKUP_DB_PASSWORD=<BACKUP-PASSWORD>
# Leave the next two empty to auto-detect MariaDB 11.4, then XAMPP, then Laragon:
BACKUP_MYSQLDUMP_PATH=
BACKUP_MYSQL_PATH=
BACKUP_VERIFY_DATABASE=mrpos_verify
# BACKUP_ROOT=E:\MRPOS-Backups    # a second physical disk is recommended
```
`.env` holds secrets. It is never committed to git and is **not** inside backups: keep a copy of the `APP_KEY` line somewhere safe (the Recovery Kit), because stored backup passwords and destination credentials are encrypted with it.

## 5. Web server and LAN access
1. Copy the contents of `deploy\windows\httpd-vhosts.mrpos.conf` into `C:\xampp\apache\conf\extra\httpd-vhosts.conf`, change `192.168.1.10` to the Server PC's IP, and make sure `mod_rewrite`, `mod_headers` and the `Include conf/extra/httpd-vhosts.conf` line are active in `httpd.conf`.
2. Install Apache as a Windows service so it starts with the PC: XAMPP Control Panel → the **Svc** box next to Apache (administrator).
3. Restart Apache.
4. **Firewall** (Administrator PowerShell): `deploy\windows\setup-firewall.ps1`. It allows port 80 from the local subnet on *Private* networks only, tells you if the network is set to *Public*, and warns if any rule exposes a database port.
5. Optional friendly name: on each terminal add `192.168.1.10  mrpos.local` to `C:\Windows\System32\drivers\etc\hosts`.
6. Open `http://192.168.1.10` from another PC and log in.

Optional HTTPS on the LAN (recommended if Wi-Fi tablets are used): create a certificate with `mkcert`, configure Apache SSL, install the mkcert root certificate on each terminal, set `APP_URL=https://…` and `SESSION_SECURE_COOKIE=true`, and open port 443.

## 6. Scheduler (automatic backups depend on it)
Windows Task Scheduler must run `php artisan schedule:run` every minute. Run `deploy\windows\install-scheduler.ps1`:
- **Administrator PowerShell, with `-RunAsSystem`** (recommended): runs at startup whether or not anyone is logged in.
- Normal PowerShell, no switch: runs as you, only while you are logged in.

Until this is done the dashboard shows a red warning that the scheduled-task runner is not running, because backups would otherwise silently never happen. Two minutes after registering, the warning clears.

## 7. Terminal setup (each counter PC)
- Chrome or Edge with a shortcut to `http://192.168.1.10`.
- Install the thermal printer's Windows driver and set it as the Windows **default printer** on that PC. Paper width, margins, font size, copies and automatic printing are configured in *Settings → Printing*; the receipts themselves arrive in Phase 5.
- Optional one-click printing with no print dialog: start the browser with `--kiosk-printing`, for example `msedge.exe --kiosk-printing http://192.168.1.10`.

## 8. Backups
See [BACKUP_RESTORE.md](BACKUP_RESTORE.md). On a new install:
1. Log in as the administrator → **Backups** → **Backup Now**, then **Test restore** on it (this proves it can be restored).
2. Add a second destination: a USB drive (`F:\MRPOS-Backups`) or another PC's share (`\\COUNTER2\MRPOS-Backups`). Rotate two USB drives, one kept off-site.
3. Check the schedule (default 21:00 daily) and retention (7 daily, 4 weekly, 6 monthly).
4. Optional: turn on AES-256 encryption and write the backup password down away from the shop.
5. Cloud destinations (Google Drive, S3, SFTP) arrive in Phase 9; the system is built to take them.

## 9. Restore
From the server PC, in a terminal in `D:\MR-POS`:
```powershell
php artisan pos:backup:list
php artisan pos:backup:restore --id=<number>          # or:  php artisan pos:backup:restore F:\MRPOS-Backups\manual\mrpos_....zip
```
It asks for an administrator login and a typed confirmation, tests the backup first, makes a safety copy of the current data, and rolls back automatically if anything fails. Full procedure, including a replacement PC: [BACKUP_RESTORE.md §12](BACKUP_RESTORE.md#12-restore-procedure).

## 10. Decision pending: the old MariaDB 10.4 server
XAMPP's MariaDB 10.4 still runs on port 3306 and holds four other projects' databases (`mis_erp`, `nexus`, `updated_dfms`, `bajk_banking`). It is end-of-life, listens on **every network interface** and its `root` user has **no password**. MR-POS does not use it. The other databases were copied into 11.4 and compared table by table with identical results, so they *can* move, but the applications that use them have not been tested on 11.4. Options:

| Option | What it means |
|---|---|
| A. Leave it | Nothing changes for the other projects. Secure it anyway: give `root` a password and set `bind-address=127.0.0.1` in `C:\xampp\mysql\bin\my.ini`, then update the other projects' `.env` files |
| B. Move the other projects to 11.4 | Test each application against 11.4 (port 3308) first, repoint their `.env`, then retire 10.4. A copy of every database is kept in `D:\_env_backups\2026-10-06_pre-phase2` |
| C. Move MR-POS to port 3306 | Only after the old server is retired (B), by changing `port` in `my.ini` and the `.env` files |

## 11. Updating the application
Today: take a backup (**Backup Now**), copy the new release over `D:\MR-POS` (keep `.env` and `storage`), then `composer install --no-dev --optimize-autoloader`, `npm ci && npm run build`, `php artisan migrate --force`, `php artisan optimize:clear`. From Phase 4 on, `migrate` also creates the default payment methods (Cash, Bank Transfer, Card, Easypaisa, JazzCash, Cheque, Customer Credit), so no extra command is needed; `php artisan db:seed --class=PaymentMethodSeeder --force` is safe to run again and only adds missing ones. Phase 5 added the invoice PDF library (`composer install` fetches it) and new screens: run `npm run build` too. Phase 6 adds two migrations (the customer ledger, and the link between a receipt and its sale) and the customer screens: `php artisan migrate --force` and `npm run build` again. Phase 7 adds one migration (service types, jobs, lines, history, payments; it also creates the eleven default service types) and the service screens: the same two commands again. After the update run `php artisan pos:integrity:check`. A one-command `pos:upgrade` that does the safety backup automatically arrives in Phase 10.

## 12. Troubleshooting

| Symptom | Check |
|---|---|
| Other computers cannot open the site | Server IP unchanged? Firewall rule present and the network set to *Private*? Apache running? Try `http://<server-ip>` on the server itself first |
| Page says the database is unreachable | Is MariaDB 11.4 running (port 3308)? `php artisan pos:doctor`. After a reboot it only starts automatically once `install-mariadb-service.ps1` has been run |
| Red "Backup problem" dot / banner | Open **Backups**: each warning says what to fix. "Scheduled-task runner is not running" means §6 has not been done |
| A backup fails with `Can't create TCP/IP socket (10106)` | Fixed in Phase 2 (SystemRoot is passed to the tools). If it ever returns, run `php artisan pos:doctor` and check the antivirus is not blocking `mariadb-dump.exe` |
| Dashboard "System checks" shows a missing PHP extension | Enable it in `php.ini` and **restart Apache** |
| Times are five hours off | `APP_TIMEZONE=Asia/Karachi` and `DB_TIMEZONE=+05:00` in `.env`, then `php artisan config:clear` |
| Slow pages | `php artisan optimize`; exclude `D:\MR-POS` and the MariaDB data folder from antivirus real-time scanning |

## 13. Running the automated tests (developers)
```powershell
cd D:\MR-POS
vendor\bin\phpunit          # about 3 minutes; uses the mrpos_test schema, never the live database
vendor\bin\pint --test      # code style
```
The test suite refuses to run unless the connected database is named `mrpos_test`.
