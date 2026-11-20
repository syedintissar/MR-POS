# Installing MR-POS on a Fresh Laptop

For a Windows 10 or 11 laptop with **nothing installed**. At the end the program is in `C:\MR-POS`, backups go to `D:\MRPOS-Backups`, everything starts by itself when the laptop is switched on, and the POS opens in the browser at `http://mr-pos.local`.

- The **internet is needed only during this installation**. Afterwards the shop works with no internet.
- Almost every step is a command. Open the Start menu, type `cmd`, right-click **Command Prompt** and choose **Run as administrator**. Use that one window for the whole guide.
- Copy one block at a time, paste it, press Enter, and wait until it finishes.
- You choose **three passwords** along the way: the database root password, the application's database password and the backup account's password. Use letters and digits only (no `&`, `%`, `"` or spaces). Write them down and keep them away from the laptop.
- The same PHP, Apache and MariaDB versions run on the developer's PC. The steps were put together from that working setup; the full sequence has not been run end to end on a blank laptop, so do it once before the client is waiting and tell the developer if a step differs.

Time: about 45 minutes, most of it downloads.

---

## 1. Install Git, Node.js, XAMPP and the Visual C++ runtime

```cmd
winget install --id Git.Git -e --accept-source-agreements --accept-package-agreements
winget install --id OpenJS.NodeJS.LTS -e --accept-package-agreements
winget install --id Microsoft.VCRedist.2015+.x64 -e --accept-package-agreements
winget install --id ApacheFriends.Xampp.8.2 -e --accept-package-agreements
```

If `winget` is not recognised, open the Microsoft Store, install **App Installer**, and try again.

XAMPP installs to `C:\xampp`. Only its Apache web server is used. Do **not** start XAMPP's own MySQL.

**Now close the Command Prompt and open a new one as administrator**, so that `git` and `npm` are found. Check:

```cmd
git --version
node -v
npm -v
```

## 2. Replace XAMPP's PHP with PHP 8.5

XAMPP brings PHP 8.2; MR-POS needs 8.3 or newer. This swaps in PHP 8.5.10 (the thread-safe build Apache needs) and keeps the old one as `php_old`.

```cmd
ren C:\xampp\php php_old
mkdir C:\xampp\php
curl -L -o "%TEMP%\php85.zip" https://windows.php.net/downloads/releases/archives/php-8.5.10-Win32-vs17-x64.zip
tar -xf "%TEMP%\php85.zip" -C C:\xampp\php
C:\xampp\php\php.exe -v
```

The last line must say `PHP 8.5.10`. If the download fails, use this address in the `curl` line instead: `https://downloads.php.net/~windows/releases/archives/php-8.5.10-Win32-vs17-x64.zip`

## 3. Get the program from GitHub

The repository is private, so a GitHub sign-in window opens: sign in with an account that may read it.

```cmd
git clone https://github.com/syedintissar/MR-POS.git C:\MR-POS
cd /d C:\MR-POS
git branch -a
```

If the list shows `remotes/origin/phase-11-cash-book` and that work has **not** been merged into `main` yet, switch to it (it holds the cash & bank book):

```cmd
git switch phase-11-cash-book
```

From here on, every command is run inside `C:\MR-POS`.

## 4. Prepare PHP and Composer

```cmd
powershell -ExecutionPolicy Bypass -File deploy\windows\configure-php.ps1
curl -L -o C:\xampp\php\composer.phar https://getcomposer.org/download/latest-stable/composer.phar
C:\xampp\php\php.exe -m
```

The list printed by the last command must include `bcmath`, `curl`, `fileinfo`, `gd`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `sodium` and `zip`.

## 5. Install the database (MariaDB 11.4)

```cmd
curl -L -o "%TEMP%\mariadb.zip" https://archive.mariadb.org/mariadb-11.4.13/winx64-packages/mariadb-11.4.13-winx64.zip
tar -xf "%TEMP%\mariadb.zip" -C C:\
ren C:\mariadb-11.4.13-winx64 mariadb-11.4
copy deploy\windows\my.ini C:\mariadb-11.4\my.ini
```

Create the data folder with **your root password** in place of `ROOTPASS`, then make the database a Windows service that starts with the laptop:

```cmd
C:\mariadb-11.4\bin\mariadb-install-db.exe --datadir=C:/mariadb-11.4/data --password=ROOTPASS
powershell -ExecutionPolicy Bypass -File deploy\windows\install-mariadb-service.ps1
```

It must end with `Service MariaDB114 is Running`.

## 6. Create the databases and the two accounts

```cmd
copy deploy\windows\create-database.sql C:\mariadb-11.4\setup.sql
notepad C:\mariadb-11.4\setup.sql
```

In Notepad replace `CHANGE_APP_PASSWORD` (two places) and `CHANGE_BACKUP_PASSWORD` (two places) with your two passwords, save and close. Then run it (it asks for the root password from step 5) and delete the file, because it holds the passwords:

```cmd
C:\mariadb-11.4\bin\mariadb.exe --user=root -p --host=127.0.0.1 --port=3308 < C:\mariadb-11.4\setup.sql
del C:\mariadb-11.4\setup.sql
```

## 7. Install the program

```cmd
C:\xampp\php\php.exe C:\xampp\php\composer.phar install --no-dev --optimize-autoloader
copy .env.example .env
C:\xampp\php\php.exe artisan key:generate
mkdir D:\MRPOS-Backups
notepad .env
```

In Notepad change these four lines (the last one starts with `# ` in the file: remove the `# `), then save and close:

