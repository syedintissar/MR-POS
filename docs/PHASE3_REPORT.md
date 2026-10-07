# Phase 3 Report: Products, Catalogue & Inventory

**Date:** 2026-10-07 · **Status:** complete and validated · **Waiting for:** owner approval before Phase 4 (Purchases and supplier accounts)

Phase 2 was committed as a checkpoint before this phase started (`0c130e8`). Phase 3 is **not yet committed**: review it with `git status` and `git diff`, or tell me to commit it.

---

## 1. What was implemented

**Catalogue**
- **Categories** (two levels, e.g. *Printer Parts › Drums*), **brands** and **units** with inline editing. Starter lists match your shop: Printers, Toners, Cartridges, Ink, Printer Parts (Drums, Blades, Rollers, Fusers, Other parts), Cables, Computer Accessories, Stationery, Services, Other; 11 common printer brands; 10 units (pieces, box, pack, set, ream, metre, gram, kg, ml, litre). Units such as gram and ml accept decimals, so toner powder can be tracked as 1,500.5 g.
- Anything in use cannot be deleted (hide it instead). A unit cannot stop allowing decimals once products with stock history use it.

**Products**
- Full product record: stock item or service, name, SKU (auto-numbered `P-000001` when left blank), barcode, category, brand, model, description, unit, sale / wholesale / lowest-allowed price, tax rate override, low-stock level, shelf, usual supplier, warranty, picture, active flag.
- Search by name, SKU, barcode or model (an exact SKU or barcode comes first), filters for category (including its subcategories), brand, stock level and status.
- **Price changes need their own permission and are audited** (old and new price). People without it see read-only prices and the server ignores anything they post.
- **Cost and profit are visible to Managers and Admins only.** Cashiers and technicians never see average cost, margin, stock value or unit costs on any screen, label, list or lookup (tested on nine screens from both sides).
- Products with stock history cannot be deleted (deactivate instead) and their unit and kind cannot change. SKUs and barcodes must be plain ASCII and unique (also against deleted products and against each other), so a scanner can never pick the wrong item.
- Pictures are re-encoded as small JPEGs, stored privately (included in every backup) and served only to logged-in users.

**Inventory**
- **Stock ledger** (`stock_movements`): every change of stock, with before, change, after, unit cost, who and which document. Append-only: the database itself refuses edits and deletes, and refuses a row whose balance does not add up.
- **`InventoryService` is the only code that changes stock.** It locks the product row, refuses negative stock unless an administrator allows it, and keeps the weighted-average cost (purchase, return and void rules per ARCHITECTURE §6.4).
- **Stock adjustments** with reasons (opening stock, count correction, damage, loss, found, expiry, other): all lines post together or not at all, numbered `ADJ-000001`, audited; cancelling (administrators, with reason and password) writes the opposite movements and also reverses the effect on average cost.
- **Stock overview** (low / out / below zero, value for managers), **stock card** per product and a full ledger with filters, **dashboard alerts** for products to re-order, and a **twice-daily integrity check** that reports any product whose stock total disagrees with its ledger (it never changes anything silently).
- **Import from Excel or CSV** with a downloadable template: checked preview, every problem listed by row, nothing saved unless the whole file is valid, opening stock posted as one document at your costs.
- **Barcode labels** (Code 128) in three sizes (50×30 mm, 38×25 mm, A4 3×8 sheet), printed from the browser, optionally with the price.
- **Suppliers**: master data with automatic codes (`SUP-000001`). Balances stay read-only until Phase 4 builds the supplier ledger.

## 2. Files created / modified
86 new or changed files: about 5,000 lines of application code, views and migrations and 3,200 lines of tests.

