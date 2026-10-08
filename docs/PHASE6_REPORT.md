# Phase 6 Report: Customers & Receivables

**Date:** 2026-10-08 · **Status:** complete and validated · **Waiting for:** owner approval before Phase 7 (Service / repair / refill jobs)

Phases 1 to 5 are committed (the latest is `2ddb1c4`, Phase 5). Phase 6 is **not yet committed**: review it with `git status` and `git diff`, or tell me to commit it.

---

## 1. What was implemented

**Customers (Customers → Customers)**
- Add, edit, search (name, phone, code, company), activate / deactivate; automatic code `CUS-000001…`. Retail or wholesale price list, "allow credit" and an optional credit limit (blank = unlimited), notes, WhatsApp, e-mail, address.
- **Duplicate phone warning:** the same number (digits only, so `0300-1234567` = `0300 1234567`) shows who has it and needs "save anyway".
- **Credit terms are a manager's decision.** A cashier can add a customer (at the till or on the page) but the customer is created retail and cash-only; editing a customer as a cashier keeps the terms a manager set.
- A customer with no history can be deleted (password); everyone else is deactivated. The **walk-in customer** has no page, no account and no credit (the database enforces it).
- **Opening balance:** once per customer, **Administrator only**, with a reason and the password; later corrections are balance adjustments, each with a reason. Nothing is ever edited: the customer ledger is append-only (database triggers).
- Customer page: balance, unpaid invoices (oldest first), recent sales and payments, account history, **Sell to this customer**, **Collect a payment**, **Print statement / PDF**. People who may not see money (Technicians) see names and phones only.

**At the till**
- **F4 / Choose customer:** search by name, phone, code or company; **Add a new customer** in the same box (with the duplicate-phone question). A wholesale customer switches the till to the wholesale list automatically; going back to the walk-in customer undoes it. The chosen customer is remembered with the cart (refresh, hold and resume).
- **Credit sale:** type what the customer pays now (or **Put it all on account**); the rest is **left on account**. Only for customers marked "allow credit"; the walk-in customer and cash-only customers still pay in full (clear messages). The payment screen shows what the customer will owe afterwards and warns when that is over their credit limit; a manager approves going over it (or any credit sale by a cashier without `sales.credit`).
- **Customer credit:** a customer who has paid in advance can spend it (**Use their credit**); nothing goes into the cash drawer and the credit cannot be spent twice.
- The "sale completed" banner shows who it was sold to and **what was left on account**.

**Collecting money (Customers → Customer payments)**
- Amount, method, reference; the money settles the **oldest invoices first** (by invoice date), or exactly the invoices chosen, or is kept as an **advance**; extra beyond what is owed stays as the customer's credit and can be applied to later invoices. A numbered receipt (`RCP-…`) opens to print (signed link, ten minutes); later prints are marked DUPLICATE and audited.
- **Cancel a payment** (managers; reason and password): the invoices become unpaid again and the account is corrected by an opposite entry.
- Cancelling a **sale** reverses its invoice on the account and cancels only its own till receipts; money the customer paid later stays as their credit.

**Reports**
- **Statement** per customer for a date range (brought-forward, running balance, closing balance), printable and as a **PDF** (audited).
- **Who owes us:** unpaid invoices by age (today, 1–30, 31–60, 61–90, over 90 days) with an "Other" column for opening balances and credit held, so every row equals the customer's balance; optional invoice detail; printable. Dashboard tile **Owed by customers** for people with financial access.

**Look and feel (new, from your logo)**
- A **light, modern theme** in the colours of the logo: red (the "R") for the main action, the current page in the menu, focus and links; neutral greys (the "M") everywhere else; white menu, rounded cards with soft shadows, frosted top bar.
- **Subtle animation:** pages and dashboard cards fade in one after another, cards lift on hover, buttons rise and press in, the current-page marker in the menu slides in, dialogs pop in, the sign-in page has the logo over a softly moving background. All motion is turned off automatically for people whose computer asks for reduced motion.
- **Logo:** the picture reached me only in the chat, so the built-in "MR" mark (menu, sign-in page, browser tab icon) is my vector redrawing of it. **Please upload the original logo file in Settings → Shop**: it replaces my drawing in the menu, the sign-in page and on receipts.

**Safety**
- Customer money has one writer (`CustomerAccountService`) and one ledger; `customers.balance` is a cache that `pos:integrity:check` recomputes. Three new integrity checks (balance = ledger; every invoice on the account once, and reversed once when cancelled; every receipt on the account once, and reversed once when cancelled).
- Every customer-money operation runs in one database transaction with a fixed lock order (customer first). **A real-process concurrency test found and fixed a double-spend of customer credit** (see §6).

