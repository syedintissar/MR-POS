# Installation & Deployment Guide

> **Status: DRAFT (Phase 1).** The steps below reflect the agreed architecture. Exact commands, file names and screenshots are finalized and tested in Phase 10. Items marked *(Phase N)* do not exist yet.

**Target:** one Windows 10/11 PC as the server ("Server PC"), with other PCs and tablets on the same LAN as terminals.

---

## 1. Hardware & network recommendations
- Server PC: Intel i5 or better, 8 GB RAM, **SSD**, a second disk or USB drive for backups, and a **UPS** (protects the database during load-shedding).
- A wired connection for the Server PC. Give it a **static IP**, either in Windows adapter settings or as a DHCP reservation on the router (e.g. `192.168.1.10`).
- A password-protected Wi-Fi (WPA2/WPA3) if tablets are used. Do not put customer guest Wi-Fi on the same network.

## 2. Software stack (option A: XAMPP, the currently installed stack)
| Component | Version | Notes |
|---|---|---|
| XAMPP (Apache + PHP) | PHP 8.3+ (8.5 installed) | `C:\xampp` |
| Database | **MariaDB 11.4 LTS** (recommended) or the XAMPP-bundled MariaDB 10.4 (EOL; dev only) | See DESIGN_REVIEW Q1 |
| Composer | 2.x | installed |
| Node.js | 20+ (24 installed) | Only needed to build assets. Not needed at runtime |
| Git | any | installed |

Option B (Laragon with MySQL 8.4) works the same way; the paths differ.

### 2.1 PHP configuration (`C:\xampp\php\php.ini`)
Enable these extensions (remove the leading `;`): `intl`, `zip`, `gd`, `mbstring`, `bcmath`, `fileinfo`, `openssl`, `pdo_mysql`, `curl`, `sodium`.
Settings: `memory_limit = 512M`, `upload_max_filesize = 64M`, `post_max_size = 64M`, `max_execution_time = 120`, `date.timezone = Asia/Karachi`, `opcache.enable = 1`.

### 2.2 Database
1. Install MariaDB 11.4 LTS as a Windows service. Set a strong root password and leave port 3306.
2. In `my.ini`, under `[mysqld]`: `bind-address = 127.0.0.1`, `character-set-server = utf8mb4`, `collation-server = utf8mb4_unicode_ci`, `innodb_flush_log_at_trx_commit = 1`, `default-time-zone = '+05:00'`.
3. Create the schemas and users (passwords are examples; generate your own):
```sql
CREATE DATABASE mrpos CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mrpos_app'@'localhost' IDENTIFIED BY '<strong-password-1>';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP, REFERENCES, TRIGGER,
      CREATE TEMPORARY TABLES, LOCK TABLES ON mrpos.* TO 'mrpos_app'@'localhost';

-- backup / verify user
CREATE USER 'mrpos_backup'@'localhost' IDENTIFIED BY '<strong-password-2>';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES, EVENT ON mrpos.* TO 'mrpos_backup'@'localhost';
GRANT ALL PRIVILEGES ON mrpos_verify.* TO 'mrpos_backup'@'localhost';
```
The restore uses `mrpos_app`, which can drop and create tables in `mrpos`. A separate test database `mrpos_test` is used by automated tests on development machines only.

## 3. Application installation *(Phase 2+)*
```powershell
cd D:\
git clone <repo> MR-POS          # or copy the release folder
cd D:\MR-POS
composer install --no-dev --optimize-autoloader
copy .env.example .env
php artisan key:generate
# edit .env (section 4)
npm ci; npm run build            # only if public/build is not shipped with the release
php artisan migrate --force
php artisan pos:install          # seeds roles/permissions/settings, creates the admin (prompts for password)
php artisan storage:link         # only for public assets; uploads stay private
php artisan config:cache; php artisan route:cache; php artisan view:cache; php artisan event:cache
```

## 4. `.env` essentials
```
APP_NAME="Mr Printer & Tuner"
APP_ENV=production
APP_DEBUG=false
APP_URL=http://192.168.1.10
APP_TIMEZONE=Asia/Karachi

DB_CONNECTION=mariadb            # or mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=mrpos
DB_USERNAME=mrpos_app
DB_PASSWORD=...

SESSION_DRIVER=database
SESSION_LIFETIME=480
SESSION_SECURE_COOKIE=false      # true only when HTTPS is enabled
QUEUE_CONNECTION=database
CACHE_STORE=file

BACKUP_DB_USERNAME=mrpos_backup
BACKUP_DB_PASSWORD=...
BACKUP_MYSQLDUMP_PATH="C:\Program Files\MariaDB 11.4\bin\mysqldump.exe"
BACKUP_MYSQL_PATH="C:\Program Files\MariaDB 11.4\bin\mysql.exe"
```

