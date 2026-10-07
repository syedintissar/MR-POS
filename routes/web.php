<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Auth\PasswordConfirmController;
use App\Http\Controllers\DashboardController;
use Illuminate\Support\Facades\Route;

// Public: shop logo (login page and printed receipts).
Route::get('/branding/logo', [SettingsController::class, 'logo'])->name('branding.logo');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])->name('login.store');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('/', DashboardController::class)->middleware('can:dashboard.view')->name('dashboard');

    // Own password
    Route::get('/password/change', [PasswordChangeController::class, 'edit'])->name('password.change');
    Route::put('/password/change', [PasswordChangeController::class, 'update'])->name('password.update');

    // "Type your password again" before sensitive actions (middleware alias: password.confirm)
    Route::get('/confirm-password', [PasswordConfirmController::class, 'show'])->name('password.confirm');
    Route::post('/confirm-password', [PasswordConfirmController::class, 'store'])->middleware('throttle:10,1');

    Route::prefix('admin')->group(function () {
        // Users
        Route::middleware('can:users.view')->group(function () {
            Route::get('users', [UserController::class, 'index'])->name('users.index');
        });
        Route::middleware('can:users.manage')->group(function () {
            Route::get('users/create', [UserController::class, 'create'])->name('users.create');
            Route::post('users', [UserController::class, 'store'])->name('users.store');
            Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::post('users/{user}/reset-password', [UserController::class, 'resetPassword'])->middleware('password.confirm')->name('users.reset-password');
        });

        // Roles & permissions
        Route::middleware('can:roles.manage')->group(function () {
            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
            Route::post('roles', [RoleController::class, 'store'])->middleware('password.confirm')->name('roles.store');
            Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
            Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('password.confirm')->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('password.confirm')->name('roles.destroy');
        });

        // Settings
        Route::middleware('can:settings.view')->get('settings/{group?}', [SettingsController::class, 'index'])->defaults('group', 'shop')->name('settings.index');
        Route::middleware('can:settings.manage')->group(function () {
            Route::put('settings-numbering', [SettingsController::class, 'updateNumbering'])->name('settings.numbering');
            Route::put('settings/{group}', [SettingsController::class, 'update'])->name('settings.update');
        });

        // Backups
        Route::middleware('can:backups.view')->group(function () {
            Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
        });
        Route::post('backups', [BackupController::class, 'store'])->middleware('can:backups.create')->name('backups.store');
        Route::post('backups/retry-transfers', [BackupController::class, 'retryTransfers'])->middleware('can:backups.create')->name('backups.retry-transfers');
        Route::put('backups/settings', [BackupController::class, 'updateSettings'])->middleware(['can:backups.settings', 'password.confirm'])->name('backups.settings');
        Route::post('backups/destinations', [BackupController::class, 'storeDestination'])->middleware(['can:backups.settings', 'password.confirm'])->name('backups.destinations.store');
        Route::post('backups/destinations/{destination}/toggle', [BackupController::class, 'toggleDestination'])->middleware('can:backups.settings')->name('backups.destinations.toggle');
        Route::post('backups/destinations/{destination}/test', [BackupController::class, 'testDestination'])->middleware('can:backups.settings')->name('backups.destinations.test');
        Route::post('backups/{backup}/verify', [BackupController::class, 'verify'])->middleware('can:backups.verify')->name('backups.verify');
        Route::get('backups/{backup}/download', [BackupController::class, 'download'])->middleware(['can:backups.download', 'password.confirm'])->name('backups.download');
        Route::post('backups/{backup}/protect', [BackupController::class, 'protect'])->middleware('can:backups.settings')->name('backups.protect');
        Route::delete('backups/{backup}', [BackupController::class, 'destroy'])->middleware(['can:backups.delete', 'password.confirm'])->name('backups.destroy');

        // Audit log
        Route::middleware('can:audit_logs.view')->group(function () {
            Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
            Route::post('audit-logs/verify', [AuditLogController::class, 'verify'])->name('audit.verify');
        });
    });
});
