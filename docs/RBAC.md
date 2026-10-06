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
| expenses.view / create | ✅ | ✅ | ◐ create only (petty cash), sees own | — |
| expenses.edit / void | ✅ | ✅ | — | — |
| expense_categories.manage | ✅ | ✅ | — | — |
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
| Expense | `update`: `expenses.edit`, status=posted and `created_at` is today. Cashiers see only expenses they created |
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
