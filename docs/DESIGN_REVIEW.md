# Phase 1 Design Review: Inconsistencies, Decisions & Open Questions

**Date:** 2026-10-06 · **Purpose:** The requirements brief asked that architecture and database design be reviewed for inconsistencies before any code is written. This file records what the review found, how each issue was resolved, and what the owner still needs to decide.

---

## 1. Inconsistencies and gaps found in the original brief

| # | Finding | Resolution (in the design) |
|---|---|---|
| I-01 | The backup folders listed only `daily/ weekly/ manual/`, but retention asks for **6 monthly** backups. | Added `monthly/`, plus `safety/`, `intraday/` and `uploaded/` (BACKUP_RESTORE §3). |
| I-02 | Entities list `sale_payments`, `customer_payments`, `purchase_payments` and `supplier_payments` without defining how they relate. | `customer_payments`/`supplier_payments` = money actually moved (one row per method). `sale_payments`/`service_job_payments`/`purchase_payments` = allocations of that money to documents. One payment can settle many invoices, and advances are supported (ADR-05). |
| I-03 | The "Delete" permission conflicts with "never overwrite financial transactions silently". | Delete applies only to master data (soft delete, and only without history) and to unposted drafts. Posted documents are **voided** with reversing entries (BR-01). |
| I-04 | "Current stock" is a product field, but there must also be a complete movement ledger. Two sources of truth can drift. | The movement ledger is the truth, `current_stock` is a cache, there is a single write path (`InventoryService`), and a nightly integrity check runs (ARCHITECTURE §4.2). |
| I-05 | The job card had "Parts cost" and "Total" without separating **what the customer is charged** for parts from **what the parts cost the shop**. | Separate `parts_amount` (billed) and `parts_cost` (internal, at average cost) fields. Profit uses the cost. |
| I-06 | Repair parts must reduce stock "when consumed", but a service invoice is issued later. If the invoice were a normal sale, stock would be deducted twice. | Service jobs are their own invoiced document. Stock moves when the part is added; the ledger is debited at delivery (ADR-06). |
| I-07 | "Replacement" and "store credit" on returns have no defined money flow. | Fixed settlement order: reduce the invoice's due amount → refund / store credit / exchange. Store credit is a negative customer balance usable as the payment method "Customer Credit" (SRS SRT-04, CUS-09). |
| I-08 | A refund on a discounted invoice could return more than the customer paid. | The header discount is allocated to lines and stored (`invoice_discount_share`). Returns refund the net unit price (SRT-03). |
| I-09 | Returned items that are not resellable: what happens to their cost? | The cost stays in COGS, and the profit report shows it separately as a "damaged returns write-off" (PFT-05). |
| I-10 | "Immutable audit log" vs **restore**: restoring an old backup removes newer audit rows. | The JSONL audit mirror sits outside the DB and is not restored; the safety backup is taken first; the restore record is written into the restored DB (BACKUP_RESTORE §12.2). |
| I-11 | Restore through the web UI while other terminals keep selling would corrupt or lose data, and long restores time out in Apache. | Maintenance mode during the restore; the restore runs as a background CLI process; a CLI command is also available for disaster recovery. |
| I-12 | "Scheduled daily backups" assumes the PC is on at the scheduled time. Shop PCs are usually switched off at night. | Catch-up rule, plus a scheduler heartbeat warning (BACKUP_RESTORE §4, §9). |
| I-13 | "Off-site backup to cloud" conflicts with "no cloud dependence" and creates a data-exposure risk. | Off-site is optional. A USB drive or second PC is the offline-capable minimum. Cloud destinations require encryption. |
| I-14 | There was no way to load existing products, stock and customer balances at go-live. | Added CSV/XLSX import with opening-stock movements (PRD-07) and opening-balance ledger entries (CUS-02). |
| I-15 | "Wholesale price if required" was given without a rule for when it applies. | A `price_tier` on the customer (retail/wholesale) selects the default POS price. |
| I-16 | "Subcategory" was listed as a separate product field. | The category tree is 2 levels deep (`parent_id`). The product links to the leaf category, and the subcategory is derived. |
| I-17 | No cost method was specified, but profit "must use product cost". | Moving weighted-average cost, with the cost snapshot stored on every sale line (ADR-04). |
| I-18 | Tax on purchases: is it an input credit or a cost? | By default, purchase tax is added to landed cost (no input claim). This is configurable. |
| I-19 | Credit to a walk-in customer would create an untraceable receivable. | The walk-in customer cannot buy on credit or hold a balance (BR-11). |
| I-20 | "Change prices" and "apply discounts" permissions alone would block cashiers at the counter. | Discounts up to a % limit are allowed, and manager approval on the POS covers the rest (AUTH-09). |
| I-21 | Double-clicking "Complete sale" or a network retry on a slow LAN could create duplicate invoices. | An idempotency key, UNIQUE in the DB (ARCHITECTURE §5.1). |
| I-22 | Several terminals can oversell the last unit. | Row locks in a fixed order, a negative-stock check under the lock, and deadlock retries (ARCHITECTURE §6.3). |
| I-23 | Quantities were implicitly integers, but toner powder and ink are consumed in grams and ml in refills. | Quantities are `DECIMAL(12,3)`, with an "allows decimal" flag per unit. |
| I-24 | No rule for editing expenses, which are financial records. | Same-day edit with audit; void after that (EXP-03). |
| I-25 | The installed environment has **MariaDB 10.4.32 (end-of-life since 2024)**, and the PHP `intl` extension is not enabled. | Upgrade recommended (Q1); enable `intl` in Phase 2. |
| I-26 | Plain HTTP on the LAN exposes passwords to anyone sniffing the shop Wi-Fi. | Optional local HTTPS is documented. Recommended if Wi-Fi tablets are used. |
| I-27 | Shipping an `admin/password` seed would be a security hole. | The install command prompts for the admin password; there are no default credentials. |

