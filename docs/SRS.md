# Software Requirements Specification (SRS)

**Product:** Mr Printer & Tuner — POS, Inventory, Accounting, Service & Shop Management System ("MR-POS")
**Version:** 1.0 (Phase 1 baseline) · **Date:** 2026-10-06 · **Status:** Draft for owner review

Related documents: [ARCHITECTURE.md](ARCHITECTURE.md) · [DATABASE.md](DATABASE.md) · [RBAC.md](RBAC.md) · [BACKUP_RESTORE.md](BACKUP_RESTORE.md) · [DESIGN_REVIEW.md](DESIGN_REVIEW.md) · [ROADMAP.md](ROADMAP.md)

---

## 1. Introduction

### 1.1 Purpose
This document defines what MR-POS must do (functional requirements) and how well it must do it (non-functional requirements). It is the acceptance baseline for every later phase. When code and this document disagree, one of them is a defect.

### 1.2 Business context
Mr Printer & Tuner is a single retail and service shop. It sells printers, toners, cartridges, ink, printer parts, computer accessories and stationery. It also repairs printers, and refills and repairs toners and cartridges. Some customers buy on credit, and some suppliers are paid later.

### 1.3 Scope
**In scope:** one shop with one database server and several browser terminals on the shop LAN, covering the 25 modules listed in §3.

**Out of scope for v1.** None of these are blocked by the design.
- Multiple branches, and sync between branches.
- Online store or e-commerce integration.
- FBR/PRAL real-time invoice integration. The tax fields are designed so it can be added later; see [DESIGN_REVIEW.md](DESIGN_REVIEW.md) Q2.
- Full double-entry general ledger and chart of accounts. Subsidiary ledgers for customers and suppliers are in scope.
- Payroll. Salaries are recorded as expenses only.
- Serial-number inventory, meaning per-unit tracking of stock. A serial number can be recorded free-text on a sale line.
- Urdu UI. All text goes through Laravel's translation system so it can be added later.

### 1.4 Definitions
| Term | Meaning |
|---|---|
| Terminal | Any browser on the LAN that uses MR-POS |
| Server PC | The Windows PC that hosts Apache/PHP, the database and the backups |
| Walk-in customer | The system customer used for anonymous cash sales. It can never carry a balance. |
| Posting | The atomic operation that makes a document financially effective: stock, ledgers and payments are all written in one DB transaction |
| Void | Cancelling a posted document by writing reversing entries. Nothing is deleted. |
| Average cost | Moving weighted-average unit cost per product, used for COGS |
| Ledger | Append-only list of debit/credit entries for a customer or supplier |
| Job card | A service/repair/refill job |

### 1.5 Stakeholders and users
| Role | Primary goals |
|---|---|
| Owner / Admin | Full control, profit visibility, data safety, and auditing staff actions |
| Manager | Day-to-day operations: purchasing, stock, pricing, reports, jobs |
| Cashier | Fast, correct billing; collecting payments; receiving service jobs at the counter |
| Technician | Seeing assigned jobs, recording diagnosis, parts used and status |

---

## 2. Global business rules

These rules apply in every module and override any module-level wording that seems to conflict.

| ID | Rule |
|---|---|
| BR-01 | **Posted financial documents are never edited or hard-deleted.** Sales, purchases, payments, returns, expenses, stock adjustments and invoiced service jobs are corrected only by voiding (reversing entries) or by follow-up documents (returns, adjustments). Each void requires a reason and is audited. |
| BR-02 | **Every stock change writes a stock-movement row** in the same DB transaction. `products.current_stock` is a cache of the movement ledger, and only the Inventory service may change it. |
| BR-03 | **Every change to a customer or supplier balance writes a ledger entry** in the same DB transaction. Balance columns are caches of the ledgers. |
| BR-04 | Every posting operation is a single DB transaction. If any step fails, nothing is saved. |
| BR-05 | Stock may not go below zero unless the admin enables `inventory.allow_negative_stock`. This applies to sales, service-part consumption, purchase returns and adjustments. |
| BR-06 | All money is stored as DECIMAL. No floating-point arithmetic is used for money in PHP either; amounts go through a decimal/integer-paisa helper. |
| BR-07 | Profit uses **cost of goods** (average cost at the time of sale), never revenue alone. |
| BR-08 | Cost prices and profit figures are visible only to users with `products.view_cost` / `reports.profit`. |
| BR-09 | Document numbers are unique, sequential, never reused, and generated inside the posting transaction. |
| BR-10 | Normal operation must never require internet access. |
| BR-11 | The walk-in customer cannot buy on credit and cannot hold a balance. |
| BR-12 | Any action that changes money or stock, and any permission or security change, is written to the audit log. |
| BR-13 | Sales tax is excluded from revenue and profit. It is a liability shown separately. |
| BR-14 | Application timezone: Asia/Karachi. Currency: PKR, symbol "Rs.", 2 decimal places stored, display rounding configurable. |

