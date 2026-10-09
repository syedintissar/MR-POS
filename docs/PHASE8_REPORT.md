# Phase 8 Report: Expenses, Returns, Profit & Reports

**Date:** 2026-10-09 · **Status:** complete and validated · **Waiting for:** owner approval before Phase 9 (backup / restore and audit completion)

Phases 1 to 7 are committed (the latest is `80ad4a8`, Phase 7). Phase 8 is **not yet committed**: review it with `git status` and `git diff`, or tell me to commit it.

**You asked that service charges show in profit: they do.** The profit engine counts every delivered service job (charges *and* parts, without tax, after the job discount) on the day it was delivered and invoiced, together with the cost of the parts it used; it also shows parts that were written off. A labour-only job is pure profit. This is proved by a test with a shop whose every number is worked out by hand (§6).

---

## 1. What was implemented

**Expenses (Money → Expenses)**
- Nine categories (Rent, Electricity, Salary, Internet, Transport, Maintenance, Tea / Refreshments, Office expense, Miscellaneous) plus your own. An expense has a number (`EXP-000001…`), date (never in the future), amount, how it was paid, who was paid, what it was for, and an optional **bill** (photo or PDF up to 5 MB, kept privately, opened only by people who may see expenses, every opening audited).
- Cashiers record expenses and see the list. **Managers correct an expense only on the day it was entered** (old and new values audited) and cancel it afterwards (reason and password); a cancelled expense no longer counts anywhere.

**Sales returns (Sales returns → Take goods back, or "Return items" on an invoice)**
- Always against an invoice: lines and quantities (never more than sold less already returned), the **condition** (resellable / damaged / defective) and **"put back in stock"** (default for resellable; a damaged line is written off at its cost, a service item has no stock). A serial number and the state of the warranty are shown.
- **The value is what the customer really paid** for those units, after the line discount and the invoice discount, plus their tax; returning the last units of a line takes exactly what is left, so no cent is lost.
- **Settlement:** first what the customer still owes on that invoice goes down; the rest is **money back** (cash, bank or wallet; needs `sale_returns.refund`) or **store credit** (named customers; the walk-in customer always gets money back). Store credit is spendable at the till as "Customer credit".
- Stock goes back at the **cost on the invoice line**. A return receipt (80 mm) prints; copies are marked DUPLICATE and audited. Returns show on the invoice, on the customer's page and in the reports.
- A manager can **cancel** a return (reason and password): stock, account, invoice and refund are put back; refused when the goods were sold again or the store credit was already spent.

