<#
.SYNOPSIS
  Installs MariaDB 11.4 (C:\mariadb-11.4) as a Windows service so the database starts with the PC,
  without anyone having to log in. Run PowerShell AS ADMINISTRATOR.

  This does NOT touch the old XAMPP MariaDB 10.4 and does not change any port.
#>
param(
    [string]$BaseDir = 'C:\mariadb-11.4',
    [string]$ServiceName = 'MariaDB114'
)

$ErrorActionPreference = 'Stop'

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run PowerShell as Administrator.'
}
$daemon = Join-Path $BaseDir 'bin\mariadbd.exe'
$ini = Join-Path $BaseDir 'my.ini'
if (-not (Test-Path $daemon)) { throw "Not found: $daemon" }
if (-not (Test-Path $ini)) { throw "Not found: $ini" }

# If the server was started by hand (as the first setup did), stop that copy first so the service can use the port.
$running = Get-CimInstance Win32_Process -Filter "Name='mariadbd.exe'" | Where-Object { $_.ExecutablePath -like "$BaseDir*" }
foreach ($p in $running) {
    Write-Host "Stopping the hand-started MariaDB 11.4 (pid $($p.ProcessId)) ..."
    $pw = (Get-Content (Join-Path $BaseDir '.root-pw.txt') -Raw).Trim()
    & (Join-Path $BaseDir 'bin\mariadb-admin.exe') --user=root "--password=$pw" --host=127.0.0.1 --port=3308 shutdown
    Start-Sleep -Seconds 5
}

if (Get-Service -Name $ServiceName -ErrorAction SilentlyContinue) {
    Write-Host "Service $ServiceName already exists."
} else {
    & $daemon --install $ServiceName "--defaults-file=$ini"
    if ($LASTEXITCODE -ne 0) { throw 'mariadbd --install failed.' }
}

sc.exe config $ServiceName start= delayed-auto | Out-Null
Start-Service $ServiceName
Write-Host "Service $ServiceName is $((Get-Service $ServiceName).Status) and starts automatically with Windows."
Write-Host 'Check: php artisan pos:doctor'
