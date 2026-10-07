@props(['title' => 'Dashboard'])
@php
    $settings = app(\App\Services\SettingsService::class);
    $shop = $settings->string('shop.name') ?: config('app.name');
    $user = auth()->user();
    $hasLogo = $settings->string('shop.logo_path') !== '';

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
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · {{ $shop }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body x-data="{ menu: false }">
<div class="flex min-h-screen">
    {{-- Sidebar --}}
    <div x-show="menu" x-cloak x-transition.opacity class="fixed inset-0 z-30 bg-slate-900/50 lg:hidden" @click="menu = false"></div>
    <aside class="fixed inset-y-0 left-0 z-40 w-64 -translate-x-full transform overflow-y-auto bg-slate-900 p-3 transition lg:translate-x-0"
           :class="{ 'translate-x-0': menu }">
        <a href="{{ route('dashboard') }}" class="mb-2 flex items-center gap-2.5 rounded-md px-2 py-3 text-white hover:text-white">
            @if($hasLogo)
                <img src="{{ route('branding.logo') }}" alt="" class="h-9 w-9 rounded bg-white object-contain p-0.5">
            @else
                <span class="flex h-9 w-9 items-center justify-center rounded bg-brand-600 text-sm font-bold">{{ \Illuminate\Support\Str::of($shop)->substr(0, 1)->upper() }}</span>
            @endif
            <span class="text-sm font-semibold leading-tight">{{ $shop }}</span>
        </a>

        <nav class="space-y-0.5">
            @foreach($nav as $item)
                @can($item['can'])
                    <a href="{{ route($item['route']) }}" class="nav-link {{ request()->routeIs($item['match']) ? 'active' : '' }}">
                        <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/></svg>
                        {{ $item['label'] }}
                    </a>
                @endcan
            @endforeach

            @if(collect($admin)->contains(fn ($i) => $user->can($i['can'])))
                <div class="nav-heading">Administration</div>
                @foreach($admin as $item)
                    @can($item['can'])
                        <a href="{{ route($item['route']) }}" class="nav-link {{ request()->routeIs($item['match']) ? 'active' : '' }}">
                            <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" stroke-width="1.7" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="{{ $item['icon'] }}"/></svg>
                            <span class="flex-1">{{ $item['label'] }}</span>
                            @if(! empty($item['alert']))
                                <span class="h-2.5 w-2.5 rounded-full bg-red-500 ring-2 ring-red-500/30" title="Backup problem: open Backups"></span>
                            @endif
                        </a>
                    @endcan
                @endforeach
            @endif
        </nav>
    </aside>

    {{-- Main --}}
    <div class="flex min-w-0 flex-1 flex-col lg:pl-64">
        <header class="sticky top-0 z-20 flex h-14 items-center gap-3 border-b border-slate-200 bg-white px-4 shadow-sm">
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