---

## 2. Decisions made on the owner's behalf (with defaults; easy to change)

| # | Decision | Default chosen |
|---|---|---|
| D-01 | Sale void window | 24 h. After that, use a sales return |
| D-02 | Cashier discount limit without approval | 5% |
| D-03 | Cashiers can make credit sales | Yes, within each customer's credit limit |
| D-04 | Cashiers can process returns or refunds | No (manager) |
| D-05 | Payment allocation default | Oldest invoice first, with manual allocation possible |
| D-06 | Service invoice numbering | Separate prefix `SRV-` (sales use `INV-`) |
| D-07 | Receipt default | 80 mm thermal; A4 on request |
| D-08 | Daily backup time | 21:00, with catch-up |
| D-09 | Retention | 7 daily / 4 weekly / 6 monthly / 20 manual / safety 30 days |
| D-10 | Login identifier | Username (staff may not have email) |
| D-11 | Front-end | Blade + Alpine + Tailwind, all assets bundled offline |
| D-12 | Store credit | Enabled |

---

## 3. Open questions for the owner

These do not block Phase 2; the default is used until the owner answers.

| # | Question | Default if no answer |
|---|---|---|
| Q1 | **Database server.** XAMPP's MariaDB 10.4 is end-of-life. Install MariaDB 11.4 LTS (or MySQL 8.4) as a Windows service, or switch to Laragon? | Install a standalone **MariaDB 11.4 LTS**; keep XAMPP for Apache/PHP |
| Q2 | Is the shop **registered for sales tax** (FBR/PRA), and do invoices need NTN/STRN and a tax breakdown? Is FBR POS integration needed in future? | Tax disabled; fields ready |
| Q3 | Weighted-average costing acceptable? (FIFO is possible but adds complexity) | Weighted average |
| Q4 | How many counter terminals, and which **thermal printer model**? Should the cash drawer open automatically? | 2–3 terminals; driver-based drawer kick |
| Q5 | Off-site backup destination: second shop PC, USB rotation, Google Drive, Backblaze B2? | USB/second-PC path; cloud optional |
| Q6 | Is existing product/customer data available (Excel, an old software export) for import at go-live? | CSV import template provided |
| Q7 | Do you want a **cash register shift** (opening float, closing count, over/short)? It is not in the brief but common for cash control. | Not in v1; daily closing report (RPT-13) covers the basics |
| Q8 | Should **managers** see cost price and profit? | Yes (per brief) |
| Q9 | Should printers sold be tracked by serial number in stock (per unit), or is a serial on the invoice line enough? | Serial on the invoice line only |
| Q10 | Should the technician be allowed to set charges, or only propose an estimate for the manager or cashier to finalize? | Technician can set charges on assigned jobs |
| Q11 | Should SMS/WhatsApp notifications ("your printer is ready") be automated? That needs internet and a paid gateway. | Manual `wa.me` link button only (C priority) |
| Q12 | Use HTTPS on the LAN (needs a one-time certificate install on each terminal)? | HTTP on wired LAN; HTTPS if Wi-Fi tablets are used |

---

## 4. Cross-document consistency check

| Check | Result |
|---|---|
| Every SRS module maps to tables in DATABASE.md | ✅ 25/25 modules |
| Every write operation in SRS has a posting rule (DATABASE §5) | ✅ |
| Every SRS action has a permission in RBAC.md | ✅ (including approval, void and export) |
| Every table with money uses DECIMAL | ✅ |
| Every cache column has an integrity-check invariant | ✅ (DATABASE §5, invariants 1–8) |
| Every requested test workflow appears in SRS §5 | ✅ 17/17, plus a rollback test |
| Brief's expected entity list is covered | ✅ All present. Added: units, document_sequences, held_sales, service_types, service_job_payments, stock_adjustments(+items), customer/supplier ledger entries, purchase_return_items, backup_destinations, backup_transfers, backup_restores |
