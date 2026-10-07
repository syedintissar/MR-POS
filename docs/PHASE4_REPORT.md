# Phase 4 Report: Purchases & Supplier Accounts

**Date:** 2026-10-07 · **Status:** complete and validated · **Waiting for:** owner approval before Phase 5 (POS, sales and invoices)

Phases 1 to 3 are committed (`c88616b`, `0c130e8`, `332f46d`, plus `b939348`). Phase 4 is **not yet committed**: review it with `git status` and `git diff`, or tell me to commit it.

---

## 1. What was implemented

**Purchases**
- A purchase starts as a **draft** (no number, no stock, no debt; edit or delete freely) and becomes **received** when the goods arrive. Receiving is one all-or-nothing step: the stock goes up, the **average cost** moves, the bill gets its number (`PUR-000001` …), the supplier is owed the total, and an optional payment made on the spot is recorded. If anything fails (a missing reference number, a deleted product …) **nothing at all is saved** and the draft stays a draft; the number is handed back.
- **Landed cost:** the invoice discount, invoice tax (when you do not claim it back) and freight / loading are shared over the lines in proportion to their value, so the cost used for the average cost is what a unit really cost. The form shows an estimate per line while you type, with an arrow when it is dearer or cheaper than last time.
- The product search on the purchase form shows **what you last paid** (to this supplier, or in general) and fills the cost box.
- The **same supplier invoice number cannot be entered twice** for one supplier (the database itself enforces it, even when two people race); a cancelled purchase frees its number.
- **Cancel a received purchase** (Manager / Administrator, password and reason): the goods leave stock again at the cost they came in (average cost unwound), the supplier's account is corrected, nothing is deleted. Refused, with nothing changed, if part of those goods has already been sold or used. **Money already paid on that bill stays recorded as an advance with the supplier**, to be applied to another bill (this differs from the original design, which cancelled the payments too; see DESIGN_REVIEW P4-04).
- Printable A4 purchase record.

**Supplier accounts**
- **Supplier ledger** (`supplier_ledger_entries`): append-only, one line per purchase, payment, cancellation or adjustment. A positive balance means you owe the supplier; a negative one means they hold an advance of yours. The cached balance is only ever written by one service and is checked against the ledger twice a day.
- **Supplier payments:** pay oldest bills first, choose the bills yourself, or keep the money as an advance. The screen previews which bills a payment settles. A payment can settle many bills; the part not applied is an **advance**, which can be applied to a bill later (no ledger line, because no money moves). A mistaken payment can be cancelled (password and reason) and its bills open again.
- **Supplier profile:** balance, open bills, recent purchases and payments, and an **account statement** with a running balance, a date range (with a "brought forward" line) and a print version.
- **Opening balance** (once per supplier) and **corrections**: Administrator only, password re-entered, reason required, never editable afterwards.
- **Payment methods** (Settings → Payment methods): Cash, Bank Transfer, Card, Easypaisa, JazzCash, Cheque and the system Customer Credit. Add your own (for example a particular bank), rename, require a reference number, reorder, switch off. Cash and Customer Credit cannot be removed; a used method cannot be deleted.
- Dashboard tile **"Owed to suppliers"** for people who may see financials; the integrity check now also covers supplier balances, bill payments, payment allocations and received goods.

**Access:** Managers and Administrators only (Admin alone for opening balances and payment-method changes). Cashiers and technicians cannot open any purchase or supplier screen and never see a purchase cost or what the shop owes (tested).

## 2. Files created / modified
40 new or changed files outside the docs: about 2,200 lines of application code, 1,000 lines of views, 200 lines of migrations and 1,760 lines of tests.