## 2. Files created / modified
About 40 new or changed files: roughly 2,700 lines of application code (new files and changed files), 1,580 lines of views, 750 lines of till JavaScript, 70 lines of migrations and 3,360 lines of tests.

| Area | Files | Highlights |
|---|---|---|
| `app/Actions/Customers`, `app/Services/Customers` | 6 + 2 | `SaveCustomer`, `DeleteCustomer`, `AdjustCustomerBalance`, `PostCustomerPayment`, `VoidCustomerPayment`, `AllocateCustomerAdvance`; `CustomerAccountService` (the only writer of the customer ledger and balance, statement, advance, integrity), `ReceivablesService` (aging) |
| `app/Actions/Sales` | 2 changed | `CompleteSale` (named customers, credit, customer credit, limits, approvals; rewritten payment rules), `VoidSale` (reverses the account) |
| `app/Http/Controllers` | 3 new + 3 changed | `CustomerController`, `CustomerPaymentController`, `ReceivablesController`; `PosController`, `DashboardController`, routes |
| `app/Models`, `app/Exceptions` | 2 + 1 changed | `CustomerLedgerEntry`, `DuplicatePhoneException`; `Customer`, `CustomerPayment` |
| `resources/js` | 1 changed | `pos.js`: customer box, quick add, on-account payments, customer credit, limit hint, held carts and refresh keep the customer |
| `resources/views` | 11 new + 5 changed | customers (list, form, profile, statement), customer payments (list, collect, payment, receipt), who-owes-us, till, sales pages, sidebar, dashboard |
| `database` | 2 migrations | see §3 |
| `tests` | 9 new + 1 changed | see §6 |
| `docs` | DATABASE §12, DESIGN_REVIEW §9, SRS §6, RBAC §9, USER_GUIDE §3, INSTALLATION, ENVIRONMENT_CHANGES, ROADMAP, README | |

## 3. Database changes
Migrations `2026_10_08_500000` and `500100` (already applied to the real `mrpos` database after a backup; the full design is in [DATABASE.md §12](DATABASE.md)):
- **`customer_ledger_entries`**: append-only (triggers refuse edit and delete), exactly one of debit / credit greater than zero, a fixed list of entry types.
- **`customer_payments.sale_id`**: nullable foreign key. A receipt taken at the till belongs to its sale; a payment collected later has none, so cancelling a sale never touches money paid on account.
- No existing data changed.

## 4. Commands you need to run
Nothing for Phase 6 itself: the migrations are applied to the real database, which is still a clean install. For any other copy of the application:

```
php artisan migrate --force
npm run build                    # the till and the customer screens changed
php artisan optimize:clear
php artisan pos:integrity:check
```

## 5. Manual setup needed from you
1. **The Phase 2 administrator steps are still pending** (MariaDB service, scheduler, Apache restart, `pos:install` for your real administrator).
2. **Customers:** list the customers who buy on credit and decide each one's limit (a manager sets it on the customer's page); the Administrator enters any amount they already owe you as an **opening balance** (name, phone, amount: send me the list if you want me to prepare an import).
3. **Upload your real logo** (Settings → Shop → Logo); the program shows a redrawn "MR" mark until you do.
4. Decide the answers to the open questions in §7 (all have a default).

## 6. Tests and checks performed

