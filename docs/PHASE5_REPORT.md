# Phase 5 Report: POS, Sales & Invoices

**Date:** 2026-10-07 · **Status:** complete and validated · **Waiting for:** owner approval before Phase 6 (Customers & receivables)

Phases 1 to 4 are committed (the latest is `7338423`, Phase 4). Phase 5 is **not yet committed**: review it with `git status` and `git diff`, or tell me to commit it.

---

## 1. What was implemented

**The till (Selling → Point of sale)**
- One screen. The search box has the cursor; a **barcode scanner** types the code and presses Enter and the product is added (the same product again raises the quantity); typing a name, SKU or model also works, and an exact code always comes first. Category buttons and a **quick-pick grid of the shop's fast sellers** sit below. Services (e.g. a toner refill) are sold like products without touching stock.
- The cart shows quantity (− / + or the keys + and −), the price list price, a typed price, a discount per line (rupees or %), a serial number for warranty products, and the stock left (red when there is not enough). Invoice discount, notes (optionally printed), tax (off by default, switchable in Settings, inclusive or exclusive) and optional round-off to Rs. 1 / 5 / 10.
- **Payment (F9):** cash is pre-filled with the exact total; type what the customer hands over and the screen shows the **change to give**; **split payments** across Cash, Card, Bank, Easypaisa, JazzCash …; wallet / bank / cheque payments ask for a reference. Only cash can be overpaid. **Complete (F10)** saves everything in one step.
- **Held carts** (F8 hold, "Held carts" to resume or discard): the screen warns about prices that changed while a cart waited; nothing is reserved. A cart that was open when the page was refreshed or closed by accident **comes back**.
- **Manager approval at the till:** a discount above the cashier's limit, a typed price, the wholesale list, or a price below the lowest allowed asks for a manager, who types their own username and password on the cashier's screen. One use, two minutes, locked after five wrong tries, every attempt audited. A manager cannot approve a below-minimum price (administrator only).
- Keys: F2 search · F8 hold · F9 pay · F10 complete · Esc · + / −.

**Safety**
- **The server decides every figure.** The browser's prices and totals are hints; the server prices each line from the product, applies discounts and tax, and **refuses the sale if its total differs from what the screen showed** (and tells the cashier the new figure). The browser's arithmetic uses exact integers and is proven identical to the PHP calculator on thousands of random sales.
- **No double sale:** a double click, three rapid clicks, a slow network and a retry all produce **one** invoice; a checkout key belongs to the cashier who used it.
- **No overselling:** several cashiers selling the last unit at once give exactly one sale (real parallel processes). Everything is all-or-nothing: if anything fails, no stock moves, no money is recorded and the invoice number is handed back.
- The invoice keeps the **cost at the moment of sale** for profit (Phase 8) and every figure as it was (names, prices, tax); the walk-in customer can never owe anything, enforced by the database.

**Invoices and printing**
- **80 mm receipt** (paper width, margins, font size, copies and auto-print from Settings → Printing) with the invoice number as a barcode, and an **A4 invoice**, both showing change, discounts, tax, serial numbers and warranty dates. The original opens only through a signed link valid ten minutes; every later print is a **reprint marked DUPLICATE and audited**; a cancelled invoice prints **VOID**. The A4 invoice can be **downloaded as a PDF** (built offline).
- **Selling → Sales:** invoice list with filters (date, cashier, payment method); cashiers see only their own, managers everyone's; the invoice page shows lines, payments, approver and, for managers only, **cost and profit**. **Cancel** an invoice (manager within 24 hours, administrator any time; password and reason; refused after a return): the goods go back at the cost they left at and the payments are cancelled.
- Dashboard tile **Sales today** (cashiers see their own); the twice-daily integrity check now also covers invoices.

## 2. Files created / modified
About 48 new or changed files outside the docs: 1,750 lines of application code, 760 lines of browser JavaScript, 640 lines of views, 190 lines of migrations and 2,700 lines of tests.

| Area | Files | Highlights |
|---|---|---|
| `app/Actions/Sales`, `app/Services/Sales` | 2 + 4 | `CompleteSale`, `VoidSale`; `SaleCalculator` (all the money maths), `SaleAccountService` (paid / due and the integrity check), `PosApprovalService` |
| `app/Http/Controllers` | 2 + 3 changed | `PosController` (till page and JSON), `SaleController` (list, invoice, prints, PDF, reprint, void); Dashboard, Settings wiring, routes |
| `app/Models`, `app/Exceptions`, `app/Support` | 6 + 2 + 2 | `Customer`, `Sale`, `SaleItem`, `CustomerPayment`, `SalePayment`, `HeldSale`; `ApprovalRequiredException`, `TotalMismatchException`; `SafeRename`, `SafeZip` (see §6) |
| `resources/js` | 3 | `pos-calc.js` (exact integer maths), `pos.js` (the till), `app.js` |
| `resources/views` | 6 new + 3 changed | till, sales list, invoice page, 80 mm and A4 print views; sidebar, dashboard |
| `database` | 2 migrations + 1 factory | see §3 |
| `tests` | 12 | calculator, sale actions, web and JSON, role matrix, real parallel processes, the till's JavaScript (also run from PHPUnit), helper tests |
| `docs` | DATABASE §11, DESIGN_REVIEW §8, SRS §6, RBAC §8, USER_GUIDE §2, INSTALLATION, ENVIRONMENT_CHANGES, ROADMAP, README | |
| Composer | `dompdf/dompdf` | the A4 PDF; offline |

