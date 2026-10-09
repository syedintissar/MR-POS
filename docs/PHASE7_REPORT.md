# Phase 7 Report: Service, Repair & Refill Jobs

**Date:** 2026-10-09 · **Status:** complete and validated · **Waiting for:** owner approval before Phase 8 (Returns, expenses, profit & reports)

Phases 1 to 6 are committed (the latest is `41c5065`, Phase 6). Phase 7 is **not yet committed**: review it with `git status` and `git diff`, or tell me to commit it.

---

## 1. What was implemented

**Receiving a device (Service → Service jobs → Receive a device)**
- Customer search (name, phone, code) or a **walk-in customer** (then a name and phone are required). Service type, device kind, brand, model, serial number, cartridge model, the problem reported, accessories received, promised date, priority, technician, estimate (people who may set charges), note for the customer. The service's **usual charge** (for example a toner refill) is added as the first line when the box is ticked. An **advance** can be taken in the same step.
- The job gets its number (`JOB-000001…`) and a **job slip** prints (80 mm or A4) with a barcode and the shop's service terms. Reprints are marked DUPLICATE and recorded.

**Finding and following jobs**
- Tabs: Open · **Ready to collect** · Overdue · Delivered · Cancelled · Everything; search by job number, invoice number, name, phone, serial number or model; **typing or scanning a job number opens the job**. Overdue badge, urgent badge, dashboard tile ("Service jobs", or "My service jobs" for a technician), and the customer's page lists their jobs.
- A **technician sees only the jobs assigned to them** (and no money, no cost); cashiers, managers and administrators see all.

**The status flow (the state machine)**
- Received → Inspection pending → Diagnosing → Waiting for approval → Approved → In progress ⇄ Waiting for parts → Completed, with Delivered and Cancelled as the two ends. Every pair of statuses is tested against the diagram. The customer's approval is recorded (in person / phone / WhatsApp) with the approved estimate; a completed job can be **reopened with a reason**. Every move writes a history line that the database refuses to edit.

**Parts, charges and prices**
- **Parts leave the stock immediately** at the average cost of that moment (the cost is written on the line for the profit figures); the part is billed at the product's price (the wholesale price for a wholesale customer). **Taking a part off** puts it back in stock, or **writes it off** (with a reason) when it cannot be reused.
- Charge lines (labour, refill), an estimate and a discount on the whole job; tax follows the shop setting. Everything is recomputed in one place, and a discount larger than the job is refused.

**Money**
- **Advances** are numbered receipts that pay the job (never more than the job is worth) and credit the customer's account (the walk-in customer has no account).
- **Deliver and invoice:** the service invoice number (`SRV-000001…`), the job total on the customer's account, the final payment (split over methods, the customer's own credit, **change for cash**), what is left stays on the account of a named customer who may have credit and is within the limit (a manager is needed otherwise); the walk-in customer pays in full. **The job is then locked.** The invoice prints (80 mm, A4, PDF).
- **A job left on account is collected like an invoice:** *Collect a payment* now settles invoices **and** delivered jobs, oldest first or as chosen; the aging report, the statement and the customer page list both; cancelling a payment reopens the job.
- **Cancel** (managers): every part goes back to stock or is written off, and the advance is kept as the customer's credit or handed back (the walk-in customer always gets it back).

**Service types** (managers): the eleven types of the brief, each with an optional usual charge; a used type is switched off, never deleted.

**Look and feel:** the new screens follow the light theme and animations introduced in Phase 6.

## 2. Files created / modified
About 60 new or changed files outside the docs: roughly 2,700 lines of application code, 1,100 lines of views, 40 lines of seeders and 120 of migration, and 3,000 lines of tests.

