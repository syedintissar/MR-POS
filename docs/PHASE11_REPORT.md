# Phase 11 Report: Cash & Bank Book, Day Closing and the Owner's Decisions

**Date:** 2026-11-11 · **Waiting for:** your approval. Phase 11 is **not committed** and **not applied to your real database**: see section 5.

Until now the system knew *how* every rupee was paid (Cash, Bank Transfer, Easypaisa ...) but not *where the money is*. There was no balance for the drawer or the bank, counting the drawer was a report you compared by eye, and there was no way to record cash taken to the bank or money you took out for yourself. Phase 11 adds that. The idea was taken from the bank register and the note-by-note cash count of the Moon Traders ERP, cut down to what one shop needs.

## 1. What was implemented
- **Accounts.** The cash drawer, a bank account and one account for each wallet are created for you. Every payment method pays into one account (Cash → Cash drawer; Bank Transfer, Card and Cheque → Bank account; Easypaisa and JazzCash → their own). You can rename them and add more (a second bank, a safe, a drawer for each counter).
- **Balances that cannot be wrong.** An account has no stored balance. It is added up from the receipts, refunds, supplier payments and expenses that already exist, plus the new entries below. A cancelled document counts nowhere. The tested sale, payment and expense code was not changed: the database itself notes on each row which account it landed in.
- **The book of an account.** For any dates: what was brought forward, every movement with its document (a click opens it), money in, money out and a running balance. It prints on A4.
- **Entries for money that moves on its own.** Cash taken to the bank (a transfer), money the owner took out or put in, and the opening balance of an account. Cash cannot leave the drawer if the book says it is not there; a bank account may be overdrawn.
- **Close the day.** Count the drawer note by note (5000, 1000, 500, 100, 50, 20, 10, and coins) or type the total. The screen shows what the book says, what you counted, and **exact / short / over** as you type. A difference must be explained; it is recorded with your name as a "cash short" or "cash over" entry so the book agrees with the drawer again and tomorrow starts from what is really there. The closing prints with signature lines.
- **Cancelling.** An entry, a closing or a statement matching made by mistake is cancelled with a reason and the password, never deleted.
- **A safety fix for the tests.** After `php artisan optimize` (go-live), the test run was booting from the cached production settings and would have pointed at your real database; only Laravel's "are you sure?" question stopped it. `phpunit.xml` now makes the tests ignore those caches.

### Your four decisions (asked and answered on 2026-11-11)
| # | Question | Your decision | How it was built |
|---|---|---|---|
| P11-Q1 | Who counts the drawer? | **Managers only** | As built: managers and administrators close the day and see the expected amount. Cashiers and technicians cannot open Cash & bank |
| P11-Q2 | Yesterday not closed? | **Remind only** | A yellow line on the dashboard and on the till: "Cash drawer has not been counted since 10 Nov 2026". A manager gets a *Close the day* link; a cashier reads "Please tell the manager". Selling is never stopped. It disappears the moment the drawer is counted |
| P11-Q4 | Cash short in profit? | **Yes, in net profit** | A line "Cash short or over at day closing" in the profit report. Short is a loss, over is a gain; gross profit does not change. Cancelling a closing takes its line out again |
| P11-Q3 | Bank statement matching? | **Yes** | **Cash & bank → Bank statements.** Enter the last day and the closing balance of the statement, tick every line of the book that is on it; the screen shows what is "still to find". The statement can be closed only when the ticked lines add up to its balance. Lines not on it yet stay open for the next statement, which starts from this one's closing balance. A line can be matched once only. After closing, cash & bank entries for that account cannot be dated inside the statement, and the integrity check reports any matched line that was cancelled afterwards |
| P11-Q5 | One drawer per counter? | **Yes** | **Accounts → One drawer per counter.** Add a cash account for each counter and give it to a person. From then on the cash that person takes at the till, refunds or pays out lands in their own drawer; everybody else's goes to the main drawer. Each drawer is counted and closed on its own, and the reminder names the drawer. Giving somebody another drawer changes the future only, never what was already taken |