| Area | Files | Highlights |
|---|---|---|
| `app/Actions/Purchasing` | 8 | `SavePurchaseDraft`, `DeletePurchaseDraft`, `ReceivePurchase`, `VoidPurchase`, `PostSupplierPayment`, `VoidSupplierPayment`, `AllocateSupplierAdvance`, `AdjustSupplierBalance` |
| `app/Services/Purchasing` | 2 | `PurchaseCalculator` (totals and landed cost, no database), `SupplierAccountService` (the only writer of the supplier ledger and caches; also the integrity check and the statement) |
| `app/Http/Controllers` | 4 + 3 changed | Purchase, SupplierPayment, Admin/PaymentMethod; Supplier (profile, statement, balance adjustment), Settings (new tab), Dashboard |
| `app/Models`, `app/Support` | 7 + 3 changed | `Purchase`, `PurchaseItem`, `PurchasePayment`, `SupplierPayment`, `SupplierLedgerEntry`, `PaymentMethod`, `Money` (display formatting); `Supplier`, morph map |
| `resources/views` | 12 new + 6 changed | purchases (list, form, page, print), supplier payments (list, record, page), supplier profile and printed statement, payment-methods tab, sidebar, dashboard tile |
| `database` | 3 migrations + 1 seeder | see §3 |
| `tests` | 7 | 4 new test classes, a role matrix, a worker for real multi-process tests, a guard against a Blade pitfall |
| `docs` | DATABASE §10, DESIGN_REVIEW §7, SRS §6, RBAC §7, USER_GUIDE §6, INSTALLATION §11, ENVIRONMENT_CHANGES, ROADMAP, README | |

## 3. Database changes
Migrations `2026_10_07_300000` to `300200` (already applied to the real `mrpos` database; the full design is in [DATABASE.md §10](DATABASE.md)):
- `payment_methods` (seeded by the migration itself with the seven defaults, so `php artisan migrate` is enough on an existing installation).
- `supplier_ledger_entries`: append-only (update/delete triggers) with a `CHECK` that each line is exactly a debit or a credit.
- `purchases`, `purchase_items`, `supplier_payments`, `purchase_payments`: money as `DECIMAL`, `CHECK` constraints on amounts, status and numbering, and a generated column that makes "one supplier invoice number per supplier" a database rule.
- No existing table was altered and no existing data touched.

## 4. Commands you need to run
Nothing new is required for Phase 4 itself: the migrations are already applied to the real database, which is still a clean install. For any other copy of the application:

```
php artisan migrate --force       # also creates the default payment methods
npm run build                     # the new screens use new styles
php artisan optimize:clear
php artisan pos:integrity:check   # should say "all agree"
```

## 5. Manual setup needed from you
1. **The Phase 2 administrator steps are still pending** (MariaDB service, scheduler, Apache restart, `pos:install` to create your real administrator). Nothing can be used day-to-day until the administrator exists.
2. **Settings → Payment methods:** check the list; add your bank accounts or wallets if you want them by name.
3. **Supplier opening balances:** if you owe suppliers money today, create the suppliers, then (as Administrator) open each supplier and enter the **opening balance** with the reason, so the account starts correct.
4. Then try the real flow once with a small bill: **Purchases → New purchase → Save & receive stock**, and look at the supplier's account.

## 6. Tests and checks performed