| Area | Files | Highlights |
|---|---|---|
| `app/Services/Inventory` | 4 | `InventoryService`, `CostingService`, `ProductImporter`, `ProductImageService` |
| `app/Actions` | 3 | `PostStockAdjustment`, `VoidStockAdjustment`, `SaveProduct` |
| `app/Http/Controllers` | 8 | Catalog, Product, ProductImport, Inventory, StockAdjustment, Label, Supplier (+ Dashboard) |
| `app/Models`, `app/Support`, `app/Exceptions` | 10 | 8 models, `Decimal` (exact arithmetic), `InsufficientStockException` |
| `app/Console/Commands` | 1 | `pos:integrity:check` |
| `database/` | 12 | 4 migrations, 5 factories, catalogue seeder |
| `resources/views` | 16 | catalogue, products (list, form, detail, import), stock, ledger, adjustments, labels (screen + print), suppliers |
| `routes/` | 2 | all new routes protected by permission middleware; integrity check scheduled |
| `tests/` | 20 | see §6 |
| `docs/` | 6 | new: this report. Updated: `DATABASE`, `DESIGN_REVIEW`, `ROADMAP`, `SRS`, `USER_GUIDE`, `README` |
| Packages | 2 | `openspout/openspout` (Excel files), `picqer/php-barcode-generator` (barcodes). Both work offline |

## 3. Database changes
Four migrations, eight tables (29 tables in total now):

| Migration | Tables |
|---|---|
| `2026_10_07_200000_create_catalogue_tables` | `categories`, `brands`, `units` |
| `2026_10_07_200100_create_suppliers_table` | `suppliers` |
| `2026_10_07_200200_create_products_table` | `products` (with check constraints: kind, prices and costs not negative, a service has no stock) |
| `2026_10_07_200300_create_inventory_tables` | `stock_adjustments`, `stock_adjustment_items`, `stock_movements` (append-only triggers; the balance and the type are check constraints) |

Seed data: 15 categories, 10 units, 11 brands, and a new `product` document sequence. Details and the few places where the build differs from the Phase 1 design (for example categories, brands and units are deleted for real when unused, not soft-deleted) are in [DATABASE.md §9](DATABASE.md).

The real `mrpos` database was **backed up first, then migrated** (backup `mrpos_2026-10-07_121950_manual.zip`). After the drills it was reset to a clean install again: 0 users, 0 products, 0 movements, reference data loaded.

## 4. Commands you need to run
Nothing new is required for Phase 3 itself; the migration is already applied to the real database. To use it:

1. **If you have not yet done the Phase 2 steps**, do those first (they are still pending): `install-mariadb-service.ps1` and `install-scheduler.ps1 -RunAsSystem` as Administrator, restart Apache, then `php artisan pos:install` to create your administrator. The twice-daily stock check and automatic backups depend on the scheduler.
2. Open **Products → Import**, download the template, put your product list in it and import (or add products one by one).
3. Open **Inventory → Adjust stock** (reason *Opening stock*) for anything not already given an opening quantity in the import.
4. Optional: **Barcode labels** for shelf stickers.

For future updates: `php artisan migrate --force` and `npm run build` after copying a new release.

## 5. Manual setup needed from you
| What | Why |
|---|---|
| The Phase 2 Administrator steps (above) | MariaDB 11.4 still only runs as a hand-started process, so a PC restart stops it; the scheduler does not run yet |
| Your product / stock / price list in Excel | To import at go-live. Any columns you have are fine; the template shows the names the import understands |
| Decide how products get barcodes | Existing manufacturer barcodes can be scanned into the barcode box. Products without one print a label from their SKU (Code 128) |
| Printer paper size for labels | The label page tells you which paper size and margins to set |

## 6. Tests and checks performed

**Automated: 459 tests, 2,515 assertions, all passing** (about 4.5 minutes; 253 of them are new in this phase) (PHPUnit against real MariaDB 11.4). Code style (Pint) passes. Highlights of what is proven:

