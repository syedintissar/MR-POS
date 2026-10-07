@props(['title' => 'Sign in'])
@php
    $settings = app(\App\Services\SettingsService::class);
    $shop = $settings->string('shop.name') ?: config('app.name');
    $hasLogo = $settings->string('shop.logo_path') !== '';
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
<body class="bg-slate-900">
<div class="flex min-h-screen flex-col items-center justify-center px-4 py-10">
    <div class="mb-6 flex flex-col items-center text-center">
        @if($hasLogo)
            <img src="{{ route('branding.logo') }}" alt="" class="mb-3 h-16 w-16 rounded-lg bg-white object-contain p-1">
        @endif
        <h1 class="text-2xl font-bold tracking-tight text-white">{{ $shop }}</h1>
    </div>
    <div class="w-full max-w-sm rounded-lg bg-white p-6 shadow-xl">
        {{ $slot }}
    </div>
    <p class="mt-6 text-xs text-slate-500">MR-POS v{{ config('mrpos.version') }}</p>
</div>
</body>
</html>
