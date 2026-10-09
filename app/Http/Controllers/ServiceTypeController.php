<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ServiceType;
use App\Services\AuditLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/** The kinds of service the shop offers (SRV-01, SET-08), each with an optional suggested charge. A type that has been used can only be switched off. */
class ServiceTypeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): View
    {
        $this->authorize('service_types.manage');

        return view('service.types.index', ['types' => ServiceType::query()->withCount('jobs')->orderBy('sort_order')->orderBy('name')->get()]);
    }

    public function create(): View
    {
        $this->authorize('service_types.manage');

        return view('service.types.form', ['type' => new ServiceType(['is_active' => true, 'sort_order' => (int) ServiceType::query()->max('sort_order') + 10])]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('service_types.manage');
        $d = $this->validated($request, null);

        $type = ServiceType::query()->create($d);
        $this->audit->log('service_type_created', 'service', "Service type \"{$type->name}\" created", $type, new: $d, user: $request->user());

        return redirect()->route('service.types.index')->with('success', "Service type \"{$type->name}\" added.");
    }

    public function edit(ServiceType $type): View
    {
        $this->authorize('service_types.manage');

        return view('service.types.form', ['type' => $type]);
    }

    public function update(Request $request, ServiceType $type): RedirectResponse
    {
        $this->authorize('service_types.manage');
        $d = $this->validated($request, $type);

        $old = $type->only(['name', 'default_charge', 'is_active', 'sort_order']);
        $type->update($d);
        $this->audit->log('service_type_updated', 'service', "Service type \"{$type->name}\" changed", $type, old: $old, new: $d, user: $request->user());

        return redirect()->route('service.types.index')->with('success', 'Service type saved.');
    }

    public function toggle(Request $request, ServiceType $type): RedirectResponse
    {
        $this->authorize('service_types.manage');
        $type->update(['is_active' => ! $type->is_active]);
        $this->audit->log($type->is_active ? 'service_type_activated' : 'service_type_deactivated', 'service', "Service type \"{$type->name}\" ".($type->is_active ? 'switched on' : 'switched off'), $type, user: $request->user());

        return back()->with('success', $type->is_active ? 'Service type is on again.' : 'Service type switched off: it is not offered for new jobs, old jobs keep it.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ServiceType $type): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('service_types', 'name')->ignore($type?->id)],
            'default_charge' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'], 'sort_order' => ['nullable', 'integer', 'min:0', 'max:65000'],
        ], ['decimal' => 'Too many decimal places.']);

        return [
            'name' => trim($d['name']), 'default_charge' => ($d['default_charge'] ?? '') === '' ? null : $d['default_charge'],
            'sort_order' => (int) ($d['sort_order'] ?? 0), 'is_active' => $request->boolean('is_active', true),
        ];
    }
}