---

## 3. Functional requirements

Priority: **M** = Must (v1 release blocker), **S** = Should (v1 unless descoped by the owner), **C** = Could (later).

### 3.1 Authentication & session (AUTH)
| ID | Requirement | P |
|---|---|---|
| AUTH-01 | Users log in with **username** (or email) and password. There is no public registration. | M |
| AUTH-02 | Passwords are hashed with bcrypt/argon2id. Minimum length 8, configurable. | M |
| AUTH-03 | Login is throttled per username+IP: 5 failures lock that pair for 60 s, rising with repeated failures. Failed and successful logins are audited. | M |
| AUTH-04 | Inactive users cannot log in, and existing sessions of a deactivated user are terminated. | M |
| AUTH-05 | Sessions expire after configurable idle time (default 8 h for POS, 60 min for admin areas via password re-confirmation). | M |
| AUTH-06 | Sensitive actions (restore, user/role changes, backup download, settings that affect security) require password re-confirmation within the last 10 minutes. | M |
| AUTH-07 | Users can change their own password. Admin can reset any user's password and force a change at next login. | M |
| AUTH-08 | Optional IP/subnet allow-list (CIDR). Requests from outside it are rejected. | S |
| AUTH-09 | Manager approval on POS: a cashier action that needs a higher permission (extra discount, price below minimum, credit-limit override) can be approved by a manager entering their credentials. The action is performed by the cashier, approved by the manager, and both are audited. | S |

### 3.2 Dashboard (DASH)
| ID | Requirement | P |
|---|---|---|
| DASH-01 | Period filter: Today, Yesterday, This week, This month, Custom range. Default is Today. | M |
| DASH-02 | KPI cards: sales (net), invoices count, cash received, credit sales, expenses, purchases, outstanding customer credit (all-time), supplier payables (all-time), open service jobs. | M |
| DASH-03 | Profit cards (gross profit, net profit) are shown only with `reports.profit`. | M |
| DASH-04 | Lists: low-stock products, out-of-stock products, recent sales, recent expenses, recent payments, recent service jobs (latest 10 each, linked). | M |
| DASH-05 | Charts: daily sales for the period; daily gross profit (permission-gated); month-by-month sales for the last 12 months compared with the same months of the previous year. | M |
| DASH-06 | Admin warnings banner: backup overdue, backup verification failed, off-site transfer pending or failed, low disk space. See [BACKUP_RESTORE.md](BACKUP_RESTORE.md) §9. | M |
| DASH-07 | Technicians see a personal dashboard: their assigned jobs grouped by status, plus overdue jobs. | S |

