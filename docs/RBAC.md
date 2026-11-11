# Roles, Permissions & Access Control (RBAC)

**Version:** 1.0 (Phase 1) · Implementation: `spatie/laravel-permission` + Laravel Policies

---

## 1. Model

- **Permissions** are fine-grained, named `module.action`, and seeded from a single source (`database/seeders/PermissionSeeder.php`). The admin cannot create new permission *names* from the UI, because code checks them. The admin can assign them freely.
- **Roles** are bundles of permissions. The four default roles are seeded and the admin can edit them. Custom roles can be created.
- A user normally has one role. Direct per-user permissions are supported for exceptions, such as one cashier who may process refunds.
- **Admin** passes every check through `Gate::before`. Two things still apply to the Admin: password re-confirmation for sensitive actions, and the "last active admin" protection.
- Enforcement is layered:
  1. Route middleware `can:` (coarse).
  2. Policy methods (record-level: ownership, assignment, document status).
  3. Query scopes: technicians see only their jobs; "own sales only" users see only their invoices.
  4. Field-level: cost and profit columns are removed from views, JSON and exports without `products.view_cost` / `reports.profit`.
- The UI hides what a user cannot do, but **the server always re-checks**.

---

## 2. Permission catalogue

| Module | Permission | Meaning |
|---|---|---|
| Dashboard | `dashboard.view` | See the dashboard (counts, sales) |
| | `dashboard.financials` | See profit, cost, payables and receivables cards |
| POS / Sales | `pos.access` | Open the POS screen |
| | `sales.create` | Complete sales |
| | `sales.view_all` | See all invoices (otherwise own only) |
| | `sales.view_own` | See own invoices |
| | `sales.hold` | Hold and resume carts |
| | `sales.reprint` | Reprint invoices |
| | `sales.void` | Void a sale within the void window |
| | `sales.discount` | Give discounts up to the configured max % |
| | `sales.discount_unlimited` | Give discounts above the max % |
| | `sales.change_price` | Change a line's unit price (not below minimum) |
| | `sales.below_min_price` | Sell below the minimum sale price |
| | `sales.credit` | Make credit / partial-payment sales |
| | `sales.override_credit_limit` | Exceed a customer's credit limit |
| | `sales.approve` | Act as approver for POS manager-approval requests |
| Sales returns | `sale_returns.view` | View returns |
| | `sale_returns.create` | Create returns (settled to due or store credit) |
| | `sale_returns.refund` | Pay cash or bank refunds |
| Customers | `customers.view` | List and view profiles |
| | `customers.create` / `customers.edit` / `customers.delete` | CRUD (delete = soft, only with no history) |
| | `customers.adjust_balance` | Post a balance adjustment entry |
| | `customers.statement` | Print statements |
| Customer payments | `customer_payments.view` | |
| | `customer_payments.create` | Collect payments |
| | `customer_payments.void` | Void a payment (correction) |
| Products | `products.view` | |
| | `products.create` / `products.edit` / `products.delete` | |
| | `products.edit_price` | Change sale, wholesale or minimum price |
| | `products.view_cost` | See purchase and average cost, margins |
| | `products.import` | Bulk import |
| | `catalog.manage` | Categories, brands, units |
| Inventory | `inventory.view` | Stock levels, stock card, movements |
| | `inventory.adjust` | Post stock adjustments and counts |
| | `inventory.void_adjustment` | Void an adjustment |
| Suppliers | `suppliers.view` / `.create` / `.edit` / `.delete` | |
| | `suppliers.adjust_balance` | |
| Purchases | `purchases.view` | |
| | `purchases.create` | Create and edit drafts |
| | `purchases.receive` | Post (receive) a purchase |
| | `purchases.void` | Void a received purchase |
| Purchase returns | `purchase_returns.view` / `.create` | |
| Supplier payments | `supplier_payments.view` / `.create` / `.void` | |
| Service | `service_jobs.view_all` | See all jobs |
| | `service_jobs.view_assigned` | See jobs assigned to me |
| | `service_jobs.create` | Receive a job (intake) |
| | `service_jobs.edit` | Edit job details (device, problem, diagnosis, notes) |
| | `service_jobs.assign` | Assign or reassign a technician |
| | `service_jobs.change_status` | Move along the state machine |
| | `service_jobs.manage_parts` | Add and remove parts (affects stock) |
| | `service_jobs.set_charges` | Set charges, estimate, discount |
| | `service_jobs.deliver` | Invoice and deliver, collect the final payment |
| | `service_jobs.cancel` | Cancel a job |
| | `service_types.manage` | |
| Expenses | `expenses.view` | |
| | `expenses.create` | |
| | `expenses.edit` | Same-day edit |
| | `expenses.void` | |
| | `expense_categories.manage` | |
| Cash & bank | `cashbook.view` | Accounts, balances and the book of each account |
| | `cashbook.transact` | Transfers (bank deposits), owner money in or out |
| | `cashbook.close_day` | Count the drawer and close the day |
| | `cashbook.reconcile` | Match a bank or wallet account against its statement |
| | `cashbook.void` | Cancel an entry, a day closing or a statement matching |
| | `cashbook.manage` | Accounts, opening balances, drawers of people, where each payment method pays into |
| Reports | `reports.sales` | Sales, returns, payment-method reports |
| | `reports.inventory` | Stock, valuation*, movements |
| | `reports.purchases` | Purchases and returns |
| | `reports.customers` | Balances, statements, aging |
| | `reports.suppliers` | Balances, statements, payables |
| | `reports.service` | Jobs, technician performance |
| | `reports.expenses` | |
| | `reports.profit` | Gross and net profit, margins |
| | `reports.export` | PDF/CSV/XLSX export |
| Users & roles | `users.view` | |
| | `users.manage` | Create, edit, deactivate users, reset passwords |
| | `roles.manage` | Create and edit roles and permissions |
| Settings | `settings.view` | |
| | `settings.manage` | Shop, tax, sales, inventory, receipt, payment methods |
| Backup | `backups.view` | Backup history and health |
| | `backups.create` | Backup Now |
| | `backups.download` | Download a backup file (+ password confirm) |
| | `backups.verify` | Run verification |
| | `backups.delete` | Delete a backup file |
| | `backups.restore` | Restore (+ password confirm, typed confirmation) |
| | `backups.settings` | Schedule, retention, destinations, encryption |
| Audit | `audit_logs.view` | View and export the audit log |

