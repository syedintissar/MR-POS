<?php

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\BackupController;
use App\Http\Controllers\Admin\PaymentMethodController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordChangeController;
use App\Http\Controllers\Auth\PasswordConfirmController;
use App\Http\Controllers\CashBookController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\CustomerPaymentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LabelController;
use App\Http\Controllers\PosController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImportController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\ReceivablesController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\ServiceJobController;
use App\Http\Controllers\ServiceJobWorkController;
use App\Http\Controllers\ServiceTypeController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\SupplierCreditController;
use App\Http\Controllers\SupplierPaymentController;
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
    Route::middleware('can:suppliers.view')->group(function () {
        Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->whereNumber('supplier')->name('suppliers.show');
        Route::get('suppliers/{supplier}/statement', [SupplierController::class, 'statement'])->whereNumber('supplier')->name('suppliers.statement');
    });
    Route::post('suppliers/{supplier}/adjust-balance', [SupplierController::class, 'adjustBalance'])->whereNumber('supplier')->middleware(['can:suppliers.adjust_balance', 'password.confirm'])->name('suppliers.adjust-balance');

    // ---- Phase 5: the till, sales and invoices ---------------------------------------------------------------

    Route::prefix('pos')->middleware('can:pos.access')->group(function () {
        Route::get('/', [PosController::class, 'index'])->name('pos.index');
        Route::get('products/search', [PosController::class, 'search'])->name('pos.products.search');
        Route::get('products/quick', [PosController::class, 'quick'])->name('pos.products.quick');
        Route::post('sales', [PosController::class, 'store'])->middleware('can:sales.create')->name('pos.sales.store');
        Route::post('approvals', [PosController::class, 'approve'])->middleware('can:sales.create')->name('pos.approvals');
        Route::get('customers/search', [CustomerController::class, 'search'])->middleware('can:customers.view')->name('pos.customers.search');
        Route::post('customers', [CustomerController::class, 'quickStore'])->middleware('can:customers.create')->name('pos.customers.store');
        Route::get('held', [PosController::class, 'held'])->middleware('can:sales.hold')->name('pos.held.index');
        Route::post('held', [PosController::class, 'hold'])->middleware('can:sales.hold')->name('pos.held.store');
        Route::get('held/{held}', [PosController::class, 'resume'])->whereNumber('held')->middleware('can:sales.hold')->name('pos.held.show');
        Route::delete('held/{held}', [PosController::class, 'discard'])->whereNumber('held')->middleware('can:sales.hold')->name('pos.held.destroy');
    });

    Route::prefix('sales')->group(function () {
        Route::get('/', [SaleController::class, 'index'])->name('sales.index');
        Route::get('{sale}', [SaleController::class, 'show'])->whereNumber('sale')->name('sales.show');
        Route::get('{sale}/print/{format}', [SaleController::class, 'print'])->whereNumber('sale')->middleware('signed')->name('sales.print');
        Route::get('{sale}/reprint/{format}', [SaleController::class, 'reprint'])->whereNumber('sale')->name('sales.reprint');
        Route::get('{sale}/pdf', [SaleController::class, 'pdf'])->whereNumber('sale')->name('sales.pdf');
        Route::post('{sale}/void', [SaleController::class, 'void'])->whereNumber('sale')->middleware(['can:sales.void', 'password.confirm'])->name('sales.void');
    });

    // ---- Phase 6: customers, receivables and customer payments ------------------------------------------------

    Route::prefix('customers')->group(function () {
        Route::middleware('can:customers.view')->get('/', [CustomerController::class, 'index'])->name('customers.index');
        Route::middleware('can:customers.create')->group(function () {
            Route::get('create', [CustomerController::class, 'create'])->name('customers.create');
            Route::post('/', [CustomerController::class, 'store'])->name('customers.store');
        });
        Route::get('{customer}', [CustomerController::class, 'show'])->whereNumber('customer')->middleware('can:customers.view')->name('customers.show');
        Route::middleware('can:customers.edit')->group(function () {
            Route::get('{customer}/edit', [CustomerController::class, 'edit'])->whereNumber('customer')->name('customers.edit');
            Route::put('{customer}', [CustomerController::class, 'update'])->whereNumber('customer')->name('customers.update');
            Route::post('{customer}/toggle', [CustomerController::class, 'toggle'])->whereNumber('customer')->name('customers.toggle');
        });
        Route::delete('{customer}', [CustomerController::class, 'destroy'])->whereNumber('customer')->middleware(['can:customers.delete', 'password.confirm'])->name('customers.destroy');
        Route::middleware('can:customers.statement')->group(function () {
            Route::get('{customer}/statement', [CustomerController::class, 'statement'])->whereNumber('customer')->name('customers.statement');
            Route::get('{customer}/statement.pdf', [CustomerController::class, 'statementPdf'])->whereNumber('customer')->name('customers.statement.pdf');
        });
        Route::post('{customer}/adjust-balance', [CustomerController::class, 'adjustBalance'])->whereNumber('customer')->middleware(['can:customers.adjust_balance', 'password.confirm'])->name('customers.adjust-balance');
        Route::post('{customer}/apply-advance', [CustomerController::class, 'applyAdvance'])->whereNumber('customer')->middleware('can:customer_payments.create')->name('customers.apply-advance');
    });

    Route::prefix('customer-payments')->group(function () {
        Route::get('/', [CustomerPaymentController::class, 'index'])->middleware('can:customer_payments.view')->name('customer-payments.index');
        Route::middleware('can:customer_payments.create')->group(function () {
            Route::get('create', [CustomerPaymentController::class, 'create'])->name('customer-payments.create');
            Route::post('/', [CustomerPaymentController::class, 'store'])->name('customer-payments.store');
        });
        Route::get('{payment}', [CustomerPaymentController::class, 'show'])->whereNumber('payment')->middleware('can:customer_payments.view')->name('customer-payments.show');
        Route::post('{payment}/void', [CustomerPaymentController::class, 'void'])->whereNumber('payment')->middleware(['can:customer_payments.void', 'password.confirm'])->name('customer-payments.void');
        Route::get('{payment}/receipt', [CustomerPaymentController::class, 'receipt'])->whereNumber('payment')->middleware('signed')->name('customer-payments.receipt');
        Route::get('{payment}/reprint', [CustomerPaymentController::class, 'reprint'])->whereNumber('payment')->name('customer-payments.reprint');
    });

    Route::get('receivables', [ReceivablesController::class, 'index'])->middleware('can:reports.customers')->name('receivables.index');

    // ---- Phase 7: service, repair and refill jobs --------------------------------------------------------------
    // The routes check the permission; the controllers and actions also check that the person may see THAT job (a technician only sees their own).

    Route::prefix('service')->name('service.')->group(function () {
        Route::get('products/search', [ServiceJobController::class, 'productSearch'])->middleware('can:service_jobs.manage_parts')->name('products.search');
        Route::get('customers/search', [ServiceJobController::class, 'customerSearch'])->middleware('can:service_jobs.create')->name('customers.search');

        Route::prefix('jobs')->name('jobs.')->group(function () {
            Route::get('/', [ServiceJobController::class, 'index'])->name('index');
            Route::middleware('can:service_jobs.create')->group(function () {
                Route::get('create', [ServiceJobController::class, 'create'])->name('create');
                Route::post('/', [ServiceJobController::class, 'store'])->name('store');
            });
            Route::get('{job}', [ServiceJobController::class, 'show'])->whereNumber('job')->name('show');
            Route::get('{job}/edit', [ServiceJobController::class, 'edit'])->whereNumber('job')->middleware('can:service_jobs.edit')->name('edit');
            Route::put('{job}', [ServiceJobController::class, 'updateJob'])->whereNumber('job')->middleware('can:service_jobs.edit')->name('update');
            Route::post('{job}/assign', [ServiceJobController::class, 'assignTechnician'])->whereNumber('job')->middleware('can:service_jobs.assign')->name('assign');
            Route::post('{job}/status', [ServiceJobController::class, 'changeStatus'])->whereNumber('job')->middleware('can:service_jobs.change_status')->name('status');

            Route::post('{job}/parts', [ServiceJobWorkController::class, 'addPart'])->whereNumber('job')->middleware('can:service_jobs.manage_parts')->name('parts.add');
            Route::post('{job}/charges', [ServiceJobWorkController::class, 'addCharge'])->whereNumber('job')->middleware('can:service_jobs.set_charges')->name('charges.add');
            Route::post('{job}/pricing', [ServiceJobWorkController::class, 'updatePricing'])->whereNumber('job')->middleware('can:service_jobs.set_charges')->name('pricing');
            Route::post('{job}/lines/{item}/remove', [ServiceJobWorkController::class, 'removeLine'])->whereNumber(['job', 'item'])->name('lines.remove');   // the controller checks manage_parts (a part) or set_charges (a charge)
            Route::post('{job}/advance', [ServiceJobWorkController::class, 'takeAdvance'])->whereNumber('job')->middleware('can:customer_payments.create')->name('advance');
            Route::post('{job}/deliver', [ServiceJobWorkController::class, 'deliver'])->whereNumber('job')->middleware('can:service_jobs.deliver')->name('deliver');
            Route::post('{job}/cancel', [ServiceJobWorkController::class, 'cancelJob'])->whereNumber('job')->middleware('can:service_jobs.cancel')->name('cancel');

            // the original slip / invoice open through a signed link (ten minutes); later prints are reprints, marked DUPLICATE and recorded
            Route::get('{job}/slip/{format}', [ServiceJobController::class, 'slip'])->whereNumber('job')->middleware('signed')->name('slip');
            Route::get('{job}/slip/{format}/reprint', [ServiceJobController::class, 'reprintSlip'])->whereNumber('job')->name('slip.reprint');
            Route::get('{job}/invoice/{format}', [ServiceJobController::class, 'invoice'])->whereNumber('job')->middleware('signed')->name('invoice');
            Route::get('{job}/invoice/{format}/reprint', [ServiceJobController::class, 'reprintInvoice'])->whereNumber('job')->name('invoice.reprint');
            Route::get('{job}/invoice.pdf', [ServiceJobController::class, 'invoicePdf'])->whereNumber('job')->name('invoice.pdf');
        });

        Route::middleware('can:service_types.manage')->prefix('types')->name('types.')->group(function () {
            Route::get('/', [ServiceTypeController::class, 'index'])->name('index');
            Route::get('create', [ServiceTypeController::class, 'create'])->name('create');
            Route::post('/', [ServiceTypeController::class, 'store'])->name('store');
            Route::get('{type}/edit', [ServiceTypeController::class, 'edit'])->whereNumber('type')->name('edit');
            Route::put('{type}', [ServiceTypeController::class, 'update'])->whereNumber('type')->name('update');
            Route::post('{type}/toggle', [ServiceTypeController::class, 'toggle'])->whereNumber('type')->name('toggle');
        });
    });

    // ---- Phase 8: expenses, returns, profit and reports ----------------------------------------------------------

    Route::prefix('expenses')->name('expenses.')->group(function () {
        Route::get('/', [ExpenseController::class, 'index'])->middleware('can:expenses.view')->name('index');
        Route::middleware('can:expenses.create')->group(function () {
            Route::get('create', [ExpenseController::class, 'create'])->name('create');
            Route::post('/', [ExpenseController::class, 'store'])->name('store');
        });
        Route::prefix('categories')->name('categories')->group(function () {
            Route::get('/', [ExpenseController::class, 'categories'])->middleware('can:expenses.view')->name('');
            Route::middleware('can:expenses.edit')->group(function () {
                Route::post('/', [ExpenseController::class, 'storeCategory'])->name('.store');
                Route::put('{category}', [ExpenseController::class, 'updateCategory'])->whereNumber('category')->name('.update');
                Route::post('{category}/toggle', [ExpenseController::class, 'toggleCategory'])->whereNumber('category')->name('.toggle');
            });
        });
        Route::get('{expense}', [ExpenseController::class, 'show'])->whereNumber('expense')->middleware('can:expenses.view')->name('show');
        Route::get('{expense}/attachment', [ExpenseController::class, 'attachment'])->whereNumber('expense')->middleware('can:expenses.view')->name('attachment');
        Route::middleware('can:expenses.edit')->group(function () {
            Route::get('{expense}/edit', [ExpenseController::class, 'edit'])->whereNumber('expense')->name('edit');
            Route::put('{expense}', [ExpenseController::class, 'update'])->whereNumber('expense')->name('update');
        });
        Route::post('{expense}/void', [ExpenseController::class, 'voidExpense'])->whereNumber('expense')->middleware(['can:expenses.void', 'password.confirm'])->name('void');
    });

    Route::prefix('sale-returns')->name('sale-returns.')->group(function () {
        Route::get('/', [SaleReturnController::class, 'index'])->middleware('can:sale_returns.view')->name('index');
        Route::middleware('can:sale_returns.create')->group(function () {
            Route::get('create', [SaleReturnController::class, 'create'])->name('create');
            Route::post('sales/{sale}', [SaleReturnController::class, 'store'])->whereNumber('sale')->name('store');
            Route::get('products', [SaleReturnController::class, 'products'])->name('products');
            Route::post('sales/{sale}/exchange', [SaleReturnController::class, 'exchange'])->whereNumber('sale')->name('exchange');
        });
        Route::get('{saleReturn}', [SaleReturnController::class, 'show'])->whereNumber('saleReturn')->middleware('can:sale_returns.view')->name('show');
        Route::post('{saleReturn}/void', [SaleReturnController::class, 'voidReturn'])->whereNumber('saleReturn')->middleware(['can:sales.void', 'password.confirm'])->name('void');
        // the original receipt opens through a signed link (ten minutes); later prints are reprints, marked DUPLICATE and recorded
        Route::get('{saleReturn}/print', [SaleReturnController::class, 'print'])->whereNumber('saleReturn')->middleware('signed')->name('print');
        Route::get('{saleReturn}/reprint', [SaleReturnController::class, 'reprint'])->whereNumber('saleReturn')->name('reprint');
    });

    Route::prefix('purchase-returns')->name('purchase-returns.')->group(function () {
        Route::get('/', [PurchaseReturnController::class, 'index'])->middleware('can:purchase_returns.view')->name('index');
        Route::middleware('can:purchase_returns.create')->group(function () {
            Route::get('create', [PurchaseReturnController::class, 'create'])->name('create');
            Route::post('purchases/{purchase}', [PurchaseReturnController::class, 'store'])->whereNumber('purchase')->name('store');
            Route::post('free', [PurchaseReturnController::class, 'storeFree'])->name('store-free');
        });
        Route::get('{purchaseReturn}', [PurchaseReturnController::class, 'show'])->whereNumber('purchaseReturn')->middleware('can:purchase_returns.view')->name('show');
        Route::get('{purchaseReturn}/print', [PurchaseReturnController::class, 'print'])->whereNumber('purchaseReturn')->middleware('can:purchase_returns.view')->name('print');
        Route::post('{purchaseReturn}/void', [PurchaseReturnController::class, 'voidReturn'])->whereNumber('purchaseReturn')->middleware(['can:purchases.void', 'password.confirm'])->name('void');
    });

    // Reports: the controller checks the permission of each report (and cuts cost and profit columns out for people without theirs); exports need reports.export.
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('{report}', [ReportController::class, 'show'])->where('report', '[a-z\-]+')->name('show');
        Route::get('{report}/print', [ReportController::class, 'print'])->where('report', '[a-z\-]+')->name('print');
        Route::get('{report}/export/{format}', [ReportController::class, 'export'])->where('report', '[a-z\-]+')->whereIn('format', ['csv', 'xlsx', 'pdf'])->name('export');
    });

    // ---- Phase 11: cash & bank book, day closing -------------------------------------------------------------------

    Route::prefix('cash-book')->name('cashbook.')->group(function () {
        Route::middleware('can:cashbook.view')->group(function () {
            Route::get('/', [CashBookController::class, 'index'])->name('index');
            Route::get('accounts/{account}', [CashBookController::class, 'account'])->whereNumber('account')->name('accounts.show');
            Route::get('accounts/{account}/print', [CashBookController::class, 'printAccount'])->whereNumber('account')->name('accounts.print');
            Route::get('entries', [CashBookController::class, 'entries'])->name('entries.index');
            Route::get('entries/{entry}', [CashBookController::class, 'showEntry'])->whereNumber('entry')->name('entries.show');
            Route::get('closings', [CashBookController::class, 'closings'])->name('closings.index');
            Route::get('closings/{closing}', [CashBookController::class, 'showClosing'])->whereNumber('closing')->name('closings.show');
        });
        // the controller and the action check: transfers and owner money need cashbook.transact, an opening balance needs cashbook.manage
        Route::get('entries/create', [CashBookController::class, 'createEntry'])->name('entries.create');
        Route::post('entries', [CashBookController::class, 'storeEntry'])->name('entries.store');
        Route::post('entries/{entry}/void', [CashBookController::class, 'voidEntry'])->whereNumber('entry')->middleware(['can:cashbook.void', 'password.confirm'])->name('entries.void');

        Route::middleware('can:cashbook.close_day')->group(function () {
            Route::get('closings/create', [CashBookController::class, 'createClosing'])->name('closings.create');
            Route::post('closings', [CashBookController::class, 'storeClosing'])->name('closings.store');
        });
        Route::post('closings/{closing}/void', [CashBookController::class, 'voidClosing'])->whereNumber('closing')->middleware(['can:cashbook.void', 'password.confirm'])->name('closings.void');

        Route::middleware('can:cashbook.manage')->group(function () {
            Route::get('setup', [CashBookController::class, 'setup'])->name('setup');
            Route::post('accounts', [CashBookController::class, 'storeAccount'])->name('accounts.store');
            Route::put('accounts/{account}', [CashBookController::class, 'updateAccount'])->whereNumber('account')->name('accounts.update');
            Route::post('accounts/{account}/toggle', [CashBookController::class, 'toggleAccount'])->whereNumber('account')->name('accounts.toggle');
            Route::put('methods/{method}', [CashBookController::class, 'linkMethod'])->whereNumber('method')->middleware('password.confirm')->name('methods.link');
            Route::put('people/{user}/drawer', [CashBookController::class, 'assignDrawer'])->whereNumber('user')->name('people.drawer');
        });

        // bank statement matching
        Route::middleware('can:cashbook.view')->group(function () {
            Route::get('statements', [CashBookController::class, 'reconciliations'])->name('reconciliations.index');
            Route::get('statements/{reconciliation}', [CashBookController::class, 'showReconciliation'])->whereNumber('reconciliation')->name('reconciliations.show');
        });
        Route::middleware('can:cashbook.reconcile')->group(function () {
            Route::post('statements', [CashBookController::class, 'storeReconciliation'])->name('reconciliations.store');
            Route::put('statements/{reconciliation}', [CashBookController::class, 'saveReconciliation'])->whereNumber('reconciliation')->name('reconciliations.save');
        });
        Route::post('statements/{reconciliation}/void', [CashBookController::class, 'voidReconciliation'])->whereNumber('reconciliation')->middleware(['can:cashbook.void', 'password.confirm'])->name('reconciliations.void');
    });

    // Supplier credit (left over from goods sent back): use it on bills, or take a use back
    Route::post('suppliers/{supplier}/use-credit', [SupplierCreditController::class, 'applyToSupplier'])->whereNumber('supplier')->middleware('can:supplier_payments.create')->name('suppliers.use-credit');
    Route::post('purchases/{purchase}/use-credit', [SupplierCreditController::class, 'applyToBill'])->whereNumber('purchase')->middleware('can:supplier_payments.create')->name('purchases.use-credit');
    Route::post('supplier-credit/{application}/void', [SupplierCreditController::class, 'voidUse'])->whereNumber('application')->middleware(['can:supplier_payments.void', 'password.confirm'])->name('supplier-credit.void');

    // ---- Phase 4: purchases and supplier accounts -----------------------------------------------------------

    Route::prefix('purchases')->group(function () {
        Route::middleware('can:purchases.view')->group(function () {
            Route::get('/', [PurchaseController::class, 'index'])->name('purchases.index');
            Route::get('lookup', [PurchaseController::class, 'lookup'])->name('purchases.lookup');
        });
        Route::middleware('can:purchases.create')->group(function () {
            Route::get('create', [PurchaseController::class, 'create'])->name('purchases.create');
            Route::post('/', [PurchaseController::class, 'store'])->name('purchases.store');
            Route::get('{purchase}/edit', [PurchaseController::class, 'edit'])->whereNumber('purchase')->name('purchases.edit');
            Route::put('{purchase}', [PurchaseController::class, 'update'])->whereNumber('purchase')->name('purchases.update');
            Route::delete('{purchase}', [PurchaseController::class, 'destroy'])->whereNumber('purchase')->name('purchases.destroy');
        });
        Route::get('{purchase}', [PurchaseController::class, 'show'])->whereNumber('purchase')->middleware('can:purchases.view')->name('purchases.show');
        Route::get('{purchase}/print', [PurchaseController::class, 'print'])->whereNumber('purchase')->middleware('can:purchases.view')->name('purchases.print');
        Route::post('{purchase}/receive', [PurchaseController::class, 'receive'])->whereNumber('purchase')->middleware('can:purchases.receive')->name('purchases.receive');
        Route::post('{purchase}/void', [PurchaseController::class, 'void'])->whereNumber('purchase')->middleware(['can:purchases.void', 'password.confirm'])->name('purchases.void');
    });

    Route::prefix('supplier-payments')->group(function () {
        Route::get('/', [SupplierPaymentController::class, 'index'])->middleware('can:supplier_payments.view')->name('supplier-payments.index');
        Route::middleware('can:supplier_payments.create')->group(function () {
            Route::get('create', [SupplierPaymentController::class, 'create'])->name('supplier-payments.create');
            Route::post('/', [SupplierPaymentController::class, 'store'])->name('supplier-payments.store');
            Route::post('{payment}/allocate', [SupplierPaymentController::class, 'allocate'])->whereNumber('payment')->name('supplier-payments.allocate');
        });
        Route::get('{payment}', [SupplierPaymentController::class, 'show'])->whereNumber('payment')->middleware('can:supplier_payments.view')->name('supplier-payments.show');
        Route::post('{payment}/void', [SupplierPaymentController::class, 'void'])->whereNumber('payment')->middleware(['can:supplier_payments.void', 'password.confirm'])->name('supplier-payments.void');
    });
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
            Route::post('payment-methods', [PaymentMethodController::class, 'store'])->name('payment-methods.store');
            Route::put('payment-methods/{method}', [PaymentMethodController::class, 'update'])->whereNumber('method')->name('payment-methods.update');
            Route::post('payment-methods/{method}/toggle', [PaymentMethodController::class, 'toggle'])->whereNumber('method')->name('payment-methods.toggle');
            Route::delete('payment-methods/{method}', [PaymentMethodController::class, 'destroy'])->whereNumber('method')->name('payment-methods.destroy');
        });

        // Backups
        Route::middleware('can:backups.view')->group(function () {
            Route::get('backups', [BackupController::class, 'index'])->name('backups.index');
        });
        Route::post('backups', [BackupController::class, 'store'])->middleware('can:backups.create')->name('backups.store');
        Route::post('backups/upload', [BackupController::class, 'upload'])->middleware(['can:backups.create', 'password.confirm'])->name('backups.upload');
        Route::get('backups/recovery-kit', [BackupController::class, 'recoveryKit'])->middleware(['can:backups.settings', 'password.confirm'])->name('backups.recovery-kit');
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