### 3.3 Point of Sale (POS)
| ID | Requirement | P |
|---|---|---|
| POS-01 | A single-screen POS with a product search box that is focused by default. | M |
| POS-02 | Search by name, SKU, barcode or model. Results appear as the user types (≤ 300 ms on the LAN). | M |
| POS-03 | Barcode scanner support (keyboard-wedge/HID). An exact barcode or SKU match followed by Enter adds the item immediately (qty +1 if it is already in the cart). | M |
| POS-04 | Category filter, and a quick-pick grid of favourite/fast-moving products. | M |
| POS-05 | The cart line shows product, qty, unit price, line discount (amount or %), tax and line total. Qty can be edited, decimals are allowed only for units that allow decimals, and stock availability is shown. | M |
| POS-06 | Price is the tier price of the selected customer (retail/wholesale). Changing a line price needs `sales.change_price`. Going below the product's minimum sale price needs `sales.below_min_price` or manager approval. | M |
| POS-07 | Invoice-level discount (amount or %). Discounts up to `pos.max_discount_percent` need `sales.discount`; more than that needs `sales.discount_unlimited` or manager approval. | M |
| POS-08 | Tax is applied per line when tax is enabled in settings (rate from the product or the default). Tax can be configured as inclusive or exclusive. | M |
| POS-09 | Optional round-off of the grand total to the nearest Rs. 1/5/10 (setting). The difference is stored as `round_off`. | S |
| POS-10 | Customer selection with search by name/phone. A quick "add customer" modal. Walk-in is the default. | M |
| POS-11 | Payment panel: one or more payment lines (split payment), each with a configurable payment method (Cash, Bank transfer, Card, Easypaisa, JazzCash, …) and an optional reference number. | M |
| POS-12 | Amount tendered and change are calculated for cash. Change is shown on screen and printed on the receipt. | M |
| POS-13 | Credit and partial payment: if paid < total, the remainder becomes a receivable on the customer's account. This needs a named customer with credit allowed, within the credit limit, unless the user has `sales.override_credit_limit` or manager approval. | M |
| POS-14 | Notes per sale (printed on the invoice when "print notes" is ticked). | M |
| POS-15 | Hold the current cart under a label; list held carts; resume or discard. Prices are re-checked on resume and the cashier is warned of changes. Held carts are not documents and do not reserve stock. | M |
| POS-16 | Cancel (clear) the current unsaved cart, with confirmation. | M |
| POS-17 | Completing a sale atomically does all of the following (BR-04): create the sale and its lines, record payments and allocations, reduce stock and write movements at the current average cost, post the customer ledger, assign the invoice number and audit the sale. | M |
| POS-18 | After completion: print an 80 mm thermal receipt and/or an A4 invoice (the default comes from settings), and start a new sale. | M |
| POS-19 | Reprint any past invoice (`sales.reprint`). The reprint is marked "DUPLICATE" and audited. | M |
| POS-20 | Void a completed sale (`sales.void`, reason required). Allowed only within `sales.void_window_hours` (default 24) and only if the sale has no returns. Voiding reverses stock, ledger and payments. After the window, a sales return must be used. | M |
| POS-21 | Keyboard shortcuts: F2 search, F4 customer, F8 hold, F9 payment, F10 complete, Esc close modal, +/- qty. | S |
| POS-22 | Service (non-stock) items such as "quick toner refill — counter" can be sold on the POS without affecting stock. | M |
| POS-23 | An optional serial number per sale line (for printers under warranty), printed on the invoice. Warranty expiry is calculated from the product's warranty months. | S |
| POS-24 | Several cashiers can sell the same product at the same time without overselling (row locking; see ARCHITECTURE §6.3). | M |
| POS-25 | A cash drawer kick on completion, if the receipt printer supports it via its driver. ESC/POS direct printing is optional. | C |

### 3.4 Products & catalogue (PRD)
| ID | Requirement | P |
|---|---|---|
| PRD-01 | Product fields: name, SKU (unique, auto-suggested), barcode (unique, optional), category (any level of a 2-level tree: category → subcategory), brand, model, description, unit, last purchase price, average cost (system-maintained, read-only), sale price, wholesale price (optional), minimum sale price (optional), current stock (read-only), min stock level (blank = global threshold), rack/location, default supplier, warranty months and terms, image, tax rate override, active flag. | M |
| PRD-02 | Product type: **Stock item** (inventory tracked) or **Service/Non-stock** (no stock, e.g. labour or refill charge). | M |
| PRD-03 | Seeded categories: Printers, Toners, Cartridges, Ink, Printer Parts (subcategories Drums, Blades, Rollers, Fusers, Other parts), Cables, Computer Accessories, Stationery, Services, Other. All are editable. | M |
| PRD-04 | Brands and units (pcs, box, pack, gram, ml, meter, set) are CRUD. Each unit has an "allows decimal" flag; gram and ml allow decimals for toner powder and ink. | M |
| PRD-05 | Changes to sale, wholesale or minimum price need `products.edit_price` and are audited with old and new values. | M |
| PRD-06 | A product with any transaction history cannot be deleted, only deactivated. Inactive products are hidden from the POS but remain in reports. | M |
| PRD-07 | Import products and opening stock from CSV/XLSX: validate first, show row errors, then post. Opening stock is posted as `opening` stock movements with the given cost. | M |
| PRD-08 | Barcode label printing (Code128/EAN-13) for selected products with quantity, sized for common label sheets and label printers. | S |
| PRD-09 | Image upload: jpg/png/webp, max 2 MB, re-encoded server-side, stored outside the web root and served through a controller. | M |