\* The stock valuation report shows costs, so it also requires `products.view_cost`.

---

## 3. Default role matrix

✅ = granted by default · — = not granted · ◐ = granted, with the limit noted

| Permission | Admin | Manager | Cashier | Technician |
|---|:-:|:-:|:-:|:-:|
| dashboard.view | ✅ | ✅ | ✅ | ✅ (own jobs view) |
| dashboard.financials | ✅ | ✅ | — | — |
| pos.access | ✅ | ✅ | ✅ | — |
| sales.create | ✅ | ✅ | ✅ | — |
| sales.view_all | ✅ | ✅ | — | — |
| sales.view_own | ✅ | ✅ | ✅ | — |
| sales.hold | ✅ | ✅ | ✅ | — |
| sales.reprint | ✅ | ✅ | ✅ | — |
| sales.void | ✅ | ✅ | — | — |
| sales.discount | ✅ | ✅ | ◐ up to `pos.max_discount_percent` (default 5%) | — |
| sales.discount_unlimited | ✅ | ✅ | — (manager approval) | — |
| sales.change_price | ✅ | ✅ | — (manager approval) | — |
| sales.below_min_price | ✅ | — (admin approval) | — | — |
| sales.credit | ✅ | ✅ | ✅ (within credit limit) | — |
| sales.override_credit_limit | ✅ | ✅ | — | — |
| sales.approve | ✅ | ✅ | — | — |
| sale_returns.view | ✅ | ✅ | ✅ | — |
| sale_returns.create | ✅ | ✅ | — | — |
| sale_returns.refund | ✅ | ✅ | — | — |
| customers.view / create / edit | ✅ | ✅ | ✅ | ◐ view only, through assigned jobs |
| customers.delete | ✅ | ✅ | — | — |
| customers.adjust_balance | ✅ | — | — | — |
| customers.statement | ✅ | ✅ | ✅ | — |
| customer_payments.view / create | ✅ | ✅ | ✅ | — |
| customer_payments.void | ✅ | ✅ | — | — |
| products.view | ✅ | ✅ | ✅ | ✅ |
| products.create / edit | ✅ | ✅ | — | — |
| products.delete | ✅ | ✅ | — | — |
| products.edit_price | ✅ | ✅ | — | — |
| products.view_cost | ✅ | ✅ | — | — |
| products.import | ✅ | ✅ | — | — |
| catalog.manage | ✅ | ✅ | — | — |
| inventory.view | ✅ | ✅ | ✅ (stock levels only) | ✅ (stock levels only) |
| inventory.adjust | ✅ | ✅ | — | — |
| inventory.void_adjustment | ✅ | — | — | — |
| suppliers.view / create / edit | ✅ | ✅ | — | — |
| suppliers.delete, suppliers.adjust_balance | ✅ | — | — | — |
| purchases.view / create / receive | ✅ | ✅ | — | — |
| purchases.void | ✅ | ✅ | — | — |
| purchase_returns.view / create | ✅ | ✅ | — | — |
| supplier_payments.view / create | ✅ | ✅ | — | — |
| supplier_payments.void | ✅ | ✅ | — | — |
| service_jobs.view_all | ✅ | ✅ | ✅ | — |
| service_jobs.view_assigned | ✅ | ✅ | ✅ | ✅ |
| service_jobs.create | ✅ | ✅ | ✅ | ✅ |
| service_jobs.edit | ✅ | ✅ | ✅ (intake fields) | ✅ (assigned; diagnosis/notes) |
| service_jobs.assign | ✅ | ✅ | ✅ | — |
| service_jobs.change_status | ✅ | ✅ | ✅ (to Delivered via deliver) | ✅ (assigned jobs, not Delivered/Cancelled) |
| service_jobs.manage_parts | ✅ | ✅ | — | ✅ (assigned) |
| service_jobs.set_charges | ✅ | ✅ | — | ✅ (assigned; estimate and charges) |
| service_jobs.deliver | ✅ | ✅ | ✅ | — |
| service_jobs.cancel | ✅ | ✅ | — | — |
| service_types.manage | ✅ | ✅ | — | — |
| expenses.view / create | ✅ | ✅ | — (owner decision, Phase 8b: cashiers do not see or record expenses; an administrator can grant it to a role) | — |
| expenses.edit / void | ✅ | ✅ | — | — |
| expense_categories.manage | ✅ | ✅ | — | — |
| cashbook.view / transact / close_day / reconcile / void | ✅ | ✅ | — | — |
| cashbook.manage | ✅ | — | — | — |
| reports.sales / inventory / purchases / customers / suppliers / service / expenses | ✅ | ✅ | — | — |
| reports.profit | ✅ | ✅ | — | — |
| reports.export | ✅ | ✅ | — | — |
| users.view | ✅ | ✅ | — | — |
| users.manage, roles.manage | ✅ | — | — | — |
| settings.view | ✅ | ✅ | — | — |
| settings.manage | ✅ | — | — | — |
| backups.view / create | ✅ | ✅ | — | — |
| backups.download / verify / delete / restore / settings | ✅ | — | — | — |
| audit_logs.view | ✅ | — | — | — |

