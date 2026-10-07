<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Middleware\IpAllowList;
use App\Models\DocumentSequence;
use App\Models\PaymentMethod;
use App\Services\AuditLogger;
use App\Services\SettingsService;
use App\Support\SettingsCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SettingsController extends Controller
{
    /** Tabs shown on the settings screen (backup settings live on the Backups page). */
    public const TABS = [
        'shop' => 'Shop',
        'currency' => 'Currency',
        'tax' => 'Sales tax',
        'sales' => 'Sales',
        'payment_methods' => 'Payment methods',
        'inventory' => 'Inventory',
        'printing' => 'Printing',
        'receipt' => 'Receipt text',
        'security' => 'Security',
        'numbering' => 'Document numbers',
    ];

    public function __construct(private readonly SettingsService $settings, private readonly AuditLogger $audit) {}

    public function index(string $group = 'shop'): View
    {
        $this->authorize('settings.view');
        abort_unless(array_key_exists($group, self::TABS), 404);
        $special = in_array($group, ['numbering', 'payment_methods'], true);   // tabs with their own form

        return view('admin.settings.index', [
            'tabs' => self::TABS,
            'group' => $group,
            'fields' => $special ? [] : collect(SettingsCatalogue::forGroup($group))->reject(fn ($d) => $d['hidden'] ?? false)->all(),
            'values' => $special ? [] : $this->settings->group($group),
            'sequences' => $group === 'numbering' ? DocumentSequence::query()->orderBy('id')->get() : collect(),
            'methods' => $group === 'payment_methods' ? PaymentMethod::query()->withCount('supplierPayments')->orderBy('sort_order')->orderBy('name')->get() : collect(),
            'kinds' => array_diff_key(PaymentMethod::KINDS, ['customer_credit' => 1]),
            'hasLogo' => $this->settings->string('shop.logo_path') !== '',
        ]);
    }

    public function update(Request $request, string $group): RedirectResponse
    {
        $this->authorize('settings.manage');
        abort_unless(array_key_exists($group, self::TABS) && ! in_array($group, ['numbering', 'payment_methods'], true), 404);

        $defs = collect(SettingsCatalogue::forGroup($group))->reject(fn ($d) => $d['hidden'] ?? false);
        $rules = $defs->mapWithKeys(fn ($d, $key) => [str_replace('.', '_', $key) => $d['rules']])->all();

        if ($group === 'shop') {
            $rules['logo'] = ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:1024'];
            $rules['remove_logo'] = ['nullable', 'boolean'];
        }

        $validated = $request->validate($rules);

        if ($group === 'security') {
            $list = IpAllowList::parse((string) ($validated['security_ip_allowlist'] ?? ''));
            if ($list !== [] && ! IpAllowList::permits($request->ip(), $list)) {
                throw ValidationException::withMessages([
                    'security_ip_allowlist' => "Your own address ({$request->ip()}) is not in this list, so saving it would lock you out. Add it first.",
                ]);
            }
        }

        $values = [];
        foreach ($defs as $key => $def) {
            $field = str_replace('.', '_', $key);
            $values[$key] = $def['type'] === 'bool' ? $request->boolean($field) : ($validated[$field] ?? null);
        }

        if ($group === 'shop') {
            if ($request->hasFile('logo')) {
                $values['shop.logo_path'] = $this->storeLogo($request->file('logo')->getRealPath());
            } elseif ($request->boolean('remove_logo')) {
                $values['shop.logo_path'] = '';
            }
        }

        $changes = $this->settings->setMany($values, $request->user());

        return redirect()->route('settings.index', $group)->with('success', $changes === [] ? 'Nothing changed.' : 'Settings saved.');
    }

    public function updateNumbering(Request $request): RedirectResponse
    {
        $this->authorize('settings.manage');

        $data = $request->validate([
            'seq' => ['required', 'array'],
            'seq.*.prefix' => ['nullable', 'regex:/^[A-Za-z0-9\-_\/]{0,20}$/'],
            'seq.*.padding' => ['required', 'integer', 'min:3', 'max:10'],
            'seq.*.reset_yearly' => ['nullable', 'boolean'],
        ], ['seq.*.prefix.regex' => 'Prefixes may contain letters, numbers, - _ / only (max 20 characters).']);

        DB::transaction(function () use ($data) {
            $old = [];
            $new = [];

            foreach (DocumentSequence::query()->whereIn('id', array_keys($data['seq']))->lockForUpdate()->get() as $seq) {
                $in = $data['seq'][$seq->id];
                $row = ['prefix' => (string) ($in['prefix'] ?? ''), 'padding' => (int) $in['padding'], 'reset_yearly' => (bool) ($in['reset_yearly'] ?? false)];

                if ($row !== ['prefix' => $seq->prefix, 'padding' => $seq->padding, 'reset_yearly' => $seq->reset_yearly]) {
                    $old[$seq->doc_type] = ['prefix' => $seq->prefix, 'padding' => $seq->padding, 'reset_yearly' => $seq->reset_yearly];
                    $new[$seq->doc_type] = $row;
                    $seq->update($row);
                }
            }

            if ($new !== []) {
                $this->audit->log('numbering_changed', 'settings', 'Document numbering changed: '.implode(', ', array_keys($new)), null, $old, $new);
            }
        });

        return redirect()->route('settings.index', 'numbering')->with('success', 'Document numbering saved. Numbers already issued are not renumbered.');
    }

    /** The logo is public on purpose: it appears on the login page and on printed receipts. */
    public function logo(): BinaryFileResponse
    {
        $rel = $this->settings->string('shop.logo_path');
        $path = $rel === '' ? null : rtrim((string) config('mrpos.files_path'), '\/').DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $rel);
        abort_unless($path && is_file($path), 404);

        return response()->file($path, ['Cache-Control' => 'public, max-age=3600', 'Content-Type' => 'image/png']);
    }

    /** Re-encode the upload with GD (drops any hidden payload) and cap its size. */
    private function storeLogo(string $tmpPath): string
    {
        $img = @imagecreatefromstring((string) file_get_contents($tmpPath));
        if ($img === false) {
            throw ValidationException::withMessages(['logo' => 'That file could not be read as an image.']);
        }

        $w = imagesx($img);
        $maxW = 600;
        if ($w > $maxW) {
            $scaled = imagescale($img, $maxW);
            if ($scaled !== false) {
                $img = $scaled;
            }
        }
        imagesavealpha($img, true);

        $dir = rtrim((string) config('mrpos.files_path'), '\/').DIRECTORY_SEPARATOR.'branding';
        File::ensureDirectoryExists($dir);
        $file = 'logo-'.substr(sha1((string) microtime(true)), 0, 8).'.png';
        imagepng($img, $dir.DIRECTORY_SEPARATOR.$file);

        // Remove older logo versions.
        foreach (File::glob($dir.DIRECTORY_SEPARATOR.'logo-*.png') as $old) {
            if (basename($old) !== $file) {
                @unlink($old);
            }
        }

        return 'branding/'.$file;
    }
}