One rule changed because of P11-Q5: pointing a payment method at another account now applies **from now on** (before, it would have moved the method's whole history). Money a method took while it had no account at all is still put into the account you choose for it.

## 2. Files
New: `CashBookController`, `MoneyAccountService`, `BankReconciliationService`, the actions `PostMoneyTransaction`, `VoidMoneyTransaction`, `CloseDay`, `VoidDayClosing`, the models `MoneyAccount`, `MoneyTransaction`, `DayClosing`, `BankReconciliation`, twelve views in `resources/views/cashbook`, two migrations, `CashBookTest`, `CashBookDecisionsTest`, `Phase11RoleMatrixTest`.
Changed: routes, the menu (Money → Cash & bank), the dashboard and the till (the reminder), `PermissionCatalogue`, `DocumentSequenceSeeder`, `PaymentMethod`, `PaymentMethodController` (a new cash method pays into the drawer at once), `ProfitService` and the profit report (cash short or over), `IntegrityCheckCommand`, the note under the Closing summary report, `phpunit.xml`, two older tests that counted permissions, and the docs.

## 3. Database changes
Two additive migrations.
- `2026_11_11_900000_create_cash_book_tables`: `money_accounts`, `money_transactions`, `day_closings`, the column `payment_methods.money_account_id`, the document numbers CBK- (entries) and DCL- (closings), the permissions.
- `2026_11_11_900100_cash_book_owner_decisions`: `users.money_account_id` (a person's own drawer); `money_account_id` on `customer_payments`, `supplier_payments` and `expenses`, filled for the rows that exist and written for new rows by four small database triggers; `bank_reconciliations` and `bank_reconciliation_items`; the document number REC-; the permission `cashbook.reconcile`.

Six permissions are new in all (98 in the catalogue). No amount, stock figure or balance of an existing row is changed.

## 4. Who may do what
| | Administrator | Manager | Cashier | Technician |
|---|---|---|---|---|
| See accounts, balances, books, closings, statements | yes | yes | no | no |
| Move money, owner money in / out | yes | yes | no | no |
| Count a drawer and close the day | yes | yes | no | no |
| Match a bank statement | yes | yes | no | no |
| Cancel an entry, a closing or a statement (password) | yes | yes | no | no |
| Accounts, opening balances, drawers of people, where a method pays into | yes | no | no | no |
| See the "not counted" reminder | yes | yes | yes (on the till, without the link) | no |

## 5. For you to do (in this order, with the shop closed)
1. **Backup Now** on the Backups page.
2. In the program folder: `php artisan migrate --force`, then `npm run build`, then `php artisan optimize`.
3. Open **Money → Cash & bank → Accounts**. Check the account names and where each payment method pays into. Add your real bank accounts if you have more than one.
4. If you want a drawer per counter: add a cash account for each counter and give it to its cashier on the same page.
5. Count each drawer and read your bank and wallet balances, then enter each as an **opening balance**.
6. From then on: **Close the day** every evening; record every bank deposit as a transfer the moment it is made; match the bank statement when it arrives.

## 6. Tests
136 tests for this phase, all passing (run with `C:\xampp\php\php.exe`): default accounts and method links; balances from sales and expenses and after a cancellation; the book with a brought-forward balance; transfers, owner money, opening balances; every refused entry; exact, short and over closings, by notes and by total; cancelling only the latest closing; cash landing in the drawer of the person who took it and staying there when they are given another; a corrected expense following its payment method; the reminder appearing for an earlier day and disappearing after the count, for a manager, a cashier and a technician; cash short and over in net profit and not in gross; a statement refused until it adds up, the next statement starting from the last, a line matched once only, back-dated entries refused, only the latest statement cancelled; the integrity check catching a tampered closing and a matched line cancelled afterwards; every screen; every route against every role.
The result of the whole suite (all earlier phases too) is in section 8.
Not tested: two people closing the same drawer, or saving the same statement, at the same instant with real parallel processes (earlier phases did this for sales and returns). The code takes row locks as they do, but the parallel drill itself was not written.

## 7. Still open
- A cancelled sale or expense that was already on a closed statement is **reported** by the integrity check, not prevented. Preventing it would mean changing the cancel actions of sales, payments and expenses. Say if you want that.
- Statements are ticked by hand. Reading the bank's Excel or PDF file and ticking automatically (as the ERP does) is not built.
- A drawer belongs to a **person**, not to a PC: if two cashiers share one counter on different shifts, give both the same drawer.
