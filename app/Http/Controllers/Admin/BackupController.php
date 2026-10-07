<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Exceptions\BackupException;
use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\BackupDestination;
use App\Services\AuditLogger;
use App\Services\Backup\BackupHealth;
use App\Services\Backup\BackupService;
use App\Services\Backup\BackupVerifier;
use App\Services\Backup\TransferService;
use App\Services\SettingsService;
use App\Support\SettingsCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class BackupController extends Controller
{
    public function __construct(
        private readonly BackupService $backups,
        private readonly BackupVerifier $verifier,
        private readonly TransferService $transfers,
        private readonly SettingsService $settings,
        private readonly AuditLogger $audit,
    ) {}

    public function index(BackupHealth $health): View
    {
        $this->authorize('backups.view');

        return view('admin.backups.index', [
            'warnings' => $health->warnings(),
            'backups' => Backup::query()->with('transfers.destination', 'triggeredBy')->orderByDesc('started_at')->orderByDesc('id')->limit(60)->get(),
            'destinations' => BackupDestination::query()->orderBy('id')->get(),
            'fields' => SettingsCatalogue::forGroup('backup'),
            'values' => $this->settings->group('backup'),
            'encryptionPasswordSet' => $this->settings->string('backup.encryption_password') !== '',
            'root' => $this->backups->root(),
        ]);
    }

    /** "Backup Now". Runs while the user waits; a failure is shown loudly, never hidden. */
    public function store(Request $request): RedirectResponse
    {
        $this->authorize('backups.create');

        try {
            $b = $this->backups->create(Backup::TIER_MANUAL, $request->user());
        } catch (BackupException $e) {
            return redirect()->route('backups.index')->with('error', 'Backup FAILED: '.$e->getMessage());
        }

        return redirect()->route('backups.index')->with('success', "Backup completed: {$b->filename}");
    }

    public function verify(Request $request, Backup $backup): RedirectResponse
    {
        $this->authorize('backups.verify');
        $request->validate(['level' => ['required', 'in:quick,deep']]);
        abort_if($backup->status !== Backup::STATUS_SUCCESS || $backup->pruned_at, 404);

        $r = $request->input('level') === 'deep' ? $this->verifier->deepAndRecord($backup) : $this->verifier->quickAndRecord($backup);
        $this->audit->log('backup_verified', 'backups', ucfirst($r->level)." verification of {$backup->filename}: ".($r->passed ? 'passed' : 'FAILED'), $backup, new: ['passed' => $r->passed, 'notes' => $r->notes]);

        return redirect()->route('backups.index')->with($r->passed ? 'success' : 'error', ($r->passed ? 'Verification passed: ' : 'Verification FAILED: ').$backup->filename);
    }

    /** Needs a recently confirmed password (route middleware) because a backup holds all shop data. */
    public function download(Backup $backup): BinaryFileResponse
    {
        $this->authorize('backups.download');
        abort_if($backup->status !== Backup::STATUS_SUCCESS || $backup->pruned_at, 404);

        $path = $this->backups->absolutePath($backup);
        abort_unless(is_file($path), 404, 'The backup file is missing from disk.');

        $this->audit->log('backup_downloaded', 'backups', "Backup downloaded: {$backup->filename}", $backup);

        return response()->download($path, $backup->filename);
    }

    public function destroy(Backup $backup): RedirectResponse
    {
        $this->authorize('backups.delete');

        if ($backup->is_protected) {
            return back()->with('error', 'This backup is protected. Remove the protection first.');
        }
        $newest = Backup::available()->orderByDesc('started_at')->orderByDesc('id')->value('id');
        if ($backup->id === $newest) {
            return back()->with('error', 'The newest good backup cannot be deleted. Make a newer one first.');
        }

        $path = $this->backups->absolutePath($backup);
        @unlink($path);
        @unlink($path.'.sha256');
        $backup->update(['pruned_at' => now()]);
        $this->audit->log('backup_deleted', 'backups', "Backup deleted: {$backup->filename}", $backup);

        return back()->with('success', 'Backup file deleted.');
    }

    public function protect(Backup $backup): RedirectResponse
    {
        $this->authorize('backups.settings');

        $backup->update(['is_protected' => ! $backup->is_protected]);
        $this->audit->log('backup_protection', 'backups', ($backup->is_protected ? 'Protected: ' : 'Unprotected: ').$backup->filename, $backup);

        return back()->with('success', $backup->is_protected ? 'Backup is now protected from automatic deletion.' : 'Protection removed.');
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $this->authorize('backups.settings');

        $defs = SettingsCatalogue::forGroup('backup');
        $rules = collect($defs)->mapWithKeys(fn ($d, $k) => [str_replace('.', '_', $k) => $d['rules']])->all();
        $v = $request->validate($rules);

        $values = [];
        foreach ($defs as $key => $def) {
            $field = str_replace('.', '_', $key);
            $values[$key] = $def['type'] === 'bool' ? $request->boolean($field) : ($v[$field] ?? null);
        }

        // Encryption without a password would produce unreadable backups.
        if ($values['backup.encryption_enabled'] && ! ($values['backup.encryption_password'] ?? null) && $this->settings->string('backup.encryption_password') === '') {
            throw ValidationException::withMessages(['backup_encryption_password' => 'Choose a backup password before turning encryption on, and write it down somewhere safe. Without it the backups cannot be opened.']);
        }

        $this->settings->setMany($values, $request->user());

        return redirect()->route('backups.index')->with('success', 'Backup settings saved.');
    }

    public function storeDestination(Request $request): RedirectResponse
    {
        $this->authorize('backups.settings');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'path' => ['required', 'string', 'max:300'],
        ]);

        $destination = DB::transaction(function () use ($data, $request) {
            $d = BackupDestination::create([
                'name' => $data['name'],
                'driver' => 'local_path',
                'config' => ['path' => rtrim($data['path'], '\\/')],
                'is_active' => true,
                'requires_encryption' => $request->boolean('requires_encryption'),
            ]);
            $this->audit->log('backup_destination_added', 'backups', "Backup destination added: {$d->name} ({$data['path']})", $d, new: ['name' => $d->name, 'path' => $data['path']]);

            return $d;
        });

        $problem = $this->transfers->test($destination);

        return redirect()->route('backups.index')->with($problem ? 'error' : 'success', $problem
            ? "Destination saved, but it is not reachable right now: {$problem}"
            : 'Destination added and working. New backups will be copied there automatically.');
    }

    public function toggleDestination(BackupDestination $destination): RedirectResponse
    {
        $this->authorize('backups.settings');

        $destination->update(['is_active' => ! $destination->is_active]);
        $this->audit->log('backup_destination_toggled', 'backups', "Backup destination {$destination->name} ".($destination->is_active ? 'enabled' : 'disabled'), $destination);

        return back()->with('success', 'Destination updated.');
    }

    public function testDestination(BackupDestination $destination): RedirectResponse
    {
        $this->authorize('backups.settings');

        $problem = $this->transfers->test($destination);
        $destination->update(['last_error' => $problem]);

        return back()->with($problem ? 'error' : 'success', $problem ? "Not reachable: {$problem}" : "Destination \"{$destination->name}\" is reachable and writable.");
    }

    public function retryTransfers(): RedirectResponse
    {
        $this->authorize('backups.create');

        $r = $this->transfers->runDue();

        return back()->with('success', "Off-site copies: {$r['success']} completed, {$r['failed']} still waiting.");
    }
}