**Purchase returns (Purchase returns → Send goods back, or on a purchase)**
- Against a received purchase (never more than received less already sent back) or **without a bill** (you give the credit per unit). The supplier credits what it billed for the units (after the line's discount); the stock leaves at the **landed cost**. The credit first reduces what you still owe on that bill; what is left over stays as credit on the supplier's account or the **supplier pays it back** (a numbered refund payment). A return note prints (A4). Cancelling puts everything back; a purchase with a return cannot be cancelled until the return is.

**The profit engine (`ProfitService`, the only place profit is worked out)**
- Gross profit = product sales (without tax) − returns + **service revenue (charges and parts)** − cost of goods sold (less the cost of returned goods put back) − **cost of the parts used on delivered jobs**. Net profit = gross profit − expenses − stock lost (adjustments, never the opening stock) − service parts written off ± the difference on goods sent back to suppliers. Goods returned damaged are shown as their own line. Cancelled invoices, returns, expenses and jobs never count; tax is never revenue; every cost is the cost recorded at the time. By day, week, month, year or any period.

**Seventeen reports (Money → Reports)** each with period choices and filters, a find box, a print view, and **PDF, Excel and CSV** for people with `reports.export` (every export audited): sales summary, sales register, sales by product / category / customer / cashier, sales returns, top sellers and slow movers, stock valuation, purchases and purchase returns, supplier balances (payables, aged), customer balances (receivables, aged), service jobs with technician performance, money in and out by payment method, expenses, **closing summary** of a day (with the cash to count), and **profit**. **Cost columns need `products.view_cost` and profit columns `reports.profit`**: without them the column is not on the screen, the print, the PDF, the CSV or the Excel file.

**The dashboard** (managers and administrators): *Business at a glance* with Today / Yesterday / This week / This month / Last month / your own dates; cards for invoiced, after returns, **service jobs delivered**, money received, left on account, purchases and expenses, each against the period before; **profit cards and the gross-profit chart only for people with `reports.profit`**; charts of sales by day / week / month and the last 12 months against the year before (drawn by the page itself, animated, no internet); best sellers; the latest sales, payments, expenses and service jobs. A technician sees "My open jobs". It works on a phone.

**Integrity** (`pos:integrity:check`, twice a day): seven new checks (returns on the customer's and the supplier's accounts exactly once, returned quantities and amounts, lines against headers, stock that left or came back, refunds exactly once).

---

## 2. Files created / modified

**New (app):** `Actions/Expenses/{SaveExpense,VoidExpense}`, `Actions/Sales/{CreateSaleReturn,VoidSaleReturn}`, `Actions/Purchasing/{CreatePurchaseReturn,VoidPurchaseReturn}`, controllers `{Expense,SaleReturn,PurchaseReturn,Report}Controller`, models `{Expense,ExpenseCategory,SaleReturn,SaleReturnItem,PurchaseReturn,PurchaseReturnItem}`, `Services/Reports/{ProfitService,Periods,DashboardService,ReportCatalogue,ReportFilters,ReportResult,ReportExporter,SalesReports,PurchaseReports,InventoryReports,FinanceReports,PeopleReports,ServiceReports}`, `database/seeders/ExpenseCategorySeeder`, migration `2026_10_10_700000_create_returns_and_expenses`.
**New (screens):** `expenses/{index,form,show,categories}`, `sale-returns/{index,create,show,print}`, `purchase-returns/{index,create,show,print}`, `reports/{index,show,document}`, `components/chart/bars`.
**Changed:** `routes/web.php`, sidebar (Money group: Reports, Sales returns, Purchase returns, Expenses), `DashboardController` and `dashboard` view, `CustomerController` and customer page (goods brought back), `SaleController` / `PurchaseController` and their invoice / purchase pages (return buttons and lists), `InventoryService` (two movement types), `SupplierAccountService`, `CustomerAccountService`, `SaleAccountService` (the new integrity checks), models `Sale`, `Purchase`, ledger and movement labels, morph map, settings (`sales.store_credit_enabled`), `app.css` (chart animation), `DatabaseSeeder`.
**Tests (new):** `Expenses/ExpenseTest`, `Returns/{SaleReturnTest,PurchaseReturnTest,ReturnConcurrencyTest}`, `Reports/{ProfitTest,ReportsTest,DashboardTest}`, `Rbac/Phase8RoleMatrixTest`, `Concerns/BuildsKnownShop`, `Support/return_worker.php`.
**Documents:** DATABASE §14, DESIGN_REVIEW §11, RBAC §11, SRS §6, USER_GUIDE §6–9, ENVIRONMENT_CHANGES, ROADMAP, README, INSTALLATION, this report.

---

## 3. Database changes

One additive migration, `2026_10_10_700000_create_returns_and_expenses` (see DATABASE §14): `expense_categories`, `expenses`, `sale_returns`, `sale_return_items`, `purchase_returns`, `purchase_return_items`, and the ledger `CHECK`s widened for `sale_return_void`, `purchase_return_void` and `refund_received_void`. The tables carry `CHECK`s that make wrong data impossible (a return's settlement adds up to its value; a refund return points at its payment; a line is never returned more than once). **Applied to your real database** after a backup (`mrpos_2026-10-09_161453_manual.zip`); your administrator and everything else were untouched; the integrity check and `pos:doctor` pass.

---

## 4. Commands you need to run

On a copy of the program (the real database on this PC is already done): `php artisan migrate --force`, `npm run build`, then `php artisan pos:integrity:check`. `php artisan db:seed --class=ExpenseCategorySeeder --force` is safe to run again (the migration already creates the nine categories). No new package to install.

---

## 5. Manual setup needed from you

1. **Try it:** record an expense; take one item back from a test invoice (resellable, money back) and look at the stock, the invoice, the customer page and the receipt; look at **Reports → Profit** and the dashboard; export a report to Excel.
2. Check the **return receipt** on your real 80 mm printer (it follows Settings → Printing).
3. Answer the questions in §7 (all have a default that is already built).
4. Still waiting for an Administrator (carried over): `deploy\windows\install-mariadb-service.ps1`, the backup scheduled task, the firewall rule; product list, opening stock and balances; upload your original logo in Settings → Shop; the open owner questions of Phases 4–7.

---

## 6. Tests and checks performed

- **The known shop (`ProfitTest`, 11 tests):** every figure of a small shop worked out by hand and matched exactly: product sales 840.00 less 300.00 returned, cost 600.00 less 100.00 back on the shelf, the **delivered job** (charge 500 + part 250 − 50 discount = **700.00**: labour 466.67, parts 233.33, parts cost 100.00), gross profit 640.00 (51.6%), expenses 300.00, stock lost 200.00, parts written off 40.00, supplier difference −20.00, **net profit 80.00**. Plus: service-only profit, labour-only jobs, a job counts on its invoice day and only when delivered, cancelled things count for nothing, tax is never revenue, a later change of cost does not rewrite past profit, inclusive periods, and day / week / month / year groups that add up to the whole period.
- **Returns (`SaleReturnTest` 25, `PurchaseReturnTest` 21):** discount share, last-piece remainder, tax, over-return, restock vs write-off at the invoice cost, walk-in, settlement order, store credit spent at the till, refund permissions, cancelling (and refusals), purchase credit and refunds, free-form returns, integrity tampering detected, pages end to end.
- **Real concurrency, with mutation checks (`ReturnConcurrencyTest`, 8 tests, separate PHP processes):** four terminals returning the same units; a return racing the cancelling of its invoice; a return racing a payment; the same return cancelled by three people; the same for purchase returns; five different operations on one account at once without a deadlock. **Mutation checks:** with the row locks removed from `CreateSaleReturn` all four terminals succeeded (8 of 5 units returned); with the locks removed from `CreatePurchaseReturn` the purchase test failed; both were restored byte for byte.
- **Reports and dashboard (`ReportsTest` 14, `DashboardTest` 8):** every report opens, prints and exports to CSV, Excel and PDF with real data and in an empty shop; the figures agree with the known shop; cost and profit columns are cut out for people without the permission (screen, print, CSV); exports need `reports.export` and are audited; a spreadsheet cannot be made to run a formula; bad periods are explained; the dashboard shows profit only to people who may see it, falls back to today on a crafted address, and its charts handle losses and empty data.
- **Authorization (`Phase8RoleMatrixTest`, 163 cases):** every Phase 8 route against all four default roles; the default permission sets; every report closed to cashiers and technicians; guests sent to the login page.
- **Real-browser drill (headless Edge, 39 checks, on the scratch schema):** a cashier records an expense, has no reports and cannot take goods back; a manager's dashboard, periods, charts and phone view; a return with live values, the over-return guard, the signed receipt, stock back on the shelf, cancelling with the password; purchase returns with and without a bill; the reports, the PDF / Excel / CSV downloads, the find box, a phone-width report. **The drill found a real bug** (a JavaScript method named `valueOf` clashed with the built-in one and left the live value empty) that no backend test could see; fixed.
- **Other:** Pint clean; all views compile; `pos:integrity:check` and `pos:doctor` pass on the real database after the migration.

---

## 7. Remaining issues and decisions before Phase 9

| # | Question | Default if you do not answer |
|---|---|---|
| P8-Q1 | **Credit with a supplier:** credit left after a return stays on the supplier's account (a negative balance). Do you want a button "use this credit on the next bill"? | Not built: pay the next bill less the credit and note it |
| P8-Q2 | A **time limit** on customer returns (for example 14 days)? | None; managers decide |
| P8-Q3 | A **restocking fee** (keep part of the money)? | Not built |
| P8-Q4 | A one-click **Exchange** (return plus new sale)? | Not built: store credit, then sell with "Customer credit" |
| P8-Q5 | Should **rounding differences** on invoices be a line in profit? | Not included |
| P8-Q6 | Should cashiers see the **expenses list**? | Yes (they can record); remove `expenses.view` from the Cashier role if not |

Known limits, not problems: the find box and the report tables work on what is on the page (a report lists at most 5,000 rows of a register, with a note); `reports.service` given to a custom role shows every job, not only the person's own; a return needs an invoice (goods without a receipt can only go back to a supplier). **Phase 9** (backup / restore and audit completion) is next, when you approve.

---

## 8. Final test result

**1,482 tests, all passing** (Phase 7 ended at 1,219): the full run on the scratch schema passed 1,481 of 1,482 (14,383 assertions, 26 minutes); the one failure, a Blade directive stuck to a word on the Reports page that the project's own guard test caught, was fixed and the tests around it (all views, reports, dashboard, role matrix, integrity) were run again and pass. Code style (Pint) is clean. The real-browser drill: 39 checks, 0 failed. `pos:integrity:check` and `pos:doctor` pass on your real database.