> **Open question for the owner:** should managers see profit and cost? The spec lists "reports" for Manager, so the matrix grants it. Remove `products.view_cost`, `reports.profit` and `dashboard.financials` from Manager if the owner wants profit visible only to themselves. See [DESIGN_REVIEW.md](DESIGN_REVIEW.md) Q8.

---

## 4. Record-level rules (Policies)

| Resource | Rule |
|---|---|
| Sale | `view`: `sales.view_all`, or `sales.view_own` when `sale.user_id == me`. `void`: `sales.void` and status=completed, no returns, within the window. |
| Customer payment | `void`: permission, status=posted, and the payment is not the source of a refund already given |
| Purchase | `update`/`delete`: only when status=draft. `receive`: draft only. `void`: received and stock sufficient |
| Service job | `view`/`update` for technicians: `technician_id == me`. Locked when Delivered or Cancelled (no updates except notes by managers). Parts changes are allowed only in permitted statuses |
| Expense | `update`: `expenses.edit`, status=posted and `created_at` is today. Only roles that hold `expenses.view` see expenses at all |
| User | Cannot deactivate or demote yourself. Cannot remove the last active Admin. Only an Admin can assign the Admin role |
| Role | The Admin role cannot be deleted or reduced. Roles in use cannot be deleted |
| Backup | Download, delete and restore require a password confirmation within 10 min. Delete is not allowed for `is_protected` or for the newest successful verified backup |

