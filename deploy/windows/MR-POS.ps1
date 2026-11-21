# MR-POS launcher. Double-click the "MR-POS" desktop icon (made by create-desktop-shortcut.ps1) to run this.
#   First run : installs everything that is missing (Composer packages, front-end build, .env, key, database tables).
#   Every run : starts the database if it is not running, starts Apache for this program, opens the browser.
#   Stopping  : close this window, press Q, or press Ctrl+C. Apache is stopped automatically.
# Apache runs from its own small config (storage\app\apache\httpd.conf), so other projects on this PC's XAMPP are never touched.

$ErrorActionPreference = 'Stop'
$Port    = 8088          # change here if 8088 is taken; LAN terminals use http://<this-PC-IP>:8088
$Root    = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$Php     = 'C:\xampp\php\php.exe'
$Httpd   = 'C:\xampp\apache\bin\httpd.exe'
$Maria   = 'C:\mariadb-11.4'
$DbPort  = 3308
$Fwd     = $Root -replace '\\', '/'
$ConfDir = Join-Path $Root 'storage\app\apache'
$Conf    = Join-Path $ConfDir 'httpd.conf'
$PidFile = Join-Path $ConfDir 'httpd.pid'
$Marker  = Join-Path $Root 'storage\app\.launcher-installed'

function Say($m, $c = 'Gray') { Write-Host $m -ForegroundColor $c }
function Fail($m) { Say "`n$m" Red; Read-Host 'Press Enter to close'; exit 1 }
function Listening($p) { [bool](Get-NetTCPConnection -State Listen -LocalPort $p -ErrorAction SilentlyContinue) }
function Step($title, [scriptblock]$do) {
    Say "-> $title" Cyan
    & $do
    if ($LASTEXITCODE -ne 0) { Fail "Step failed: $title (see the messages above)." }
}

Set-Location $Root
$Host.UI.RawUI.WindowTitle = 'MR-POS server - close this window to stop'
Say '=== Mr Printer & Tuner POS ===' Green

foreach ($need in @($Php, $Httpd)) { if (-not (Test-Path $need)) { Fail "Not found: $need. Install XAMPP first (docs\INSTALLATION.md)." } }

# ---- 1. First-run installation (skipped when everything is already there) ----
$needInstall = -not ((Test-Path 'vendor\autoload.php') -and (Test-Path 'public\build\manifest.json') -and (Test-Path '.env') -and (Test-Path $Marker))
if ($needInstall) {
    Say 'First run: installing. This can take a few minutes and needs the internet once.' Yellow
    if (-not (Test-Path '.env')) { Copy-Item '.env.example' '.env' }
    if (-not (Test-Path 'vendor\autoload.php')) {
        if (-not (Get-Command composer -ErrorAction SilentlyContinue)) { Fail 'Composer is not installed. Install it from getcomposer.org, then click the icon again.' }
        Step 'Installing PHP packages (composer)' { & composer install --no-dev --optimize-autoloader --no-interaction }
    }
    if (-not (Test-Path 'public\build\manifest.json')) {
        if (-not (Get-Command npm -ErrorAction SilentlyContinue)) { Fail 'Node.js is not installed. Install the LTS version from nodejs.org, then click the icon again.' }
        Step 'Installing front-end packages (npm)' { & npm ci --no-audit --no-fund }
        Step 'Building the screens (npm run build)' { & npm run build }
    }
}

# ---- 2. Database ----
if (-not (Listening $DbPort)) {
    Say "-> Starting the database (MariaDB, port $DbPort)" Cyan
    if (-not (Test-Path "$Maria\bin\mariadbd.exe")) { Fail "MariaDB not found at $Maria." }
    Start-Process "$Maria\bin\mariadbd.exe" -ArgumentList "--defaults-file=$Maria\my.ini", '--standalone' -WindowStyle Hidden
    $t = 0; while (-not (Listening $DbPort) -and $t -lt 30) { Start-Sleep 1; $t++ }
    if (-not (Listening $DbPort)) { Fail 'The database did not start. See docs\INSTALLATION.md (troubleshooting).' }
}

if (-not (Select-String -Path '.env' -Pattern '^APP_KEY=base64:' -Quiet)) { Step 'Creating the security key' { & $Php artisan key:generate --force } }

# Native tools write notices to stderr; read them without stopping the script.
function Artisan-Text { $ErrorActionPreference = 'Continue'; (& $Php artisan @args 2>&1 | ForEach-Object { "$_" }) -join "`n" }
$users = (Artisan-Text tinker --execute='try { echo \App\Models\User::count(); } catch (\Throwable $e) { echo -1; }').Trim().Split("`n")[-1]