| Area | Files | Highlights |
|---|---|---|
| `app/Actions/Service` | 10 | `ReceiveServiceJob`, `UpdateServiceJob`, `AssignServiceJob`, `ChangeServiceJobStatus`, `AddServicePart`, `RemoveServiceLine`, `AddServiceCharge`, `UpdateServicePricing`, `TakeServiceAdvance`, `DeliverServiceJob`, `CancelServiceJob` |
| `app/Services/Service` | 2 | `ServiceJobService` (the only writer of a job's figures and history, and its integrity check), `ServiceJobAccess` (who sees which job) |
| `app/Actions/Customers`, `app/Services/Customers` | changed | collecting, applying an advance and cancelling a payment now handle jobs; `CustomerAccountService` gets open documents, allocations, refunds; `ReceivablesService` lists jobs |
| `app/Http/Controllers` | 3 new + 4 changed | `ServiceJobController`, `ServiceJobWorkController`, `ServiceTypeController`; Customer, CustomerPayment, Dashboard controllers, routes |
| `app/Models`, `database` | 5 models + 1 seeder + 1 migration | see §3 |
| `resources/views` | 10 new + 5 changed | job list, intake / edit form, job page, service types, slip and invoice for 80 mm and A4, sidebar, dashboard, customer pages, aging report |
| `tests` | 7 new | see §6 |
| `docs` | DATABASE §13, DESIGN_REVIEW §10, SRS §6, RBAC §10, USER_GUIDE §4, INSTALLATION, ENVIRONMENT_CHANGES, ROADMAP, README | |

## 3. Database changes
Migration `2026_10_09_600000_create_service_tables` (applied to the real `mrpos` database **after a backup**; the design is in [DATABASE.md §13](DATABASE.md)):
- `service_types` (with the 11 default types), `service_jobs`, `service_job_items`, `service_status_history` (append-only), `service_job_payments`, and `customer_payments.refund_of_id`.
- `CHECK` constraints on statuses, amounts, and the rule that a job has an invoice exactly when it is delivered and is cancelled exactly when it says so.
- Nothing existing was changed or lost.

## 4. Commands you need to run
Nothing for your own database: the migration is already applied there. For any other copy:

```
php artisan migrate --force
npm run build
php artisan optimize:clear
php artisan pos:integrity:check
```

## 5. Manual setup needed from you
1. **Create a Technician user** (Administration → Users, role Technician) for each person who works on devices, and a Cashier for the counter.
2. **Service → Service types:** check the list and type the usual charge of the services you price the same every time (a toner refill, for example).
3. **Settings → Receipt:** read the **service job terms** that print on the slip (default: devices not collected within 30 days are not the shop's responsibility), and Printing → "print the job slip automatically".
4. Print one **job slip** and one **service invoice** on the real printer and tell me about the layout.
5. **The Phase 2 administrator steps are still pending**, and now matter more: **MariaDB is not a Windows service**, so after every restart of the PC it has to be started by hand (this stopped my work once). Run `deploy\windows\install-mariadb-service.ps1` as Administrator, then the scheduler and firewall scripts.

## 6. Tests and checks performed

| Check | Result |
|---|---|
| **Whole suite** (`php artisan test`, real MariaDB schema) | **1,219 passed, 0 failed** (12,325 assertions, about 13 minutes), run after the last change. Phase 7 added about 206 PHP tests (122 of them the role matrix); none of the earlier tests was weakened |
| Job flow (`ServiceJobFlowTest`, 29) | intake rules and numbering, the suggested charge, technician self-assignment, the **whole state machine (every pair of statuses)**, approval, reopening, history cannot be edited even by hand, two people moving a job at once, edit rights (counter vs workshop), technician isolation, parts and stock (cost snapshot, wholesale price, only from diagnosing, not enough stock leaves nothing behind, return and write-off), charges, estimate, discount, tax, locked jobs, integrity check names a tampered job |
| Money (`ServiceMoneyTest`, 26) | advance (pays the job, credits the account, is not free credit, walk-in has no ledger, never more than the job), delivery (ledger exactly as expected, invoice number, locked job, free job, walk-in pays in full and a refused delivery uses up nothing, change, split payments, credit rules and limits, customer credit, excess advance), cancellation (parts back, advance kept or handed back, walk-in, write-off, rules), collecting what was left on account, aging, voiding that payment, the integrity command |
| Web (`ServiceWebTest`, 22) | every page, scanning a job number, intake and its validation, advance at intake, technician scoping and "no money, no cost" for technicians, status, parts, charges, pricing, edit, assign, advance / delivery / cancellation, signed slip and invoice links, DUPLICATE reprints with audit, PDF, pickers without cost or money, service types, dashboard, customer page, collecting a job from the customer page |
| **Authorization** (`Phase7RoleMatrixTest`, 122) | every Phase 7 route against Admin, Manager, Cashier and Technician matches docs/RBAC.md; a technician is refused on every route of someone else's job; guests go to login |
| **Concurrency with real processes** (`ServiceConcurrencyTest`, 6) | three tills delivering one job (one delivery); two jobs of one customer delivered on credit together (only one fits the limit); two workshops promising the last part; an advance racing a cancellation; two people moving one job on; four parts added at once (no deadlock). **Mutation check:** without the customer and job locks the first two fail |
| **Backup and restore with service data** (`ServiceBackupRestoreTest`) | a backup holding jobs, parts, advances, a delivery and a cancellation restores to exactly the backed-up state (counts, balances, advance, stock, invoice numbers); the job made after the backup is gone; the history, status, invoice, product and total protections still refuse bad edits; integrity clean. (Done in the test sandbox on purpose: your real database now holds your administrator, so I did not run a restore drill on it) |
| **Real-browser drill** (headless Edge, real key presses, scratch schema) | **30 checks, all passed:** intake with customer search, suggested charge and advance; walk-in refused without a phone and then received; scanning a job number; a technician sees only their own job and no money, finds a part, adds it and a charge, completes the job; the cashier sees 1,150.00 total / 300.00 paid / 850.00 due, delivers with 1,000 and is told the change is 150.00, reprints the invoice and downloads the PDF; a manager cancels a job; dashboard, service types and customer page. No JavaScript error anywhere |
| Existing suite | All earlier tests still pass (only one expectation about a customer-credit integrity message needed to change meaning, see DESIGN_REVIEW P7-02) |
| Style | Pint clean |

**Problems found and fixed during the phase** (full list in DESIGN_REVIEW §10): a job left on account would have been invisible to the Phase 6 collection, aging and statement screens (now handled together with invoices); a false alarm in the integrity check after a cancelled job's advance was refunded; two slips caught by the first test run (model mass-assignment) before any page was used. **Two things outside the code:** the PC had restarted and MariaDB (not yet a Windows service) was down, which made every test fail until I started it again; and your real database now has your own administrator, so I did not reset it, backed it up before the one additive migration, and ran the drills on the scratch schema.

## 7. Remaining issues and decisions before Phase 8

| # | Item | Needed from you |
|---|---|---|
| 1 | **Approval of Phase 7** | Yes / changes. Also say whether to **commit** it |
| 2 | **Void a delivered job's invoice** (a mistake in amount or customer) | Should a manager be able to cancel a delivered job's invoice like a sale (24 hours, reason, password) and put the job back to "completed"? Not built yet |
| 3 | **Inspection fee** when the customer declines | Wanted? (it was a "Should") |
| 4 | **Technicians and customers** | A technician sees the name and phone of the customers of their own jobs (never money): OK? |
| 5 | **Slip terms and "forgotten jobs"** | Read the terms printed on the slip; after how many days should a finished job count as forgotten? |
| 6 | **Phase 8 priorities:** returns (sales, purchases), expenses, profit and the reports | Which report first? |
| 7 | **Phase 2 administrator steps** (MariaDB service, scheduler, Apache, firewall) | Please run them: MariaDB currently stops when the PC restarts |
| 8 | Real receipt printer test (slip, invoice, customer receipt); your products, prices and opening stock; old MariaDB 10.4 on port 3306 | Carried over from earlier phases |

Known limits (deliberate, tracked): no void of a delivered job's invoice; no inspection fee; the cash handed over and the change on a delivery are shown on screen and kept in the audit trail but not printed on a reprint; no cut-off in days for "forgotten" jobs; a technician's customer list is not limited to their own jobs.

## 8. Final test result
`php artisan test`: **1,219 passed, 0 failed, 12,325 assertions, 785 seconds** (final run, after all changes). Node tests of the till: 46 passed, 1 skipped by design (run inside the PHP suite). Pint clean. `pos:integrity:check` and `pos:doctor` pass on the real database (your administrator, the walk-in customer, 7 payment methods, 11 service types; Phase 7 migration applied after a backup).
