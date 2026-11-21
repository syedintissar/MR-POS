# Creates the MR-POS icon on the Desktop (and deploy\windows\MR-POS.ico). Run once; no administrator needed.
$ErrorActionPreference = 'Stop'
Add-Type -AssemblyName System.Drawing
$Root = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$IcoPath = Join-Path $PSScriptRoot 'MR-POS.ico'

# Draw the icon: blue rounded square with "POS" (256 px, stored as a PNG inside the .ico)
$bmp = New-Object System.Drawing.Bitmap 256, 256
$g = [System.Drawing.Graphics]::FromImage($bmp)
$g.SmoothingMode = 'AntiAlias'; $g.TextRenderingHint = 'AntiAliasGridFit'
$path = New-Object System.Drawing.Drawing2D.GraphicsPath
$r = 48; $s = 256
$path.AddArc(0, 0, $r, $r, 180, 90); $path.AddArc($s - $r, 0, $r, $r, 270, 90)
$path.AddArc($s - $r, $s - $r, $r, $r, 0, 90); $path.AddArc(0, $s - $r, $r, $r, 90, 90); $path.CloseFigure()
$g.FillPath((New-Object System.Drawing.SolidBrush ([System.Drawing.Color]::FromArgb(29, 78, 216))), $path)
$font = New-Object System.Drawing.Font 'Segoe UI', 88, ([System.Drawing.FontStyle]::Bold), ([System.Drawing.GraphicsUnit]::Pixel)
$fmt = New-Object System.Drawing.StringFormat; $fmt.Alignment = 'Center'; $fmt.LineAlignment = 'Center'
$g.DrawString('POS', $font, [System.Drawing.Brushes]::White, (New-Object System.Drawing.RectangleF 0, 0, 256, 256), $fmt)
$g.Dispose()
$ms = New-Object System.IO.MemoryStream; $bmp.Save($ms, [System.Drawing.Imaging.ImageFormat]::Png); $png = $ms.ToArray(); $bmp.Dispose()
$w = New-Object System.IO.BinaryWriter ([System.IO.File]::Create($IcoPath))
$w.Write([uint16]0); $w.Write([uint16]1); $w.Write([uint16]1)
$w.Write([byte]0); $w.Write([byte]0); $w.Write([byte]0); $w.Write([byte]0); $w.Write([uint16]1); $w.Write([uint16]32)
$w.Write([uint32]$png.Length); $w.Write([uint32]22); $w.Write($png); $w.Close()

$desktop = [Environment]::GetFolderPath('Desktop')
$sh = (New-Object -ComObject WScript.Shell).CreateShortcut((Join-Path $desktop 'MR-POS.lnk'))
$sh.TargetPath = "$env:SystemRoot\System32\WindowsPowerShell\v1.0\powershell.exe"
$sh.Arguments = "-NoProfile -ExecutionPolicy Bypass -File `"$(Join-Path $PSScriptRoot 'MR-POS.ps1')`""
$sh.WorkingDirectory = $Root
$sh.IconLocation = $IcoPath
$sh.Description = 'Start Mr Printer & Tuner POS (stops when you close the window)'
$sh.Save()
Write-Host "Done. The MR-POS icon is on your Desktop: $desktop\MR-POS.lnk"
