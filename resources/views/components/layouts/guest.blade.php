@props(['title' => 'Sign in'])
@php
    $settings = app(\App\Services\SettingsService::class);
    $shop = $settings->string('shop.name') ?: config('app.name');
    $hasLogo = $settings->string('shop.logo_path') !== '';
    $logoUrl = $hasLogo ? route('branding.logo') : asset('brand/mr-logo.svg');
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
<body class="relative overflow-x-hidden bg-slate-50">
{{-- soft, slowly drifting colour behind the sign-in card (the red of the R and the grey of the M) --}}
<div aria-hidden="true" class="pointer-events-none fixed inset-0 overflow-hidden">
    <div class="absolute -left-24 -top-24 h-96 w-96 animate-drift rounded-full bg-brand-200/50 blur-3xl"></div>
    <div class="absolute -bottom-32 -right-24 h-[28rem] w-[28rem] animate-drift rounded-full bg-slate-300/50 blur-3xl [animation-delay:-6s]"></div>
</div>
<div class="relative flex min-h-screen flex-col items-center justify-center px-4 py-10">
    <div class="mb-6 flex animate-fade-up flex-col items-center text-center">
        <img src="{{ $logoUrl }}" alt="" class="mb-4 h-28 w-28 rounded-full bg-white object-contain p-1.5 shadow-xl ring-1 ring-slate-200">
        <h1 class="text-2xl font-bold tracking-tight text-slate-800">{{ $shop }}</h1>
        <p class="mt-1 text-sm text-slate-500">Sign in to continue</p>
    </div>
    <div class="w-full max-w-sm animate-fade-up rounded-2xl border border-slate-200/80 border-t-4 border-t-brand-600 bg-white p-6 shadow-[var(--shadow-lift)] [animation-delay:80ms]">
        {{ $slot }}
    </div>
    <p class="mt-6 text-xs text-slate-400">MR-POS v{{ config('mrpos.version') }}</p>
</div>
</body>
</html>