| Check | Result |
|---|---|
| **Whole suite** (`php artisan test`, real MariaDB schema) | **1,013 passed, 0 failed** (11,079 assertions, about 11 minutes), run after the last change including the new look. Phase 6 added about 185 PHP tests (customer rules, web pages, role matrix, real parallel processes, integrity) and 15 browser-code tests; one Phase 5 test that said "named customers are not available yet" was replaced by its Phase 6 meaning; no other earlier test was weakened. (Two earlier runs were disturbed while you were installing in the same folder, see ENVIRONMENT_CHANGES; both were repeated) |
| Customer posting rules (`CreditSaleTest` 24, `CustomerActionsTest` 25) | credit sale, partial payment, limit, approval reasons, walk-in and cash-only refusal, oldest-first and manual allocation, advance, cancel payment, cancel sale, opening balance once, statement running balance, failure leaves nothing behind |
| Web pages (`CustomerWebTest` 21, `CustomerPaymentWebTest` 15) | every page renders; credit terms and wholesale list protected from cashiers; duplicate phone; delete and opening balance need the password and the right role; technicians never see money (list, profile, statement, payments, till search); till search and quick add; credit sale and limit through the JSON endpoint; collect, cancel, signed receipt, DUPLICATE reprint with audit; statement and PDF; aging bands and the "Other" column; dashboard tile |
| **Authorization** | `Phase6RoleMatrixTest`: every Phase 6 route against Admin, Manager, Cashier and Technician (88 cases) matches docs/RBAC.md; guests go to login |
| **Concurrency with real processes** (`CustomerConcurrencyTest`, 6 tests) | two people collecting on one invoice (settled once, the extra is credit); four cashiers selling on credit near a limit (exactly two fit); **two tills spending the same credit (one succeeds)**; cancelling an invoice while its money is collected; one payment cancelled by three people (reversed once); collections and credit sales together (no deadlock). **Mutation checks:** without the row lock four of the six tests fail; with the early read put back the credit test fails |
| **A real bug found by that test and fixed** | The sale's first database read ("which customer is the walk-in one?") fixed the transaction's snapshot **before** it waited for the customer lock, so a second till still saw credit that had just been spent. The customer lock is now the first read (sale and void); the rule is documented (DATABASE §12) |
| Integrity (`CustomerIntegrityTest`) | good books pass; a hand-edited balance, invoice paid figure or receipt amount is named; the ledger refuses edits, deletes, two-sided and unknown-type lines even by hand |
| Till JavaScript (`tests/js/pos-customers.test.mjs`) | 15 tests (also run inside the PHP suite): walk-in and cash-only rules, on-account figures, limit hint, no money for people who cannot see it, customer credit, wholesale switch, search race, quick add with duplicate confirmation, refresh / hold / resume keep the customer, F4 and Escape |
| **Real-browser drill** (real server and database, headless Edge, real key presses and clicks) | **43 checks, 42 passed**; the one "failure" was my script looking for words in mixed case that the page draws in capitals (the tile was right on the screenshot): customer box, search, selecting, 300 sale with 100 paid and 200 left on account, walk-in and cash-only refusals, wholesale price, quick add and duplicate phone, 1,200 over the limit approved by a manager, collecting 1,500 against 1,400 owed, receipt, spending the 100 credit at the till, statement and a real PDF, "Who owes us", dashboard tile, customer and sales lists; no JavaScript error anywhere |
| **Backup and restore drill** (same database, with customer data) | The backup passed **deep verification** (42 tables imported into a scratch database). After more credit and a payment, restoring brought back exactly the backed-up state (balances, ledger, advance, triggers), the later sale, payment and held cart disappeared, and the customer-ledger, stock-ledger and audit protections still refused hand edits; integrity check and audit chain passed; a safety backup was written |
| Style | Pint clean |

**Other problems found and fixed during the phase:** the payment page would have crashed (the model lacked three relations; the backend tests did not render pages, the new web tests did); a first integrity query was rejected by MariaDB and was rewritten; the dashboard's "Coming next" text was out of date. Details in DESIGN_REVIEW §9.

## 7. Remaining issues and decisions before Phase 7

| # | Item | Needed from you |
|---|---|---|
| 1 | **Approval of Phase 6** | Yes / changes. Also say whether to **commit** it |
| 2 | **Phase 2 admin steps still pending** (MariaDB service, scheduler, Apache restart, `pos:install`) | Run them when convenient; the system cannot be used for real until your administrator exists |
| 3 | **Do customers owe you money today?** | Names, phone numbers and amounts: they become opening balances |
| 4 | **Technician and customers:** Technicians can find a customer (name, phone) but never see money | Keep it? (Phase 7 can limit them to the customers of their own jobs.) |
| 5 | **Cashier and credit terms:** a cashier can add a customer but only a manager gives credit or the wholesale list | OK, or should cashiers be allowed? |
| 6 | **Repair jobs for the walk-in customer** | Default: allowed, paid in full at delivery; a named customer may have the job invoice on account |
| 7 | **Advances on repair jobs** | Default: kept as the customer's credit (usable at the till), so one account tells the whole story |
| 8 | **Real receipt printer test** (carried over) | Print one sale and one customer payment receipt and tell me about the layout |
| 9 | **Products, prices and opening stock** (carried over) | Your Excel list, when ready |
| 10 | Old MariaDB 10.4 on port 3306 (no root password, open to the network; other projects use it) | Still undecided: leave and secure it, or move those projects to 11.4 |

Known limits (deliberate, tracked): a customer's returns and service jobs appear in their profile with Phases 7 and 8; crediting the account from a sales return arrives with returns (Phase 8); statements are per customer (no bulk "print all statements" yet); the customer list has no import (ask if you want one).

## 8. Final test result
`php artisan test`: **1,013 passed, 0 failed, 11,079 assertions, 644 seconds** (final run, after all changes including the new look). `node --test tests/js`: 46 passed, 1 skipped by design (the parity file is supplied by the PHP run). Pint clean. `pos:integrity:check` and `pos:doctor` pass on the real database (clean install: 0 users, 0 sales, 7 payment methods, the walk-in customer; Phase 6 migrations applied).