| Check | Result |
|---|---|
| **Whole suite** (`php artisan test`, real MariaDB schema) | **651 passed, 0 failed** (3,383 assertions, about 7 minutes). Phase 4 added about 190 tests (15 + 42 + 5 + 26 in the four new test classes, 103 in the role matrix, 1 view guard); none of the earlier tests was weakened (one dashboard heading assertion was updated because the heading was reworded) |
| Money maths (`PurchaseCalculatorTest`) | 15 tests: line totals, rounding, discounts, landed cost with freight / discount / tax claimed or not, fractional quantities, zero-value bills |
| Posting rules (`PurchaseActionsTest`) | 42 tests: draft rules, receive (stock, number, ledger, average cost 100 + 200 → 150), void reversal (average cost unwound, advance kept, refused when goods were sold or returned), payments (oldest first, manual, advance, over-allocation, wrong supplier, method rules, zero / negative), apply an advance, cancel a payment, opening balance, ledger append-only even with raw SQL, audit trail, a "busy day" scenario where books must agree |
| **Rollback test** | A payment that fails while receiving (no reference for a bank transfer, or more than the bill) leaves **no** stock movement, no ledger line, no payment, the draft still a draft, and the numbers are handed back (the next receipt is `PUR-000001`) |
| **Concurrency with real processes** (`PurchaseConcurrencyTest`) | Four people receiving the same draft: exactly one succeeds. Two people paying the same bill in full: exactly one. Four automatic payments at once never over-pay a bill and nothing deadlocks. Cancelling a bill while it is being paid, and two people cancelling the same payment: books agree. **Mutation check:** with every row lock removed on purpose, four processes received the same draft and two paid the same bill, so the tests genuinely detect the problem (locks restored and re-checked) |
| Web flows (`PurchaseWebTest`) | 26 tests: every page renders, save draft / save and receive, a failed receive keeps the draft, bad input (10 kinds) is refused and nothing saved, duplicate invoice, password needed to cancel, payments, advance, adjustments, payment methods (system ones protected), dashboard tile, integrity check names a tampered supplier balance and a tampered bill |
| **Authorization** | `Phase4RoleMatrixTest`: every Phase 4 route against Admin, Manager, Cashier and Technician (100 cases) matches docs/RBAC.md; guests go to the login page; cashier and technician see **no** cost or supplier information on the dashboard, product pages, product search, stock card or any purchase URL; "may prepare drafts but not receive" is enforced before anything is saved |
| DB integrity | `pos:integrity:check` passes on the test data and on the real database after the drills; it reports a hand-edited balance or bill |
| **Real-system drill** (real `mrpos` database, real web server, after a backup) | 44 HTTP checks passed: log in, receive a purchase with a payment, duplicate invoice refused, draft, every page opens, bank payment without reference refused, oldest-first payment with an advance, receive a draft, apply the advance, cancel needs the password, cancel and cancel-payment, and a cashier blocked from every purchasing URL |
| **Backup and restore drill** (same database) | A manual backup holding the Phase 4 data passed **deep verification** (imported into a scratch database). After more data was entered, restoring it brought back exactly the backed-up state (2 payments, 7 methods, 6 ledger lines), the supplier ledger, stock ledger and audit log still refused edits, the integrity check and the audit hash chain passed, and a safety backup of the pre-restore state was written. *Note:* the restore command's password prompt cannot be scripted on Windows, so the drill called the restore service directly after authenticating; the prompt path is covered by the Phase 2 tests |
| Style | Pint clean |

**Problems found and fixed during the phase** (full list in DESIGN_REVIEW §7): the integrity check caught a real bug in my first run (a payment that had settled a cancelled bill was still counted as allocated); a Blade directive glued to a word (`yet@endif`) silently blanked the dashboard (now guarded by a test); a possible lock-order deadlock between receiving-with-payment and a payment for another supplier (audit write moved last); a misleading cost-trend arrow; two of my own tests used `array + array` and did not test what they claimed.

## 7. Remaining issues and decisions before Phase 5

| # | Item | Needed from you |
|---|---|---|
| 1 | **Approval of Phase 4** | Yes / changes. Also say whether to **commit** it |
| 2 | **Phase 2 admin steps still pending** (MariaDB service, scheduler, Apache restart, `pos:install`) | Run them when convenient; Phase 5 (selling) needs a real administrator and cashier accounts |
| 3 | **Supplier opening balances and your product list** | Whenever ready: supplier names and what you owe each; the Excel product list with stock and prices |
| 4 | **Phase 5, credit sales:** customers arrive in Phase 6, so in Phase 5 only walk-in (cash / card / wallet, fully paid) sales are possible | Default: yes, partial payment and credit are added with customers in Phase 6. OK? |
| 5 | **Cashier discount limit** | Default: 5 % without approval, more needs a manager (setting `pos.max_discount_percent`). OK? |
| 6 | **Rounding at the till** | Default: no rounding (exact paisa). Do you want totals rounded to the nearest rupee? |
| 7 | **Wholesale price** | Default: the cashier picks Retail or Wholesale per sale. Or should wholesale apply automatically to certain customers (Phase 6)? |
| 8 | **How long can a sale be cancelled?** | Default: managers can cancel a sale on the same day, administrators any time, always with a reason, and only if no goods were returned |
| 9 | Old MariaDB 10.4 on port 3306 (no root password, open to the network; other projects use it) | Still undecided: leave and secure it, or move those projects to 11.4 |

Known limits (deliberate, tracked): a product's "last purchase cost" is not rolled back when a purchase is cancelled (the next purchase corrects it); only one payment method can be used at the moment of receiving (more go through Supplier payments straight afterwards); there are no bill due dates or ageing yet (reports, Phase 8); supplier refunds and purchase returns arrive in Phase 8; purchases are printed through the browser on A4.