---

## 5. Manager approval on POS (SRS AUTH-09)

1. A cashier attempts an action that needs a permission they do not have, such as a 15% discount.
2. The POS opens an "Approval required" modal. A manager enters a username and password, or a 6-digit approval PIN if that is enabled later.
3. The server checks that the approver has both `sales.approve` and the specific permission. It then issues a **single-use approval token** valid for 2 minutes, bound to the cashier, the action type and the value. For example: discount ≤ 15% on the cart with idempotency key X.
4. `CompleteSale` verifies and consumes the token, stores `sales.approved_by`, and audits "discount 15% approved by Manager M for cashier C".
5. Failed approval attempts are throttled and audited like logins.

---

## 6. Testing requirements
- A data-driven test iterates over every route × each default role and asserts 200/403 against this matrix.
- Policy tests cover the record-level rules in §4.
- An export test asserts that cost and profit columns are absent for roles without `products.view_cost` / `reports.profit`.

---

## 7. Phase 4 route map (as enforced)

Every route below is protected twice: by route middleware (`can:`) and, for the actions, again inside the controller. "Password" means the user must have re-typed their password in the last 10 minutes (`password.confirm`). Tests: `Phase4RoleMatrixTest` runs every route against all four default roles.

| Screen / action | Permission | Password |
|---|---|:-:|
| Purchases list, view, print, product lookup | `purchases.view` | |
| New / edit / delete a **draft** | `purchases.create` | |
| Receive (also "Save & receive") | `purchases.receive` (checked before anything is saved) | |
| Cancel a received purchase | `purchases.void` | ✅ |
| Supplier payments list, view | `supplier_payments.view` | |
| Record a payment, apply an advance | `supplier_payments.create` | |
| Cancel a payment | `supplier_payments.void` | ✅ |
| Supplier profile, account statement, printed statement | `suppliers.view` | |
| Opening balance / balance correction | `suppliers.adjust_balance` (Admin only) | ✅ |
| Payment methods: view (Settings tab) | `settings.view` | |
| Payment methods: add, rename, switch off, delete | `settings.manage` (Admin only) | |
| Dashboard "Owed to suppliers" tile | `dashboard.financials` **and** `suppliers.view` | |

Cashier and Technician hold none of the purchase, supplier-payment or supplier permissions, so they cannot reach any of these screens and never see purchase costs or what the shop owes.

---

## 8. Phase 5 route map (as enforced)

Every route is protected by route middleware (`can:`) and again inside the action that does the work. "Password" means the user re-typed their password in the last 10 minutes. Tests: `Phase5RoleMatrixTest` runs every route against all four default roles; `PosWebTest` covers the rest.

