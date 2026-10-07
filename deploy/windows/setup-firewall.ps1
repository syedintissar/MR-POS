<#
.SYNOPSIS
  Lets other computers on the shop network (and only the local subnet, only on a Private network)
  open the POS website on port 80. Run PowerShell AS ADMINISTRATOR.
  The database port is deliberately NOT opened.
#>
param([int]$Port = 80, [string]$RuleName = 'MR-POS web (LAN only)')

$ErrorActionPreference = 'Stop'

if (-not ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    throw 'Run PowerShell as Administrator.'
}

Remove-NetFirewallRule -DisplayName $RuleName -ErrorAction SilentlyContinue
New-NetFirewallRule -DisplayName $RuleName -Direction Inbound -Protocol TCP -LocalPort $Port `
    -Profile Private -RemoteAddress LocalSubnet -Action Allow | Out-Null
Write-Host "Allowed TCP $Port from the local subnet on Private networks."

Write-Host ''
Write-Host 'Your network connections (the shop network must say Private):'
Get-NetConnectionProfile | Format-Table Name, InterfaceAlias, NetworkCategory -AutoSize
Write-Host 'If it says Public, change it with:  Set-NetConnectionProfile -InterfaceAlias "Ethernet" -NetworkCategory Private'

$open = Get-NetFirewallRule -Direction Inbound -Enabled True -Action Allow | Get-NetFirewallPortFilter | Where-Object { $_.LocalPort -in '3306', '3307', '3308' }
if ($open) {
    Write-Warning 'A firewall rule allows a database port (3306/3307/3308) in. Remove it: the database must only be reachable from this PC.'
} else {
    Write-Host 'Good: no firewall rule opens a database port.'
}
