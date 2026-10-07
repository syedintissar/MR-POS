<#
.SYNOPSIS
  Registers the Windows Task Scheduler job that runs Laravel's scheduler every minute.
  Automatic backups, retries of USB/off-site copies and the nightly audit check all depend on it.

.DESCRIPTION
  Two modes:
   * Default (no admin needed): runs as YOU, only while you are logged in to Windows.
   * -RunAsSystem (run PowerShell as Administrator): runs at startup whether anyone is logged in or not.
     Recommended for the shop server.

  Safe to run again: it replaces the existing task of the same name.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File .\install-scheduler.ps1 -RunAsSystem
#>
param(
    [string]$ProjectPath = 'D:\MR-POS',
    [string]$PhpPath = 'C:\xampp\php\php.exe',
    [switch]$RunAsSystem,
    [string]$TaskName = 'MRPOS Scheduler'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path (Join-Path $ProjectPath 'artisan'))) { throw "artisan not found in $ProjectPath" }
if (-not (Test-Path $PhpPath)) { throw "PHP not found at $PhpPath" }

# php-win.exe is the same PHP without a console window, so nothing flashes on screen every minute.
$phpWin = Join-Path (Split-Path $PhpPath) 'php-win.exe'
$exe = if (Test-Path $phpWin) { $phpWin } else { $PhpPath }

$isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if ($RunAsSystem -and -not $isAdmin) { throw 'Run PowerShell as Administrator to use -RunAsSystem.' }

$action = New-ScheduledTaskAction -Execute $exe -Argument 'artisan schedule:run' -WorkingDirectory $ProjectPath
$triggers = @(
    (New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650))
)
if ($RunAsSystem) { $triggers += New-ScheduledTaskTrigger -AtStartup }

$settings = New-ScheduledTaskSettingsSet -StartWhenAvailable -MultipleInstances IgnoreNew `
    -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -ExecutionTimeLimit (New-TimeSpan -Minutes 30)

if ($RunAsSystem) {
    $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest
} else {
    $principal = New-ScheduledTaskPrincipal -UserId ([Security.Principal.WindowsIdentity]::GetCurrent().Name) -LogonType Interactive -RunLevel Limited
}

Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $triggers -Settings $settings -Principal $principal `
    -Description 'Mr Printer & Tuner POS: backups, retries and audit checks (runs php artisan schedule:run every minute).' | Out-Null

$mode = if ($RunAsSystem) { 'runs at startup, logged in or not' } else { 'runs while you are logged in' }
Write-Host "Task '$TaskName' registered ($mode)."
Write-Host 'Check it in 2 minutes: Dashboard -> Backups should no longer say the scheduled-task runner is not running.'
