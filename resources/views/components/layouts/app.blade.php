@props(['title' => 'Dashboard'])
@php
    $settings = app(\App\Services\SettingsService::class);
    $shop = $settings->string('shop.name') ?: config('app.name');
    $user = auth()->user();
    $hasLogo = $settings->string('shop.logo_path') !== '';
    // the owner's uploaded logo (Settings > Shop) wins; otherwise the built-in MR mark
    $logoUrl = $hasLogo ? route('branding.logo') : asset('brand/mr-logo.svg');

    // Critical backup problems show as a red dot next to "Backups" (cached for a minute to keep pages fast).
    $backupAlert = false;
    if ($user->can('backups.view')) {
        $backupAlert = \Illuminate\Support\Facades\Cache::remember('backup.health.critical', 60, fn () => app(\App\Services\Backup\BackupHealth::class)->hasCritical());
    }

    $nav = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'match' => 'dashboard', 'can' => 'dashboard.view', 'icon' => 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
    ];
    $admin = [
        ['label' => 'Users', 'route' => 'users.index', 'match' => 'users.*', 'can' => 'users.view', 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
        ['label' => 'Roles & permissions', 'route' => 'roles.index', 'match' => 'roles.*', 'can' => 'roles.manage', 'icon' => 'M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
        ['label' => 'Settings', 'route' => 'settings.index', 'match' => 'settings.*', 'can' => 'settings.view', 'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z'],
        ['label' => 'Backups', 'route' => 'backups.index', 'match' => 'backups.*', 'can' => 'backups.view', 'alert' => $backupAlert, 'icon' => 'M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4'],
        ['label' => 'Audit log', 'route' => 'audit.index', 'match' => 'audit.*', 'can' => 'audit_logs.view', 'icon' => 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
    ];
    $catalogue = [
        ['label' => 'Products', 'route' => 'products.index', 'match' => 'products.*', 'can' => 'products.view', 'icon' => 'M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4'],
        ['label' => 'Categories & brands', 'route' => 'catalog.index', 'match' => 'catalog.*', 'can' => 'products.view', 'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
        ['label' => 'Barcode labels', 'route' => 'labels.index', 'match' => 'labels.*', 'can' => 'products.view', 'icon' => 'M4 6h2v12H4zM8 6h1v12H8zM11 6h2v12h-2zM15 6h1v12h-1zM18 6h2v12h-2z'],
    ];
    $inventoryNav = [
        ['label' => 'Stock', 'route' => 'inventory.stock', 'match' => 'inventory.stock', 'can' => 'inventory.view', 'icon' => 'M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4'],
        ['label' => 'Stock card', 'route' => 'inventory.movements', 'match' => 'inventory.movements', 'can' => 'inventory.view', 'icon' => 'M7 16V4m0 0L3 8m4-4l4 4m6 0v12m0 0l4-4m-4 4l-4-4'],
        ['label' => 'Adjustments', 'route' => 'adjustments.index', 'match' => 'adjustments.*', 'can' => 'inventory.view', 'icon' => 'M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4'],
    ];
    $purchasing = [
        ['label' => 'Purchases', 'route' => 'purchases.index', 'match' => 'purchases.*', 'can' => 'purchases.view', 'icon' => 'M3 3h2l.4 2M7 13h10l4-8H5.4M7 13L5.4 5M7 13l-2.293 2.293c-.63.63-.184 1.707.707 1.707H17m0 0a2 2 0 100 4 2 2 0 000-4zm-8 2a2 2 0 11-4 0 2 2 0 014 0z'],
        ['label' => 'Supplier payments', 'route' => 'supplier-payments.index', 'match' => 'supplier-payments.*', 'can' => 'supplier_payments.view', 'icon' => 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'],
        ['label' => 'Suppliers', 'route' => 'suppliers.index', 'match' => 'suppliers.*', 'can' => 'suppliers.view', 'icon' => 'M8 17a2 2 0 11-4 0 2 2 0 014 0zm10 0a2 2 0 11-4 0 2 2 0 014 0zM13 16V6a1 1 0 00-1-1H4a1 1 0 00-1 1v10m10 0h1m-1 0H9m4-10h3.586a1 1 0 01.707.293l2.414 2.414a1 1 0 01.293.707V16h-1'],
    ];
    $selling = [
        ['label' => 'Point of sale', 'route' => 'pos.index', 'match' => 'pos.*', 'can' => 'pos.access', 'icon' => 'M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z'],
        ['label' => 'Sales', 'route' => 'sales.index', 'match' => 'sales.*', 'can' => 'sales.view_all|sales.view_own', 'icon' => 'M9 14l6-6m-5.5.5h.01m4.99 5h.01M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16l3.5-2 3.5 2 3.5-2 3.5 2z'],
    ];
    $customersNav = [
        ['label' => 'Customers', 'route' => 'customers.index', 'match' => 'customers.*', 'can' => 'customers.view', 'icon' => 'M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z'],
        ['label' => 'Customer payments', 'route' => 'customer-payments.index', 'match' => 'customer-payments.*', 'can' => 'customer_payments.view', 'icon' => 'M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'],
        ['label' => 'Who owes us', 'route' => 'receivables.index', 'match' => 'receivables.*', 'can' => 'reports.customers', 'icon' => 'M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z'],
    ];
    $serviceNav = [
        ['label' => 'Service jobs', 'route' => 'service.jobs.index', 'match' => 'service.jobs.*', 'can' => 'service_jobs.view_all|service_jobs.view_assigned', 'icon' => 'M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085m-1.745 1.437L5.909 7.5H4.5L2.25 3.75l1.5-1.5L7.5 4.5v1.409l4.26 4.26m-1.745 1.437l1.745-1.437m6.615 8.206L15.75 15.75M4.867 19.125h.008v.008h-.008v-.008z'],
        ['label' => 'Service types', 'route' => 'service.types.index', 'match' => 'service.types.*', 'can' => 'service_types.manage', 'icon' => 'M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z'],
    ];
    $groups = ['' => $nav, 'Selling' => $selling, 'Customers' => $customersNav, 'Service' => $serviceNav, 'Catalogue' => $catalogue, 'Inventory' => $inventoryNav, 'Purchasing' => $purchasing, 'Administration' => $admin];
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#ffffff">
    <title>{{ $title }} · {{ $shop }}</title>
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body x-data="{ menu: false }">
<div class="flex min-h-screen">
    {{-- Sidebar --}}
    <div x-show="menu" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden" @click="menu = false"></div>
    <aside class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full transform overflow-y-auto border-r border-slate-200/80 bg-white p-3 shadow-[var(--shadow-soft)] transition duration-300 ease-out lg:translate-x-0"
           :class="{ 'translate-x-0': menu }">
        <a href="{{ route('dashboard') }}" class="group mb-3 flex items-center gap-3 rounded-lg border-b border-slate-100 px-2 pb-4 pt-3 text-slate-800 hover:text-slate-800">
            <img src="{{ $logoUrl }}" alt="" class="h-12 w-12 shrink-0 rounded-full bg-white object-contain p-0.5 shadow-md ring-1 ring-slate-200 transition duration-300 group-hover:rotate-6 group-hover:scale-105">
            <span class="text-sm font-semibold leading-tight">{{ $shop }}<span class="mt-0.5 block text-[11px] font-normal text-slate-500">Point of sale &amp; service</span></span>
        </a>

        <nav class="space-y-0.5">
            @foreach($groups as $heading => $items)
                @if(collect($items)->contains(fn ($i) => $user->canAny(explode('|', $i['can']))))
                    @if($heading !== '')<div class="nav-heading">{{ $heading }}</div>@endif
                    @foreach($items as $item)
                        @if($user->canAny(explode('|', $item['can'])))
                            <a href="{{ route($item['route']) }}" class="nav-link {{ request()->routeIs($item['match']) ? 'active' : '' }}">
                                <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/></svg>
                                <span class="flex-1">{{ $item['label'] }}</span>
                                @if(! empty($item['alert']))
                                    <span class="h-2.5 w-2.5 rounded-full bg-red-500 ring-2 ring-red-500/30" title="Backup problem: open Backups"></span>
                                @endif
                            </a>
                        @endif
                    @endforeach
                @endif
            @endforeach
        </nav>
    </aside>

    {{-- Main --}}
    <div class="flex min-w-0 flex-1 flex-col lg:pl-64">
        <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-t-2 border-slate-200 border-t-brand-600 bg-white/80 px-4 shadow-sm backdrop-blur-md">
            <button type="button" class="rounded p-1.5 text-slate-600 hover:bg-slate-100 lg:hidden" @click="menu = true" aria-label="Open menu">
                <svg class="h-6 w-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
            <h1 class="truncate text-base font-semibold text-slate-800">{{ $title }}</h1>
            <div class="ml-auto flex items-center gap-3">
                @if($backupAlert)
                    <a href="{{ route('backups.index') }}" class="hidden items-center gap-1.5 rounded-full bg-red-100 px-3 py-1 text-xs font-semibold text-red-700 hover:bg-red-200 hover:text-red-800 sm:flex">
                        <span class="h-2 w-2 rounded-full bg-red-600"></span> Backup problem
                    </a>
                @endif
                <div class="relative" x-data="{ open: false }" @click.outside="open = false">
                    <button type="button" class="flex items-center gap-2 rounded-md px-2 py-1 text-left hover:bg-slate-100" @click="open = !open">
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 text-sm font-semibold text-brand-700">{{ \Illuminate\Support\Str::of($user->name)->substr(0, 1)->upper() }}</span>
                        <span class="hidden leading-tight sm:block">
                            <span class="block text-sm font-medium text-slate-800">{{ $user->name }}</span>
                            <span class="block text-xs text-slate-500">{{ $user->roleName() }}</span>
                        </span>
                    </button>
                    <div x-show="open" x-cloak x-transition class="absolute right-0 mt-1 w-52 rounded-md border border-slate-200 bg-white py-1 shadow-lg">
                        <a href="{{ route('password.change') }}" class="block px-4 py-2 text-sm text-slate-700 hover:bg-slate-50 hover:text-slate-900">Change my password</a>
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" data-no-loading class="block w-full px-4 py-2 text-left text-sm text-slate-700 hover:bg-slate-50">Log out</button>
                        </form>
                    </div>
                </div>
            </div>
        </header>

        <main class="flex-1 p-4 sm:p-6">
            @if($errors->has('general'))
                <div class="mb-4 rounded-md border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">{{ $errors->first('general') }}</div>
            @endif
            {{ $slot }}
        </main>

        <footer class="px-6 py-3 text-xs text-slate-400">{{ $shop }} · MR-POS v{{ config('mrpos.version') }}</footer>
    </div>
</div>

{{-- Toasts --}}
<div class="pointer-events-none fixed right-4 top-16 z-50 flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2" aria-live="polite">
    <template x-for="t in $store.ui.toasts" :key="t.id">
        <div class="pointer-events-auto flex items-start gap-2 rounded-md border px-4 py-3 text-sm shadow-lg"
             :class="t.type === 'error' ? 'border-red-200 bg-red-50 text-red-800' : 'border-emerald-200 bg-emerald-50 text-emerald-800'" x-transition>
            <span class="flex-1" x-text="t.message"></span>
            <button type="button" class="text-lg leading-none opacity-60 hover:opacity-100" @click="$store.ui.dismiss(t.id)" aria-label="Dismiss">&times;</button>
        </div>
    </template>
</div>

{{-- Confirmation dialog --}}
<div x-show="$store.ui.dialog.open" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/50 p-4" @keydown.escape.window="$store.ui.cancel()">
    <div class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl" @click.outside="$store.ui.cancel()" role="dialog" aria-modal="true">
        <p class="text-sm text-slate-700" x-text="$store.ui.dialog.message"></p>
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="btn btn-secondary" @click="$store.ui.cancel()">Cancel</button>
            <button type="button" class="btn" :class="$store.ui.dialog.danger ? 'btn-danger' : 'btn-primary'" x-text="$store.ui.dialog.label" @click="$store.ui.accept()"></button>
        </div>
    </div>
</div>

<script>
    document.addEventListener('alpine:init', () => {
        @if(session('success')) Alpine.store('ui').toast(@json(session('success')), 'success'); @endif
        @if(session('error')) Alpine.store('ui').toast(@json(session('error')), 'error'); @endif
    });
</script>
</body>
</html>