| Area | What is proven |
|---|---|
| Exact arithmetic | `Decimal` never uses floats: 0.1 + 0.2 = 0.3, half-up rounding, no negative zero, bad input refused |
| Costing | Weighted average on purchase, return and void, including negative balances, fractions and round-trips |
| Stock ledger | Every movement type and direction rule; refused when stock would go negative (and allowed only when the administrator turns it on); whole-number units reject fractions; service items have no stock; the ledger is append-only in the database *and* the model; the database rejects a row whose balance does not add up; the cached stock always equals the ledger and any drift is detected |
| **Real concurrency** | Separate PHP processes hit the same product at once: of four buyers of the last unit exactly one succeeds; no overselling with several buyers; six adjustments touching two products in opposite order never deadlock. A **mutation check** (row lock removed on purpose) made two buyers "succeed" and caused deadlocks, so the test genuinely detects the problem |
| Adjustments | Numbered, linked to every movement, audited; one bad line cancels the whole document and returns its number; opening stock only adds; void reverses stock *and* average cost, refused when the stock is gone, only once, reason required |
| Products | Create, edit, validation, SKU auto-numbering that skips hand-typed numbers, barcode/SKU collisions, ASCII rule, price-change permission and audit, unit and kind locked after history, deactivate, delete rules (password; history blocks it), picture handling, search and filters, lookup JSON |
| **Cost secrecy** | Cashier and technician sessions see no cost, margin, stock value or unit cost on nine screens; a cost forged into an adjustment by a user who may not see costs is ignored; manager and admin see them |
| Import | Valid CSV and XLSX; one bad row blocks everything with every problem listed by row; Excel quirks (byte-order mark, semicolons, `Rs. 1,200`, header aliases); new categories and brands announced and created once; all-or-nothing even when the save fails halfway; data re-checked at commit; no replay; permissions |
| Screens | Stock overview filters and value, stock card running balance, adjustment form (including refusal messages that keep what was typed), labels (sizes, price on/off, unreadable codes skipped, 1,000 limit), suppliers, dashboard alerts, integrity check |
| Permissions | Every new route against every default role (111 cases) and each Phase 3 permission opens exactly its own door |
| Quality gates | Every Blade view is compiled and parse-checked; the form's client-side logic was exercised under Node (14 checks) |

**Checks on the real system (not only tests):** real backup before migrating; migration on the real database; 13 new pages over HTTP with no errors; **the template imported through the real upload form** (3 products, 12 pcs of toner at Rs. 3,200, 1,500.5 g of powder, one opening-stock document); a product created with a picture (re-saved as JPEG, served back); a stock adjustment; the oversell guard refusing to remove 99 of 10; barcode labels with no cost on them; a **real cashier login saw no cost or margin on 8 pages and got 403 for creating, importing and adjusting**; a real backup with **deep verification (29 tables restored into the scratch database, row counts match)**; and a **restore drill** (toner stock 5 after a "bad day" came back to 10, the junk product vanished, the integrity check and the ledger agree, the append-only triggers were recreated and still refuse edits).

**Real bugs found and fixed during Phase 3 (details in [DESIGN_REVIEW.md §6](DESIGN_REVIEW.md)):** Excel's byte-order mark hid the quoted first header; a template mistake swallowed half of the products page (Blade pairs the first `@php` with the first `@endphp`); users without the price permission could not save at all; accented barcodes became unreadable labels; a flaky comparison in my concurrency test; and a test I wrote that left the environment altered for later tests.

## 7. Remaining issues and decisions before Phase 4

| # | Item | Needed from you |
|---|---|---|
| 1 | **Approval of Phase 3** | Yes / changes. Also say whether to **commit** it |
| 2 | **Phase 2 admin steps** are still pending (MariaDB service, scheduler, Apache restart, `pos:install`) | Run them when convenient; nothing in Phase 4 can be used day-to-day without a real administrator |
| 3 | **Existing supplier balances** | Do you owe suppliers money today? If so, send each supplier and the amount: Phase 4 enters them as opening balances so the ledger starts correct |
| 4 | **Purchases:** supplier invoice numbers | Default: the same supplier invoice number cannot be entered twice. OK? |
| 5 | **Freight / extra costs** on a purchase | Default: added to the cost of the goods in proportion to their value, so the average cost is the true landed cost. OK? |
| 6 | **Payment methods** | Default list: Cash, Bank transfer, Card, Easypaisa, JazzCash, Cheque. Anything to add or remove? |
| 7 | **Purchase tax** | You are not registered for sales tax, so purchase tax is added to cost (the default setting). Confirm |
| 8 | Old MariaDB 10.4 on port 3306 (no root password, open to the network; other projects use it) | Still undecided: leave and secure it, or move those projects to 11.4 |

Known limits (deliberate, tracked): there is no stock *reservation* for held POS carts (the POS re-checks stock when the sale is completed); a dedicated stock-count sheet is a later nicety (the adjustment form covers counts); purchases, sales and repair parts start using the ledger in Phases 4, 5 and 7.
