<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Recovery Kit · {{ $shop }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: 'Segoe UI', Arial, sans-serif; font-size: 12.5px; color: #111; background: #e5e7eb; }
        .bar { position: sticky; top: 0; padding: .6rem 1rem; background: #fff; border-bottom: 1px solid #d1d5db; font-size: 14px; }
        .bar button { padding: .4rem .9rem; border: 0; border-radius: 6px; background: #c0121b; color: #fff; cursor: pointer; margin-right: .75rem; }
        .sheet { width: 210mm; margin: 12px auto; padding: 14mm; background: #fff; }
        h1 { margin: 0 0 2px; font-size: 20px; } h2 { margin: 14px 0 4px; font-size: 14px; border-bottom: 1px solid #111; }
        table { width: 100%; border-collapse: collapse; } td { padding: 4px 6px; border-bottom: 1px solid #ddd; vertical-align: top; } td:first-child { width: 42mm; color: #444; }
        code { font-family: Consolas, monospace; word-break: break-all; }
        .secret { border: 2px solid #c0121b; padding: 6px; margin: 6px 0; }
        .write { border-bottom: 1px solid #111; height: 22px; margin-top: 6px; }
        ol { margin: 4px 0 0 18px; padding: 0; } li { margin: 3px 0; }
        @page { size: A4; margin: 0; } @media print { body { background: #fff; } .bar { display: none; } .sheet { margin: 0; } }
    </style>
</head>
<body>
<div class="bar"><button type="button" onclick="window.print()">Print</button> Keep the printed page <strong>somewhere safe and away from this PC</strong> (it contains the application key).</div>
<div class="sheet">
    <h1>{{ $shop }}: Recovery Kit</h1>
    <div>Printed {{ now()->format('d M Y, H:i') }} by {{ $printedBy }}</div>

    <h2>1. What you need to rebuild the system on a new PC</h2>
    <div class="secret">Application key (needed to read saved settings and destination passwords after moving):<br><code>{{ $key }}</code></div>
    <table>
        <tr><td>Backup encryption password</td><td>@if($encrypted)<strong>Backups are encrypted.</strong> The system never prints the password: write it here by hand.<div class="write"></div>@else Backups are not encrypted (no password needed). @endif</td></tr>
        <tr><td>Database</td><td>name <code>{{ $db['name'] }}</code> · server {{ $db['host'] }} port {{ $db['port'] }} (MariaDB 11.4). The database passwords are in the credentials file kept by whoever installed the system, and can be created again.</td></tr>
        <tr><td>Backups on this PC</td><td><code>{{ $root }}</code></td></tr>
        <tr><td>Newest backup</td><td>@if($last){{ $last->filename }} · {{ $last->started_at->format('d M Y, H:i') }} · verification: {{ $last->verify_status }} @else none yet @endif</td></tr>
    </table>

    <h2>2. Where the second copies are</h2>
    <table>
        @forelse($destinations as $d)<tr><td>{{ $d->name }}</td><td>{{ $d->driver }} · <code>{{ data_get($d->config, 'path') }}</code> · {{ $d->is_active ? 'active' : 'switched off' }}</td></tr>
        @empty<tr><td colspan="2"><strong>No second copy is set up.</strong> A disk failure or theft would lose everything: add a USB drive or another PC under Backups → destinations.</td></tr>@endforelse
    </table>

    <h2>3. If the PC is lost or broken</h2>
    <ol>
        <li>Install the system on the new PC by following <strong>docs/INSTALLATION.md</strong> (XAMPP PHP, MariaDB 11.4, the program folder, <code>composer install</code>, <code>npm run build</code>). Put the application key above in <code>.env</code> as <code>APP_KEY</code>.</li>
        <li>Copy the newest backup file (.zip) from the second copy (USB drive or other PC) to the new PC.</li>
        <li>In a command prompt in the program folder run <code>php artisan pos:backup:restore "F:\path\mrpos_….zip"</code>. On a new empty database no login is needed; type the confirmation phrase it asks for (and the backup password if it was encrypted).</li>
        <li>Run <code>php artisan pos:integrity:check</code> and <code>php artisan pos:audit:verify</code>, then sign in and look at today's sales and the stock.</li>
        <li>Make a new backup straight away and check the second copy works again.</li>
    </ol>
    <p>The full procedure is in <strong>docs/BACKUP_RESTORE.md</strong> §12.</p>
</div>
</body>
</html>