if ($users -eq '-1' -or $users -eq '0') {
    # New database: pos:install creates the tables, roles and the first administrator (you type the password).
    Say "`nFirst-time setup of the database. Create the administrator (you choose the password):" Yellow
    & $Php artisan pos:install
    if ($LASTEXITCODE -ne 0) { Fail 'Setup did not finish. Click the icon again to retry.' }
} elseif ((Artisan-Text migrate:status) -match 'Pending') {
    # The program was updated and the database needs new tables. Back up the real data first, always.
    Step 'Backing up before updating the database' { & $Php artisan pos:backup:run --tier=safety }
    Step 'Updating the database' { & $Php artisan migrate --force }
}

if ($needInstall) {
    Step 'Preparing the screens for a fast first page' { & $Php artisan view:cache }
    New-Item -ItemType Directory -Force -Path (Split-Path $Marker) | Out-Null
    Set-Content -Path $Marker -Value (Get-Date -Format s)
}

# ---- 3. Apache (own config, own port) ----
New-Item -ItemType Directory -Force -Path $ConfDir | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $Root 'storage\logs') | Out-Null
$mods = 'authz_core','authz_host','alias','dir','env','headers','log_config','mime','rewrite','setenvif' | ForEach-Object { "LoadModule ${_}_module modules/mod_$_.so" }
@"
# Generated by MR-POS.ps1 on every start. Do not edit.
ServerRoot "C:/xampp/apache"
PidFile "$Fwd/storage/app/apache/httpd.pid"
Listen 0.0.0.0:$Port
ServerName localhost:$Port
ServerTokens Prod
ThreadsPerChild 64
$($mods -join "`n")
LoadFile "C:/xampp/php/php8ts.dll"
LoadFile "C:/xampp/php/libpq.dll"
LoadFile "C:/xampp/php/libsqlite3.dll"
LoadModule php_module "C:/xampp/php/php8apache2_4.dll"
PHPIniDir "C:/xampp/php"
TypesConfig conf/mime.types
DirectoryIndex index.php
AddHandler application/x-httpd-php .php
ErrorLog "$Fwd/storage/logs/apache-error.log"
LogLevel warn
DocumentRoot "$Fwd/public"
<Directory />
    AllowOverride None
    Require all denied
</Directory>
<Directory "$Fwd/public">
    Options FollowSymLinks
    AllowOverride All
    Require all granted
</Directory>
<Directory "$Fwd/storage">
    Require all denied
</Directory>
Header always set X-Content-Type-Options "nosniff"
Header always set X-Frame-Options "SAMEORIGIN"
Header always set Referrer-Policy "same-origin"
"@ | Set-Content -Path $Conf -Encoding ascii

$ErrorActionPreference = 'Continue'   # httpd writes "Syntax OK" to stderr; that is not an error
$check = & $Httpd -f $Conf -t 2>&1 | ForEach-Object { "$_" }
$ok = ($LASTEXITCODE -eq 0)
$ErrorActionPreference = 'Stop'
if (-not $ok) { $check | ForEach-Object { Say $_ Red }; Fail 'The Apache settings were rejected (message above).' }

$ours = $false
if (Listening $Port) {
    Say "Apache is already serving this program on port $Port. Opening it." Yellow
} else {
    Say "-> Starting Apache on port $Port" Cyan
    # Same console as this window, so closing the window also ends Apache.
    $apache = Start-Process $Httpd -ArgumentList '-f', "`"$Conf`"" -NoNewWindow -PassThru
    $ours = $true
    $t = 0; while (-not (Listening $Port) -and $t -lt 20) { Start-Sleep 1; $t++ }
    if (-not (Listening $Port)) { Fail "Apache did not start. Is port $Port used by another program? See storage\logs\apache-error.log" }
}

$ip = (Get-NetIPAddress -AddressFamily IPv4 -ErrorAction SilentlyContinue | Where-Object { $_.IPAddress -notmatch '^(127|169)\.' } | Select-Object -First 1).IPAddress
Say "`nMR-POS is running." Green
Say "  On this PC : http://localhost:$Port"
if ($ip) { Say "  Other PCs  : http://${ip}:$Port  (needs the firewall rule, see GO_LIVE.md)" }
Say "`nTo stop: press Q here, or close this window. Apache stops with it.`n" Yellow
Start-Process "http://localhost:$Port"

# ---- 4. Wait, then stop Apache ----
try {
    while ($true) {
        Start-Sleep -Milliseconds 500
        if ($ours -and $apache.HasExited) { Say 'Apache stopped.' Red; break }
        if ([Console]::KeyAvailable) {
            $k = [Console]::ReadKey($true)
            if ($k.Key -eq 'Q') { break }
        }
    }
} finally {
    if ($ours -and $apache -and -not $apache.HasExited) {
        Say 'Stopping Apache...' Gray
        & taskkill /PID $apache.Id /T /F | Out-Null
    }
    Remove-Item $PidFile -ErrorAction SilentlyContinue
}
Say 'Apache stopped. The database keeps running (it is shared); closing this window is safe.' Green
Start-Sleep 2