### 3.5 Inventory (INV)
| ID | Requirement | P |
|---|---|---|
| INV-01 | Stock movement ledger per BR-02. Each row records product, qty before, change (+/-), qty after, unit cost, movement type, source document reference, user, timestamp and notes. | M |
| INV-02 | Movement types: opening, purchase, purchase_return, purchase_void, sale, sale_return, sale_void, service_consumption, service_return, adjustment_in, adjustment_out. | M |
| INV-03 | Stock adjustment document (`inventory.adjust`) with reason (count correction, damage, loss/theft, found, expiry, other), lines of products and +/- quantities, and notes. Posted atomically and audited. | M |
| INV-04 | Stock count helper: enter physical counts for a filtered product list. The system computes the differences and creates one adjustment document. | S |
| INV-05 | Product stock card: the movement history with running balance for any period. | M |
| INV-06 | Low-stock (stock ≤ min level and > 0) and out-of-stock (≤ 0) lists. | M |
| INV-07 | Moving weighted-average costing (see ARCHITECTURE §6.4). Average cost is recalculated on purchase receipt, purchase return and restocked sales return. | M |
| INV-08 | A nightly integrity check compares `current_stock` with the sum of movements and reports any mismatch to the admin. It never silently "fixes" anything. | M |

### 3.6 Suppliers & purchases (PUR)
| ID | Requirement | P |
|---|---|---|
| PUR-01 | Supplier fields: code, name, company, contact person, phone, WhatsApp, email, address, NTN, opening balance, notes, active flag. | M |
| PUR-02 | Purchase document: auto number, supplier, supplier invoice no (unique per supplier when entered), purchase date, lines (product, qty, unit cost, line discount, line tax), invoice discount, invoice tax, additional cost (freight/loading), totals, notes. | M |
| PUR-03 | Status flow: **Draft** (editable, no effect) → **Received** (posted: stock increases, average cost updated, supplier ledger credited) → optionally **Voided**. | M |
| PUR-04 | Invoice discount and additional cost are allocated to lines in proportion to line value, giving a **landed unit cost** used for average cost. | M |
| PUR-05 | Payment at the time of purchase (one or more methods) and later supplier payments. Payment status is derived: Unpaid / Partially paid / Paid. | M |
| PUR-06 | A supplier payment can be allocated to one or more purchases. Any unallocated part is an advance to the supplier. | M |
| PUR-07 | Voiding a received purchase is allowed only if every line's quantity can be removed from stock (BR-05). It reverses stock, ledger and allocations, needs a reason, and is audited. | M |
| PUR-08 | Supplier profile: purchases, payments, returns, balance, and a printable supplier statement for a date range. | M |
| PUR-09 | Last purchase price per product per supplier is shown while entering a purchase. | S |

### 3.7 Purchase returns (PRT)
| ID | Requirement | P |
|---|---|---|
| PRT-01 | Return goods to the supplier, against a received purchase (qty ≤ received − already returned) or, for stock that predates the system, without a purchase reference. | M |
| PRT-02 | Stock decreases at the line's landed cost and average cost is recalculated. The supplier ledger is debited, which reduces the payable. | M |
| PRT-03 | Settlement: reduce the payable (default) or record a cash/bank refund received from the supplier. | M |

