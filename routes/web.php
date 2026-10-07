<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Auth\PasswordConfirmController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\SupplierController;
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

    // ---- Phase 3: catalogue, products, inventory, suppliers -------------------------------------------------

    Route::middleware('can:products.view')->get('catalog/{tab?}', [CatalogController::class, 'index'])->defaults('tab', 'categories')->name('catalog.index');
    Route::middleware('can:catalog.manage')->group(function () {
        Route::post('catalog/{tab}', [CatalogController::class, 'store'])->name('catalog.store');
        Route::put('catalog/{tab}/{id}', [CatalogController::class, 'update'])->whereNumber('id')->name('catalog.update');
        Route::delete('catalog/{tab}/{id}', [CatalogController::class, 'destroy'])->whereNumber('id')->name('catalog.destroy');
    });

    Route::middleware('can:products.view')->group(function () {
        Route::get('products', [ProductController::class, 'index'])->name('products.index');
        Route::get('products/lookup', [ProductController::class, 'lookup'])->name('products.lookup');
    });
    Route::middleware('can:products.create')->group(function () {
        Route::get('products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('products', [ProductController::class, 'store'])->name('products.store');
    });
    Route::middleware('can:products.import')->prefix('products-import')->group(function () {
        Route::get('/', [ProductImportController::class, 'index'])->name('products.import');
        Route::get('template', [ProductImportController::class, 'template'])->name('products.import.template');
        Route::post('preview', [ProductImportController::class, 'preview'])->name('products.import.preview');
        Route::post('commit', [ProductImportController::class, 'commit'])->name('products.import.commit');
        Route::post('cancel', [ProductImportController::class, 'cancel'])->name('products.import.cancel');
    });
    Route::get('products/{product}', [ProductController::class, 'show'])->whereNumber('product')->middleware('can:products.view')->name('products.show');
    Route::get('products/{product}/image', [ProductController::class, 'image'])->whereNumber('product')->middleware('can:products.view')->name('products.image');
    Route::get('products/{product}/edit', [ProductController::class, 'edit'])->whereNumber('product')->middleware('can:products.edit')->name('products.edit');
    Route::put('products/{product}', [ProductController::class, 'update'])->whereNumber('product')->middleware('can:products.edit')->name('products.update');
    Route::post('products/{product}/toggle', [ProductController::class, 'toggle'])->whereNumber('product')->middleware('can:products.edit')->name('products.toggle');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->whereNumber('product')->middleware(['can:products.delete', 'password.confirm'])->name('products.destroy');

    Route::prefix('inventory')->group(function () {
        Route::middleware('can:inventory.view')->group(function () {
            Route::get('/', [InventoryController::class, 'stock'])->name('inventory.stock');
            Route::get('movements', [InventoryController::class, 'movements'])->name('inventory.movements');
            Route::get('adjustments', [StockAdjustmentController::class, 'index'])->name('adjustments.index');
        });
        Route::middleware('can:inventory.adjust')->group(function () {
            Route::get('adjustments/create', [StockAdjustmentController::class, 'create'])->name('adjustments.create');
            Route::post('adjustments', [StockAdjustmentController::class, 'store'])->name('adjustments.store');
        });
        Route::get('adjustments/{adjustment}', [StockAdjustmentController::class, 'show'])->whereNumber('adjustment')->middleware('can:inventory.view')->name('adjustments.show');
        Route::post('adjustments/{adjustment}/void', [StockAdjustmentController::class, 'void'])->whereNumber('adjustment')->middleware(['can:inventory.void_adjustment', 'password.confirm'])->name('adjustments.void');

        Route::middleware('can:products.view')->group(function () {
            Route::get('labels', [LabelController::class, 'index'])->name('labels.index');
            Route::get('labels/print', [LabelController::class, 'print'])->name('labels.print');
        });
    });

    Route::middleware('can:suppliers.view')->get('suppliers', [SupplierController::class, 'index'])->name('suppliers.index');
    Route::middleware('can:suppliers.create')->group(function () {
        Route::get('suppliers/create', [SupplierController::class, 'create'])->name('suppliers.create');
        Route::post('suppliers', [SupplierController::class, 'store'])->name('suppliers.store');
    });
    Route::middleware('can:suppliers.edit')->group(function () {
        Route::get('suppliers/{supplier}/edit', [SupplierController::class, 'edit'])->whereNumber('supplier')->name('suppliers.edit');
        Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->whereNumber('supplier')->name('suppliers.update');
        Route::post('suppliers/{supplier}/toggle', [SupplierController::class, 'toggle'])->whereNumber('supplier')->name('suppliers.toggle');
    });
    Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->whereNumber('supplier')->middleware(['can:suppliers.delete', 'password.confirm'])->name('suppliers.destroy');
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