## 3. Database changes
Migrations `2026_10_07_400000` and `400100` (already applied to the real `mrpos` database; the full design is in [DATABASE.md §11](DATABASE.md)):
- `customers` with its final shape and the **one walk-in customer** (a second one is impossible, and credit or a balance for it is refused, by the database).
- `sales`, `sale_items`, `customer_payments`, `sale_payments` (every till payment is one numbered receipt, `RCP-…`, settling one sale; change is not a payment), `held_sales`; `CHECK` constraints on statuses, amounts and quantities, and a unique checkout key per sale.
- No existing table was altered and no existing data touched.

## 4. Commands you need to run
Nothing for Phase 5 itself: the migrations are applied to the real database, which is still a clean install. For any other copy of the application:

```
composer install --no-dev        # fetches the PDF library
php artisan migrate --force
npm run build                    # the till is a new script
php artisan optimize:clear
php artisan pos:integrity:check
```
A receipt prints through the browser to the Windows default printer of each counter PC. For one-click printing without the print dialog, start Chrome / Edge with `--kiosk-printing` (see INSTALLATION.md §7).

## 5. Manual setup needed from you
1. **The Phase 2 administrator steps are still pending** (MariaDB service, scheduler, Apache restart, `pos:install` for your real administrator). Selling needs real users: create a **Cashier** account for each person at the till (Admin → Users).
2. **Products and stock:** import your Excel list (Products → Import) so there is something to sell, with prices and opening stock.
3. **Settings → Shop and Receipt text:** shop name, address, phone, logo, footer message; **Printing:** paper width of your printer, auto-print; check **Sales** (cashier discount limit, void window) and **Sales tax** (off until you are registered).
4. Print one test sale on the real receipt printer and tell me if the layout needs a change (paper width, margins, font size are settings).

## 6. Tests and checks performed