### 3.8 Customers & receivables (CUS)
| ID | Requirement | P |
|---|---|---|
| CUS-01 | Customer fields: code, name, company, phone (searchable), WhatsApp, email, address, price tier (retail/wholesale), allow credit (yes/no), credit limit (blank = unlimited), opening balance, notes, active flag. | M |
| CUS-02 | The opening balance is posted once as an `opening_balance` ledger entry. Changes after that need an adjustment entry with a reason (`customers.adjust_balance`). | M |
| CUS-03 | Profile tabs: summary (total sales, total paid, returns, outstanding, last payment), sales history, payment history, returns, service jobs, ledger. | M |
| CUS-04 | Collect payment: amount and method. It is allocated automatically oldest-invoice-first (FIFO) or manually per invoice/job. The excess is an on-account advance. A payment receipt is printed. | M |
| CUS-05 | Payment correction is only by voiding the payment (reason required). That reverses the ledger entry and allocations, and a new correct payment is then entered. | M |
| CUS-06 | Printable/PDF customer statement for a date range: opening balance, each entry, running balance, closing balance. | M |
| CUS-07 | Outstanding invoices report and aging report (current, 1–30, 31–60, 61–90, 90+ days) by invoice date. | M |
| CUS-08 | Credit limit check on credit sales (POS-13). | M |
| CUS-09 | Store credit: when enabled, a sales return can credit the customer's account. The credit can then be used as a payment method ("Customer credit") on the next sale. | S |
| CUS-10 | Duplicate detection: warn when creating a customer whose phone number already exists. | S |

### 3.9 Service / repair / refill (SRV)
| ID | Requirement | P |
|---|---|---|
| SRV-01 | Configurable service types: Printer repair, Printer servicing, Toner refill, Cartridge refill, Cartridge repair, Drum replacement, Blade replacement, Printer installation, Troubleshooting, Maintenance, Other. Each has an optional default charge. | M |
| SRV-02 | Job card fields: job no, customer, contact phone, service type, device type (Printer / Toner / Cartridge / Other), brand, model, serial no, cartridge/toner model, reported problem, diagnosis, accessories received, received at, expected delivery, assigned technician, priority, status, estimate, service charges, parts (billed), parts cost (internal), discount, tax, total, advance, paid, balance, internal notes, customer notes. | M |
| SRV-03 | Statuses: Received → Inspection Pending → Diagnosing → Waiting for Approval → Approved → In Progress → Waiting for Parts → Completed → Delivered, plus Cancelled. Allowed transitions are enforced (see DATABASE §7.4). Every change writes a status-history row with user, time and note. | M |
| SRV-04 | Recording customer approval stores the approved estimate, the approval time and the approval method (in person / phone / WhatsApp). | M |
| SRV-05 | Adding a part to a job **immediately** consumes stock (`service_consumption` movement at average cost). Removing a part writes a `service_return` movement. Lines are never deleted. | M |
| SRV-06 | Charge lines (labour, refill charge) are non-stock lines with description and amount. | M |
| SRV-07 | Advance payments can be taken at any status; they are allocated to the job. | M |
| SRV-08 | **Deliver & invoice**: assign the service invoice number, post the customer ledger debit for the job total, take the final payment (full, partial or credit within the limit), and print the service invoice. After delivery the job is locked. | M |
| SRV-09 | Cancel a job (reason required). Consumed parts must be returned to stock or written off (adjustment_out, reason "consumed on cancelled job"). Advances are refunded or kept as account credit. | M |
| SRV-10 | Technicians see and update only jobs assigned to them (`service_jobs.view_assigned`). | M |
| SRV-11 | Printables: job card / customer receipt at intake (with terms and job no barcode), and a service invoice at delivery (A4 and 80 mm). | M |
| SRV-12 | Search jobs by job no, customer, phone, serial no or model. A job no barcode on the intake slip opens the job when scanned. | M |
| SRV-13 | Overdue indicator when the expected delivery date has passed and the job is not Completed or Delivered. | S |
| SRV-14 | Uncollected-jobs list: Completed but not Delivered for more than N days. | S |
| SRV-15 | Message link: a "Send WhatsApp" button that opens a pre-filled `wa.me` message (needs internet on the click only; never blocks the workflow). | C |