```ini
APP_URL=http://mr-pos.local
DB_PASSWORD=your application password from step 6
BACKUP_DB_PASSWORD=your backup password from step 6
BACKUP_ROOT=D:\MRPOS-Backups
```

No `D:` drive on the laptop? Use a USB drive's letter instead, or leave `BACKUP_ROOT` as it was (backups then stay in `C:\MR-POS\storage\app\backups`, which does not protect against a disk failure).

Build the pages and create the tables. `pos:install` asks for the name, login name and password of the **first administrator**; there is no default password.

```cmd
npm ci
npm run build
C:\xampp\php\php.exe artisan pos:install
C:\xampp\php\php.exe artisan pos:doctor
C:\xampp\php\php.exe artisan optimize
```

`pos:doctor` must end with `All required checks passed.`

## 8. Web server: address, service, start

```cmd
type deploy\windows\httpd-vhosts.client.conf >> C:\xampp\apache\conf\extra\httpd-vhosts.conf
(echo.& echo 127.0.0.1 mr-pos.local)>> C:\Windows\System32\drivers\etc\hosts
C:\xampp\apache\bin\httpd.exe -t
```

The last line must say `Syntax OK`. Then make Apache a Windows service that starts with the laptop:

```cmd
C:\xampp\apache\bin\httpd.exe -k install
sc config Apache2.4 start= auto
net start Apache2.4
```

If Apache will not start, another program is using port 80 (often Skype or IIS). Find it with `netstat -ano | findstr :80` and stop it.

## 9. Automatic backups (the scheduler)

Runs the automatic daily backup and the nightly checks, whether or not anybody is logged in:

```cmd
powershell -ExecutionPolicy Bypass -File deploy\windows\install-scheduler.ps1 -ProjectPath C:\MR-POS -RunAsSystem
```

## 10. Open the POS automatically when Windows starts

```cmd
(echo [InternetShortcut]& echo URL=http://mr-pos.local/)> "%APPDATA%\Microsoft\Windows\Start Menu\Programs\Startup\MR-POS.url"
```

Run this while logged in as the Windows user who will work on the laptop.

## 11. First look

```cmd
start http://mr-pos.local
C:\xampp\php\php.exe artisan pos:backup:run --tier=manual
C:\xampp\php\php.exe artisan pos:backup:verify --latest
dir D:\MRPOS-Backups\manual
C:\xampp\php\php.exe artisan pos:integrity:check
```

Log in with the administrator from step 7. A backup file must be listed in `D:\MRPOS-Backups\manual`, verification must say `PASSED` and the integrity check must say it passed. Then, in the program:

1. **Settings:** shop name, address, phone, logo, receipt text; test the receipt printer with one sale.
2. **Backups → Backup Now** once from the page too (this proves the web server can write to `D:`), then print the **Recovery Kit** and keep it away from the laptop.
3. **Money → Cash & bank → Accounts:** check the accounts and where each payment method pays into, then enter each account's opening balance.
4. Products, suppliers and customers: see [GO_LIVE.md](GO_LIVE.md).

## 12. Prove that it starts by itself

Restart the laptop. After logging in to Windows, the browser must open the POS login page without anyone starting anything. If you want to check by hand:

```cmd
sc query Apache2.4 | findstr STATE
sc query MariaDB114 | findstr STATE
schtasks /query /tn "MRPOS Scheduler"
```

Both services must say `RUNNING`. Finally switch the Wi-Fi off and open `http://mr-pos.local` again: it must work exactly the same.

---

## Installing an update later

With the shop closed, in an administrator Command Prompt:

```cmd
cd /d C:\MR-POS
C:\xampp\php\php.exe artisan pos:backup:run --tier=manual
git pull
C:\xampp\php\php.exe C:\xampp\php\composer.phar install --no-dev --optimize-autoloader
npm ci
npm run build
C:\xampp\php\php.exe artisan migrate --force
C:\xampp\php\php.exe artisan optimize
C:\xampp\php\php.exe artisan pos:integrity:check
```

## If something goes wrong

| What you see | What to do |
|---|---|
| `winget` is not recognised | Install **App Installer** from the Microsoft Store |
| A download stops half-way (`curl: (18)`), or `tar` says the file is damaged | Run the same `curl` line again with `-C -` added after `curl` (it continues where it stopped). The MariaDB file is about 95 MB |
| `git` or `npm` is not recognised after step 1 | Close the Command Prompt and open a new one as administrator |
| `pos:install` or `pos:doctor` says the database cannot be reached | `sc query MariaDB114` must say RUNNING; check `DB_PASSWORD` in `.env`, then run `C:\xampp\php\php.exe artisan config:clear` |
| The browser says "This site can't be reached" | `net start Apache2.4`; check that `C:\Windows\System32\drivers\etc\hosts` has the line `127.0.0.1 mr-pos.local` |
| The XAMPP welcome page opens instead of the POS | Step 8's first line was not run, or Apache was not restarted: `net stop Apache2.4` then `net start Apache2.4` |
| A page shows "500 Server Error" | `notepad C:\MR-POS\storage\logs\laravel.log` and send the last lines to the developer |
| A red backup warning on the dashboard | Open **Backups**: each warning says what to fix. "Scheduled-task runner is not running" means step 9 was not done |
| After changing `.env` nothing changes | `C:\xampp\php\php.exe artisan optimize` (the settings are cached) |

## What must never be lost

- The file `C:\MR-POS\.env` (it holds the `APP_KEY` and the database passwords). It is not on GitHub and not inside the backups. The Recovery Kit prints what is needed from it.
- The backups in `D:\MRPOS-Backups`. If `C:` and `D:` are two parts of the same disk, a disk failure takes both: copy the newest backup to a USB drive every week.