| Check | Result |
|---|---|
| **Whole suite** (`php artisan test`, real MariaDB schema) | **828 passed, 0 failed** (10,117 assertions, about 10 minutes). Phase 5 added about 175 PHP tests plus 32 browser-code tests (run from inside the PHP suite); none of the earlier tests was weakened |
| Money maths (`SaleCalculatorTest`) | 19 tests including 600 random sales: lines balance, shares add up to the paisa, tax inclusive and exclusive, round-off |
| **Browser maths = server maths** | The till's integer arithmetic was compared with the PHP calculator on ~4,000 random sales (and 2,500 on every full test run): **identical figures** |
| Posting rules (`CompleteSaleTest`) | 51 tests: totals, stock and cost snapshot, split payments and change, walk-in must pay in full, payment-method rules, **stock shortage leaves nothing behind**, price / discount / wholesale / minimum-price permissions and approvals (one use, tied to the cashier, throttled, covers every reason), tax and round-off, serial and warranty, retries, void (cost, window, administrator, returns), integrity check detects a tampered invoice |
| **Rollback test** | An exception injected after the sale, its lines and the stock movement were written (the receipt numbering broken on purpose) leaves **zero** rows: no sale, no lines, no payment, no stock change, no audit entry |
| **Concurrency with real processes** (`SaleConcurrencyTest`) | Four cashiers selling the last unit: exactly one sale; the same checkout from four places at once: one sale, all four told about it; four buyers of 2 with 5 in stock: two sell; six carts with the same products in opposite order: no deadlock, gap-free invoice numbers; one sale cancelled by three people at once: reversed once. **Mutation checks:** without the stock locks four sales succeeded for one unit; without **both** retry protections the retry test fails with a raw duplicate-key error (one protection alone is enough, which is why two are kept) |
| Web and JSON (`PosWebTest`) | 31 tests: pages, search (never returns cost or the lowest price, for any role), services, categories, quick picks, signed print links (forged, wrong format, expired), DUPLICATE and VOID, receipt contents on 80 mm and A4 and the PDF, own-sales visibility, cost only for managers, void with password, held carts (own only, limit, whole cart kept), dashboard, integrity command |
| **Authorization** | `Phase5RoleMatrixTest`: every Phase 5 route against Admin, Manager, Cashier and Technician (60 cases) matches docs/RBAC.md; guests go to login; cashiers never receive cost or the lowest price from any till endpoint, receipt, invoice, list or the dashboard |
| **Real-browser drill** (real server and database, headless Edge, real key presses and clicks) | **31 checks, five runs in a row clean:** barcode scan with Enter, repeat scan, SKU + Enter, stock warning blocking payment, F9 / F10, 500 handed over for 390 shows 110.00 change, receipts open and ask the browser to print, 20 % discount asks for a manager (wrong password refused, right one completes), service item, hold and resume at today's prices, refresh restores the cart, a triple click on Complete makes exactly one sale, a manager sees cost and profit. Two early "failures" were my script typing faster than a person; fixed in the script |
| **Backup and restore drill** (same database, with sales in it) | A backup holding the Phase 5 data passed **deep verification** (all 41 tables imported into a scratch database). After another sale and a held cart, restoring it brought back exactly the backed-up state, the later sale and cart disappeared, and the audit log, stock ledger and sales constraints still refused hand edits (invalid status, negative total, a second walk-in customer, credit for the walk-in customer, a repeated checkout key); the integrity check and audit chain passed and a safety backup was written. (The restore command's password prompt cannot be scripted on Windows, so the drill called the restore service directly; the prompt path is covered by the Phase 2 tests) |
| PDF | Rendered to a picture and inspected: layout, DUPLICATE watermark, discount column and the barcode |
| Style | Pint clean |

**Problems found and fixed during the phase** (full list in DESIGN_REVIEW §8): a held cart lost its prices, discounts and notes because validation kept only some fields (caught by a test); the checkout key would not have been created on the tills because `crypto.randomUUID` does not exist on plain-http LAN pages; the first design's way of sharing an invoice discount could over-discount a small line; anyone could re-print the "original" receipt (now a ten-minute signed link; later prints are marked); the barcode was missing from the PDF at first. **Two unrelated Windows file-lock failures** showed up in the full suite (once `rename` and once `ZipArchive::open` was refused on a file written a moment before: antivirus holding it). They are rare, but a backup must never fail for that, so the same protection now covers every place the backup code renames or opens a freshly written file (`SafeRename`, `SafeZip`), with tests that reproduced the real Windows refusal and the recovery. One slip of mine: a one-line check created a stray test user in the real database; it was removed at once and recorded in ENVIRONMENT_CHANGES.

## 7. Remaining issues and decisions before Phase 6

| # | Item | Needed from you |
|---|---|---|
| 1 | **Approval of Phase 5** | Yes / changes. Also say whether to **commit** it |
| 2 | **Phase 2 admin steps still pending** (MariaDB service, scheduler, Apache restart, `pos:install`) | Run them when convenient; the till cannot be used for real until your administrator exists |
| 3 | **Real receipt printer test** | Print one sale on the shop's printer and tell me about the layout |
| 4 | **Products, prices and opening stock** | Your Excel list, when ready |
| 5 | **Phase 6, credit sales:** a named customer can buy on credit up to a limit | Default: credit needs the customer to be marked "allow credit" with an optional limit (blank = unlimited); only a manager can exceed the limit. OK? |
| 6 | **Customer price list** | Default: a customer is Retail or Wholesale; wholesale customers get wholesale prices automatically (no approval needed for them). OK? |
| 7 | **Opening balances of customers** | Do customers owe you money today? If so, a list of names, phone numbers and amounts: they are entered as opening balances so the accounts start correct |
| 8 | **Customer payments** | Default: money collected from a customer settles the oldest invoice first (or the invoices you choose); anything extra is kept as an advance. OK? |
| 9 | **Statements** | Default: a printable statement per customer for a date range (like the supplier one). OK? |
| 10 | Old MariaDB 10.4 on port 3306 (no root password, open to the network; other projects use it) | Still undecided: leave and secure it, or move those projects to 11.4 |

Known limits (deliberate, tracked): sales to the walk-in customer only until Phase 6; sales returns and exchanges are Phase 8 (an invoice can be cancelled meanwhile); a customer search at the till and a cash-drawer kick (the printer driver's "open drawer" option) are not built; the quick-pick grid is automatic (fast sellers), not a hand-picked favourites list; the receipt prints through the browser (no direct ESC/POS printing).

## 8. Final test result
`php artisan test`: **828 passed, 0 failed, 10,117 assertions, 610 seconds** (final run, after all changes). `node --test tests/js`: 32 passed. Pint clean. `pos:integrity:check` and `pos:doctor` pass on the real database (clean install: 0 users, 0 sales, 7 payment methods, the walk-in customer).