### 3.10 Expenses (EXP)
| ID | Requirement | P |
|---|---|---|
| EXP-01 | Configurable expense categories, seeded with Rent, Electricity, Salary, Internet, Transport, Maintenance, Tea/Refreshments, Office expense, Miscellaneous. | M |
| EXP-02 | Expense fields: expense no, category, amount, date, payment method, payee, description, attachment (jpg/png/pdf ≤ 5 MB), entered by (automatic). | M |
| EXP-03 | Expenses are editable only on the day they are entered and only by users with `expenses.edit`. The old and new values are audited. After that day they can only be voided. | M |

### 3.11 Sales returns (SRT)
| ID | Requirement | P |
|---|---|---|
| SRT-01 | Return against an original invoice: search the invoice, choose lines and quantities (≤ sold − already returned). Full and partial returns are supported. | M |
| SRT-02 | Per line: condition (Resellable / Damaged / Defective) and a **restock** flag (default on for Resellable, off otherwise). Only restocked lines add stock, at the original sale line's unit cost. | M |
| SRT-03 | The return value is the net unit price actually paid. That includes the line's share of the invoice discount and tax, so a customer is never refunded more than they paid. | M |
| SRT-04 | Settlement order: (1) reduce the invoice's outstanding balance, then the remainder is (2) a cash/bank refund (`sale_returns.refund`), (3) store credit (if enabled), or (4) exchange, where a new sale is opened with the credit applied. | M |
| SRT-05 | A return receipt is printed. The return history is visible on the invoice and on the customer profile. | M |
| SRT-06 | Non-restocked returned items are reported as a "damaged returns" write-off in the profit report. | M |
| SRT-07 | A warranty indicator appears on the return screen when the line has a serial number or warranty. | C |

### 3.12 Profit (PFT)
| ID | Requirement | P |
|---|---|---|
| PFT-01 | Gross profit = Net sales revenue + Net service revenue − COGS − Service parts cost. | M |
| PFT-02 | Net sales revenue = Σ(sale line net amount excl. tax) for non-voided sales in the period − Σ(return line value excl. tax) for returns dated in the period. | M |
| PFT-03 | COGS = Σ(qty × unit cost at sale) − Σ(restocked return qty × original unit cost). | M |
| PFT-04 | Net profit = Gross profit − Expenses − Net inventory losses (adjustments out minus adjustments in, at cost). | M |
| PFT-05 | The report shows each component separately, plus the damaged-returns write-off, by day/week/month/year/custom range. | M |
| PFT-06 | Service revenue is recognised on the service invoice date (delivery). Parts cost is recognised on the same date. | M |

### 3.13 Reports (RPT)
All reports have a date filter, search, a print view, and PDF export. CSV/XLSX export is available where the report is tabular. Exports respect cost/profit visibility (BR-08).

| ID | Report | P |
|---|---|---|
| RPT-01 | Sales: daily summary, monthly summary, custom range, invoice register | M |
| RPT-02 | Sales by product, by category, by customer, by user/cashier | M |
| RPT-03 | Sales by payment method (also covering service, customer and supplier payments, as a cash/bank movement summary) | M |
| RPT-04 | Sales returns register | M |
| RPT-05 | Purchases register, purchases by supplier/product; purchase returns register | M |
| RPT-06 | Inventory: current stock, stock valuation at average cost, stock movement (by product, type, date), low stock, out of stock | M |
| RPT-07 | Expenses register and by category | M |
| RPT-08 | Gross profit and net profit (PFT) | M |
| RPT-09 | Customer balances, customer statement, outstanding invoices, aging | M |
| RPT-10 | Supplier balances, supplier statement, payables aging | M |
| RPT-11 | Service jobs register (by status/type/date), technician performance (jobs completed, average turnaround, revenue, re-open count) | M |
| RPT-12 | Most-selling products (by qty and by revenue); slow-moving products (no sale in N days but stock > 0) | M |
| RPT-13 | Daily closing summary: sales, returns, collections by method, expenses, expected cash | S |
| RPT-14 | Audit log viewer with filters (user, module, action, date) | M |