| Screen / action | Permission | Password |
|---|---|:-:|
| Open the till, product search, quick picks | `pos.access` | |
| Complete a sale (the action re-checks), ask a manager for approval | `sales.create` | |
| Hold, list, resume, discard own held carts | `sales.hold` (and only your own carts) | |
| Sales list and invoice page | `sales.view_all`, or `sales.view_own` (then only your own invoices) | |
| Reprint receipt / A4 invoice, download PDF (marked DUPLICATE, audited) | `sales.reprint` (plus the right to see that invoice) | |
| Cancel a sale | `sales.void` (managers within the void window; an administrator any time) | ✅ |
| Original receipt right after a sale | a signed link, valid ten minutes | |
| Change a price, use the wholesale list | `sales.change_price`, or a manager's approval | |
| Discounts / above the limit | `sales.discount` / `sales.discount_unlimited`, or a manager's approval | |
| Sell below the lowest price | `sales.below_min_price` (Administrator), or an administrator's approval | |
| Be the one who approves at the till | `sales.approve` (Manager, Administrator) | their own password |
| See cost and profit on an invoice | `products.view_cost` | |

Cashiers can sell, hold, reprint and see **their own** sales; they cannot cancel a sale, change a price, give a large discount or see cost, profit or other people's invoices. Technicians have none of these.

## 9. Phase 6 route map (as enforced)

Every route is protected by route middleware (`can:`) and again inside the action that does the work. "Password" means the user re-typed their password in the last 10 minutes. Tests: `Phase6RoleMatrixTest` runs every route against all four default roles (88 cases); `CustomerWebTest`, `CustomerPaymentWebTest` cover the rest.

