<?php

namespace App\Providers;

use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerPayment;
use App\Models\HeldSale;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\StockAdjustment;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\SupplierPayment;
use App\Models\Unit;
use App\Models\User;
use App\Services\SettingsService;
use App\Support\PermissionCatalogue;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\Models\Role;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One settings instance per request keeps the in-memory cache effective.
        $this->app->singleton(SettingsService::class);
    }

    public function boot(): void
    {
        // Admin passes every permission check. Sensitive actions still need password re-confirmation,
        // and the "last active admin" protection still applies (see UserController).
        Gate::before(function ($user, string $ability) {
            return $user->hasRole(PermissionCatalogue::ADMIN) ? true : null;
        });

        // Short, stable names are stored in morph columns (audit_logs.auditable_type, ledgers later).
        // Not "enforced": an unregistered model must never make an audit write fail.
        Relation::morphMap([
            'user' => User::class,
            'setting' => Setting::class,
            'backup' => Backup::class,
            'backup_destination' => BackupDestination::class,
            'role' => Role::class,
            'product' => Product::class,
            'supplier' => Supplier::class,
            'category' => Category::class,
            'brand' => Brand::class,
            'unit' => Unit::class,
            'stock_adjustment' => StockAdjustment::class,
            'stock_movement' => StockMovement::class,
            'purchase' => Purchase::class,
            'supplier_payment' => SupplierPayment::class,
            'payment_method' => PaymentMethod::class,
            'sale' => Sale::class,
            'customer' => Customer::class,
            'customer_payment' => CustomerPayment::class,
            'held_sale' => HeldSale::class,
        ]);

        $this->applySessionTimeout();
    }

    /** Idle logout time comes from Settings → Security (applies to the next login). */
    private function applySessionTimeout(): void
    {
        if ($this->app->runningInConsole()) {
            return;
        }

        try {
            $minutes = $this->app->make(SettingsService::class)->int('security.session_idle_minutes');
            if ($minutes >= 5) {
                config(['session.lifetime' => $minutes]);
            }
        } catch (Throwable) {
            // settings table not there yet (fresh install): keep the .env value
        }
    }
}