### 3.14 Users, roles & permissions (USR)
| ID | Requirement | P |
|---|---|---|
| USR-01 | User CRUD (`users.manage`): name, username, email, phone, role(s), active flag. Users are deactivated, never deleted, once they have activity. | M |
| USR-02 | Default roles Admin, Manager, Cashier and Technician, with the permission matrix in [RBAC.md](RBAC.md). | M |
| USR-03 | Admin can create custom roles and edit permissions per role through a checkbox matrix grouped by module. Changes are audited. | M |
| USR-04 | The Admin role cannot be deleted. The last active admin cannot be deactivated or demoted. | M |
| USR-05 | Authorization is enforced server-side (policies and middleware) on every route and action. Hiding UI elements is not security. | M |

### 3.15 Audit log (AUD)
| ID | Requirement | P |
|---|---|---|
| AUD-01 | Record user, action, module, record type and id, old values, new values, timestamp, IP, user agent and URL. | M |
| AUD-02 | Logged at minimum: login/logout/failed login; sale complete/void/reprint; refunds and returns; stock adjustments; price changes; purchase receive/void/edit; payments and payment voids; expense create/edit/void; user, role and permission changes; settings changes; backup create/download/delete/restore. | M |
| AUD-03 | The audit table is append-only. The application has no update or delete path, and DB triggers reject UPDATE and DELETE. | M |
| AUD-04 | Tamper evidence: each row stores a SHA-256 hash chained to the previous row. `pos:audit:verify` reports any break in the chain. | S |
| AUD-05 | Audit entries are also appended to a daily JSON-lines file under `storage/logs/audit/`, which survives a database restore. | S |

### 3.16 Settings (SET)
| ID | Requirement | P |
|---|---|---|
| SET-01 | Shop profile: name (default "Mr Printer & Tuner"), logo, address, phone, WhatsApp, email, NTN/STRN. | M |
| SET-02 | Document prefixes and numbering for invoice, service job, service invoice, purchase, payments, returns, expenses and adjustments. | M |
| SET-03 | Currency code and symbol (default PKR / Rs.), decimal display, rounding rule. | M |
| SET-04 | Tax: enabled flag, default rate, inclusive/exclusive, label (e.g. "GST"). | M |
| SET-05 | Receipt: default format (80 mm/A4), header and footer text, show/hide logo, notes and terms on the job slip. | M |
| SET-06 | Inventory: global low-stock threshold, negative-stock policy. | M |
| SET-07 | Sales: max discount % without approval, void window hours, store credit enabled, walk-in credit disallowed (fixed). | M |
| SET-08 | Payment methods, service types and expense categories (CRUD screens). | M |
| SET-09 | Backup settings: see [BACKUP_RESTORE.md](BACKUP_RESTORE.md) §10. | M |
| SET-10 | Security: session idle timeout, IP allow-list, password policy. | S |

### 3.17 Invoice / receipt (DOC)
| ID | Requirement | P |
|---|---|---|
| DOC-01 | Invoice content: shop name **MR PRINTER & TUNER**, logo, address, phone, NTN (if set), invoice no, date/time, cashier, customer (name/phone/balance where relevant), lines (product/service, qty, rate, discount, total), subtotal, discount, tax, round-off, grand total, paid by method, change, balance due, previous balance (optional), footer message. | M |
| DOC-02 | 80 mm thermal layout (≈ 72 mm printable, monospace-friendly) and A4 layout, both as HTML pages with print CSS, printed from the browser. A4 can also be downloaded as a PDF. | M |
| DOC-03 | Duplicate copies are watermarked "DUPLICATE". Voided documents print "VOID". | M |
| DOC-04 | Other printables: payment receipt, return receipt, service intake slip, service invoice, purchase document, customer and supplier statements. | M |

### 3.18 Backup & restore (BKP)
Full specification: [BACKUP_RESTORE.md](BACKUP_RESTORE.md). Summary of the Must items: automatic scheduled backups (with catch-up after the PC was off), a manual "Backup Now", tiers (daily/weekly/monthly/manual/safety), configurable retention, backup history with status, download, integrity verification (checksum plus a test restore), protection from unauthorized users, inclusion of uploaded files, off-site copies with a pending/retry queue, admin warnings, and a safe restore (verify → warn → re-authenticate → safety backup → restore → migrate → audit).

---

## 4. Non-functional requirements

