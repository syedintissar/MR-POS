<#
.SYNOPSIS
  Prepares php.ini for MR-POS on a fresh PHP folder: copies php.ini-production to php.ini (only when there is none yet),
  switches on the extensions the system needs and sets the limits it uses.
  Safe to run again: it only changes the lines listed below. No administrator rights needed.

.EXAMPLE
  powershell -ExecutionPolicy Bypass -File deploy\windows\configure-php.ps1
#>
param([string]$PhpDir = 'C:\xampp\php')

$ErrorActionPreference = 'Stop'

$ini = Join-Path $PhpDir 'php.ini'
if (-not (Test-Path $ini)) {
    $template = Join-Path $PhpDir 'php.ini-production'
    if (-not (Test-Path $template)) { throw "Neither php.ini nor php.ini-production found in $PhpDir" }
    Copy-Item $template $ini
    Write-Host "Created $ini from php.ini-production."
}

$text = [IO.File]::ReadAllText($ini)

# extensions: remove the leading ";" (or add the line when it is not there at all)
foreach ($ext in 'curl', 'fileinfo', 'gd', 'intl', 'mbstring', 'openssl', 'pdo_mysql', 'sodium', 'zip') {
    if ($text -match "(?m)^\s*extension\s*=\s*$ext\s*$") { continue }
    if ($text -match "(?m)^\s*;\s*extension\s*=\s*$ext\s*$") {
        $text = [regex]::Replace($text, "(?m)^\s*;\s*extension\s*=\s*$ext\s*$", "extension=$ext")
    } else {
        $text += "`r`nextension=$ext"
    }
}

# settings: replace the line whether it is commented out or not (or add it)
$settings = [ordered]@{
    'extension_dir'       = '"' + (Join-Path $PhpDir 'ext') + '"'
    'memory_limit'        = '512M'
    'upload_max_filesize' = '128M'
    'post_max_size'       = '128M'
    'max_execution_time'  = '600'
    'date.timezone'       = 'Asia/Karachi'
}
foreach ($key in $settings.Keys) {
    $line = "$key = $($settings[$key])"
    $pattern = "(?m)^\s*;?\s*$([regex]::Escape($key))\s*=.*$"
    if ($text -match $pattern) {
        # only the first match is replaced: php.ini-production has example lines for other platforms further down
        $text = ([regex]$pattern).Replace($text, $line.Replace('$', '$$'), 1)
    } else {
        $text += "`r`n$line"
    }
}

[IO.File]::WriteAllText($ini, $text)
Write-Host "php.ini is ready: $ini"
Write-Host 'Restart Apache after this (net stop Apache2.4 & net start Apache2.4) if it is already running.'