## 5. Apache virtual host & LAN access
`C:\xampp\apache\conf\extra\httpd-vhosts.conf`:
```apache
<VirtualHost *:80>
    ServerName mrpos.local
    ServerAlias 192.168.1.10
    DocumentRoot "D:/MR-POS/public"
    <Directory "D:/MR-POS/public">
        AllowOverride All
        Require all granted
    </Directory>
    <Directory "D:/MR-POS/storage">
        Require all denied
    </Directory>
</VirtualHost>
```
- In XAMPP Control Panel → Config, install **Apache** and the **database** as services (the "Svc" checkboxes), so they start on boot.
- Optional friendly name: on each terminal, add `192.168.1.10  mrpos.local` to `C:\Windows\System32\drivers\etc\hosts`.

### 5.1 Windows Firewall (run PowerShell as Administrator on the Server PC)
```powershell
New-NetFirewallRule -DisplayName "MR-POS HTTP (LAN)" -Direction Inbound -Protocol TCP -LocalPort 80 `
  -Profile Private -RemoteAddress LocalSubnet -Action Allow
# Make sure the network is set to Private:
Get-NetConnectionProfile
Set-NetConnectionProfile -InterfaceAlias "Ethernet" -NetworkCategory Private
```
**Do not** open port 3306.

### 5.2 Optional HTTPS on the LAN
Generate a certificate with `mkcert` for `mrpos.local` and `192.168.1.10`, configure Apache SSL, install the mkcert root CA on each terminal, set `APP_URL=https://…` and `SESSION_SECURE_COOKIE=true`, and add a firewall rule for 443.

## 6. Scheduler & queue (backups depend on this) *(Phase 2/9)*
Create a Task Scheduler task (an XML template will be provided in `deploy/windows/MRPOS-Scheduler.xml`):
- Trigger: **At startup**, repeat **every 1 minute** indefinitely.
- Action: `C:\xampp\php\php.exe` with arguments `D:\MR-POS\artisan schedule:run`, start in `D:\MR-POS`.
- "Run whether user is logged on or not", "Run with highest privileges" off, run as the same account as the Apache service.
- Verify: Admin → Backups shows "Scheduler heartbeat: OK".

## 7. Terminal setup (each counter PC)
- Chrome or Edge, with a bookmark or shortcut to `http://192.168.1.10/pos`.
- Install the thermal printer driver, set paper to 80 mm, and enable "open cash drawer" in the driver if available.
- Optional one-click printing: a shortcut such as `msedge.exe --kiosk-printing http://192.168.1.10/pos` prints to the **default** printer without a dialog. Set the thermal printer as the Windows default on that terminal.
- Set browser zoom so the POS fits the screen (100% at 1366×768 or larger).

## 8. Backups *(Phase 9)*
See [BACKUP_RESTORE.md](BACKUP_RESTORE.md). At install:
1. Admin → Backups → Settings: confirm the time (21:00), retention, and the backup folder (second disk if available).
2. Add a `local_path` destination (USB drive or `\\OTHER-PC\MRPOS-Backups`) → Test.
3. Optional cloud destination (encryption required).
4. Click **Backup Now** → wait for success → **Verify (deep)**.
5. Print the Recovery Kit and store it off-site.

## 9. Restore
Use the web UI (Admin → Backups → Restore) or, if the app cannot start:
```powershell
cd D:\MR-POS
php artisan pos:backup:restore "F:\MRPOS-Backups\mrpos_2026-10-06_2100_daily.zip"
```
Full procedure: [BACKUP_RESTORE.md §12](BACKUP_RESTORE.md#12-restore-procedure).

## 10. Updating the application *(Phase 10)*
`php artisan pos:upgrade` puts the app in maintenance mode, takes a **safety backup**, pulls or copies the new release, runs `composer install --no-dev`, migrates, clears and rebuilds caches, and brings the app back up.

## 11. Troubleshooting (to be expanded)
| Symptom | Check |
|---|---|
| Terminals can't reach the app | Server IP unchanged? Firewall rule Private + LocalSubnet? Network profile Private? Apache service running? |
| "Backup overdue" warning | Task Scheduler task enabled? Heartbeat age? `storage/logs/laravel.log` |
| Slow POS search | Run `php artisan optimize`; check the disk is an SSD and antivirus excludes `D:\MR-POS` and the DB data folder |