| ID | Category | Requirement |
|---|---|---|
| NFR-01 | Availability | Full operation with no internet. Only the optional off-site backup and WhatsApp links use the internet. |
| NFR-02 | Performance | POS product search ≤ 300 ms and sale completion ≤ 1 s at 20,000 products and 500,000 sale lines on a mid-range PC (i5/8 GB/SSD). Reports for one year of data ≤ 5 s. |
| NFR-03 | Concurrency | At least 8 simultaneous terminals with no lost updates and no overselling (pessimistic row locks on stock and balances). |
| NFR-04 | Reliability | ACID transactions on InnoDB. No partial postings. The system survives sudden power loss (load-shedding) with no corruption beyond the in-flight transaction. A UPS on the server is strongly recommended. |
| NFR-05 | Recoverability | RPO ≤ 24 h by default (≤ 4 h with intraday backups enabled). RTO ≤ 1 h onto the same or a replacement PC, following [BACKUP_RESTORE.md](BACKUP_RESTORE.md). |
| NFR-06 | Security | OWASP Top-10 mitigations: CSRF tokens, prepared statements/Eloquent only, Blade escaping, server-side validation, policies on every action, login throttling, secure session cookies, upload validation, secrets only in `.env`, the DB port not exposed on the LAN. See ARCHITECTURE §8. |
| NFR-07 | Auditability | Every money and stock change is traceable to user, time and source document. |
| NFR-08 | Usability | The POS can be operated entirely by keyboard and scanner. Common sale ≤ 3 interactions after scanning. Clear validation messages, toasts, confirmations for destructive actions, and empty and loading states. |
| NFR-09 | Compatibility | Chrome and Edge (current versions) on Windows. Usable on a tablet from 1024 px wide. All assets (fonts, icons, JS, CSS) are bundled locally, with no CDNs. |
| NFR-10 | Maintainability | Laravel conventions, PSR-12, strict types, posting logic in Action classes with tests, migrations for every schema change, no business logic in Blade or controllers. |
| NFR-11 | Testability | Automated feature tests for every critical workflow (§5), run against MariaDB/MySQL, not SQLite, because locking, CHECK constraints and triggers matter. |
| NFR-12 | Data retention | Financial and audit data are never purged automatically. |
| NFR-13 | Localization | en-PK formatting (dd/mm/yyyy, Rs. 1,234.00), Asia/Karachi time. Strings are translatable. |
| NFR-14 | Installability | Installation on a fresh Windows PC by following [INSTALLATION.md](INSTALLATION.md) in under 2 hours. |

---

## 5. Acceptance tests (minimum automated test set)

| Workflow | Key assertions |
|---|---|
| Login | Valid login, wrong password, throttling after 5 failures, inactive user blocked |
| Permissions | Each default role reaches only its allowed routes. Technician sees only assigned jobs. Cost fields are hidden without `products.view_cost` |
| Product creation | Validation, unique SKU/barcode, price change audited |
| Sale | Totals, discount allocation, tax, round-off, invoice number sequence |
| Stock deduction | Stock and movement rows correct. Negative stock blocked. Concurrent sale of the last unit: exactly one succeeds |
| Purchase | Landed-cost allocation, average cost update, supplier ledger |
| Stock addition | Purchase receipt increases stock with correct movement rows |
| Credit sale | Customer ledger and balance, credit-limit enforcement, walk-in credit refused |
| Customer payment | FIFO allocation, invoice payment status, payment void reverses everything |
| Sale return | Qty limits, discount-share refund, restock vs damaged, settlement order |
| Purchase return | Stock and cost decrease, payable reduced |
| Repair job | Status transitions allowed and refused, history written |
| Parts consumption | Stock decreases on add and returns on removal; job cancel handling |
| Profit calculation | Known-data scenario produces exact gross and net profit, including returns and discounts |
| Backup creation | Zip, manifest, checksum and history row; retention pruning |
| Backup verification | A corrupted file fails verification; a valid file passes |
| Restore | Safety backup is created first, data is restored, a failed import rolls back to the safety backup, and the audit record is written |
| Rollback | An exception injected mid-posting leaves zero partial rows |