| Screen / action | Permission | Password |
|---|---|:-:|
| Customer list and profile (names, phones, codes) | `customers.view` | |
| See balances, credit limits, advances, open invoices and payment history on those screens | `customer_payments.view` (otherwise the money is left out) | |
| Add a customer (page or the till's quick add) | `customers.create` | |
| Edit, activate / deactivate a customer | `customers.edit` | |
| Change **credit terms and price list** | `sales.override_credit_limit` (a cashier's edit keeps the existing terms) | |
| Delete a customer (only one with no history) | `customers.delete` | ✅ |
| Opening balance, balance adjustment | `customers.adjust_balance` (Administrator only) | ✅ |
| Statement (page and PDF) | `customers.statement` **and** `customer_payments.view` | |
| Collected payments list and one payment | `customer_payments.view` | |
| Collect a payment, apply a customer's advance to invoices | `customer_payments.create` | |
| Cancel a collected payment | `customer_payments.void` (managers) | ✅ |
| Original payment receipt | a signed link, valid ten minutes | |
| Reprint a payment receipt (marked DUPLICATE, audited) | `sales.reprint` (and `customer_payments.view`) | |
| "Who owes us" aging report | `reports.customers` | |
| Customer search and quick add at the till | `pos.access` with `customers.view` / `customers.create` | |
| Sell on credit (part or none paid now) | `sales.credit`, or a manager's approval | |
| Go over a customer's credit limit | `sales.override_credit_limit`, or a manager's approval | |
| Dashboard tile "Owed by customers" | `dashboard.financials` and `customers.view` | |

Cashiers can add customers, see balances, sell on credit within a limit and collect payments; they cannot give credit terms, cancel a payment, delete a customer, set an opening balance or open the aging report. Technicians can find customers (names and phones) and nothing else: no balances, no payments, no statements.

## 10. Phase 7 route map (as enforced)

Every route is protected by route middleware (`can:`) and again inside the action that does the work. On top of the permission, **a person with only `service_jobs.view_assigned` (the Technician) may touch only the jobs assigned to them** (`ServiceJobAccess`). Tests: `Phase7RoleMatrixTest` runs every route against all four default roles (120 cases, with a technician working on their own job) plus a test that a technician is refused on every route of someone else's job and a guest test; `ServiceWebTest` covers the rest.

| Screen / action | Permission | Technician |
|---|---|---|
| Job list and job page | `service_jobs.view_all`, or `view_assigned` (then only your own jobs) | own jobs only |
| Receive a device | `service_jobs.create` | allowed: the job is assigned to them |
| Edit the card (contact, device, problem, promised date, notes for the customer) | `service_jobs.edit` | own jobs |
| Diagnosis and internal notes | `service_jobs.edit` **and** `service_jobs.manage_parts` | own jobs |
| Assign a technician | `service_jobs.assign` | no |
| Move the status on, reopen | `service_jobs.change_status` (the stored status decides) | own jobs |
| Add / take off a part (stock moves) | `service_jobs.manage_parts` | own jobs |
| Charges, estimate, discount, a different price for a part | `service_jobs.set_charges` | own jobs |
| Take an advance | `customer_payments.create` | no |
| Deliver and invoice | `service_jobs.deliver`; credit needs `sales.credit` (and `sales.override_credit_limit` over the limit) | no |
| Cancel a job | `service_jobs.cancel` (managers) | no |
| Original slip / invoice | a signed link, valid ten minutes | |
| Reprint slip or invoice, invoice PDF (marked DUPLICATE, audited) | `sales.reprint` (and the right to see that job) | no |
| Part picker, customer picker (names and phones only, never cost or money) | `service_jobs.manage_parts`, `service_jobs.create` | allowed |
| See paid, due and the receipts on a job | `customer_payments.view` | no |
| See the cost of parts | `products.view_cost` | no |
| Service types (add, edit, switch off) | `service_types.manage` (managers) | no |
| Dashboard tile | `service_jobs.view_all` or `view_assigned` ("My service jobs" for a technician) | own jobs |

Cashiers receive devices, assign technicians, move jobs on, take advances and **deliver and invoice**; they cannot add parts, set charges or cancel. Technicians work on their own jobs (status, parts, charges, diagnosis) and never see money or cost.

## 11. Phase 8 route map (as enforced)

Every route is protected by route middleware (`can:`) and again inside the action that does the work (returns, expenses). Reports check the permission of the report in the controller and cut out the columns the person may not see. Tests: `Phase8RoleMatrixTest` runs every route against all four default roles (163 cases) plus tests of the default permission sets, of every report against cashiers and technicians, and a guest test; `ReportsTest` covers cost, profit and export visibility, `ExpenseTest`, `SaleReturnTest` and `PurchaseReturnTest` cover the pages.

| Screen / action | Permission | Cashier | Technician |
|---|---|---|---|
| Expenses: list, one expense, its bill, the categories | `expenses.view` (administrators and managers; **not cashiers**, Phase 8b) | no | no |
| Record an expense | `expenses.create` (administrators and managers) | no | no |
| Correct an expense (the day it was entered), manage categories | `expenses.edit` (managers) | no | no |
| Cancel an expense (reason and password) | `expenses.void` (managers) | no | no |
| Sales returns: list and one return | `sale_returns.view` | yes | no |
| Take goods back | `sale_returns.create` (managers) | no | no |
| Hand the money back (instead of store credit or taking it off the debt) | `sale_returns.refund` (managers) | no | no |
| Cancel a sales return (reason and password) | `sales.void` (managers) | no | no |
| Return receipt | an original opens through a signed link for ten minutes; a copy needs `sales.reprint` and `sale_returns.view` and is marked DUPLICATE and audited | copy only | no |
| Purchase returns: list, one return, return note | `purchase_returns.view` (managers) | no | no |
| Send goods back to a supplier | `purchase_returns.create` (managers); receiving a refund also needs `supplier_payments.create` | no | no |
| Cancel a purchase return (reason and password) | `purchases.void` (managers) | no | no |
| Reports hub and each report | the report's own permission: `reports.sales`, `reports.inventory`, `reports.purchases`, `reports.customers`, `reports.suppliers`, `reports.service`, `reports.expenses`, `reports.profit` (managers) | no | no |
| Cost columns inside a report | `products.view_cost` | no | no |
| Profit columns and the profit report | `reports.profit` | no | no |
| Print a report | the report's own permission | no | no |
| Export a report to PDF, Excel or CSV | `reports.export` **and** the report's permission; every export is audited | no | no |
| Dashboard "Business at a glance" (invoiced, returns, service) and the latest sales | `sales.view_all` | no | no |
| Dashboard money cards, latest payments and expenses | `dashboard.financials` (and `customer_payments.view` / `expenses.view` for the lists) | no | no |
| Dashboard profit cards and chart | `reports.profit` | no | no |
| Technician's "My open jobs" | `service_jobs.view_assigned` without `view_all` | no | yes |

A role built by the administrator can hold any of these on its own: the cost and profit permissions are the three that must never reach a Cashier or Technician (`PermissionCatalogue::COST_PROFIT`).

## 12. Phase 8b: the owner's decisions (as enforced)

| Change | Permission / rule | Cashier | Technician |
|---|---|---|---|
| Expenses are closed to cashiers | the Cashier role no longer holds `expenses.view` / `expenses.create` (removed from the defaults **and**, for an existing installation, by the migration). Enforced on the server (routes and actions, 403), and on the screens: no menu entry, no dashboard card or list, and the expense columns of the money-by-method report and the closing summary need `expenses.view` too. An administrator can still grant the two permissions to any role | no | no |
| Take goods back after the return period (default 7 days) | **`sale_returns.override_period`** (new; Manager and Administrator). The person must also write a reason (3 letters or more); it is stored on the return and audited (`sale_return_period_exception`). A role that may create returns but lacks this permission is held to the period | no | no |
| Exchange (return plus replacement sale in one step) | needs **both** `sale_returns.create` and `sales.create`; paying the returned value back as money (mode "refund") also needs `sale_returns.refund` | no | no |
| Use supplier credit on bills | `supplier_payments.create` | no | no |
| Take a use of supplier credit back | `supplier_payments.void`, with the password and a reason | no | no |
| Change the return period or the restocking fee | `settings.manage` (Administrator) | no | no |

`Phase8RoleMatrixTest` was updated (expense routes are now managers only, 92 permissions in the catalogue); `ExpenseTest` proves a cashier and a technician get 403 on every expense route and see nothing on the dashboard.
## 13. Phase 11 route map: cash & bank (as enforced)

| Route | Permission | Manager | Cashier / Technician |
|---|---|---|---|
| `GET /cash-book`, `/cash-book/accounts/{id}` (and `/print`), `/cash-book/entries`, `/cash-book/entries/{id}`, `/cash-book/closings`, `/cash-book/closings/{id}` | `cashbook.view` | yes | no |
| `GET /cash-book/entries/create`, `POST /cash-book/entries` | `cashbook.transact` for a transfer or owner money; `cashbook.manage` for an opening balance (checked in the controller **and** in the action) | yes, except opening balances | no |
| `POST /cash-book/entries/{id}/void` | `cashbook.void` + password | yes | no |
| `GET /cash-book/closings/create`, `POST /cash-book/closings` | `cashbook.close_day` | yes | no |
| `POST /cash-book/closings/{id}/void` | `cashbook.void` + password; only the latest closing of the account | yes | no |
| `GET /cash-book/setup`, `POST /cash-book/accounts`, `PUT /cash-book/accounts/{id}`, `POST /cash-book/accounts/{id}/toggle` | `cashbook.manage` | no | no |
| `PUT /cash-book/methods/{id}` (which account a payment method pays into) | `cashbook.manage` + password | no | no |

The book of an account links each line to its document only for people who may open that document (`customer_payments.view`, `supplier_payments.view`, `expenses.view`). `Phase11RoleMatrixTest` runs every route against every default role and proves each permission works on its own; the catalogue now holds 98 permissions.

### Added with the owner's decisions (2026-11-11)

| Route | Permission | Manager | Cashier / Technician |
|---|---|---|---|
| `GET /cash-book/statements`, `/cash-book/statements/{id}` | `cashbook.view` | yes | no |
| `POST /cash-book/statements`, `PUT /cash-book/statements/{id}` (save ticks, close) | **`cashbook.reconcile`** (new; Manager and Administrator), checked in the routes and in the service | yes | no |
| `POST /cash-book/statements/{id}/void` | `cashbook.void` + password; only the latest statement of the account | yes | no |
| `PUT /cash-book/people/{user}/drawer` (a person's own cash drawer) | `cashbook.manage` | no | no |
| The "drawer not counted" reminder on the dashboard and the till | shown to `cashbook.close_day` (with the link) and to `pos.access` (without it) | yes | cashier: yes, technician: no |
