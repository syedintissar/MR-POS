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

## 3a. Owner decisions (recorded 2026-10-06, before Phase 2)

The owner reviewed §3 and decided as follows. These supersede the defaults above.

| # | Decision | Design consequence |
|---|---|---|
| Q1 | **Upgrade MariaDB 10.4.32 → 11.4 LTS.** Back up existing data first and verify compatibility. | The project targets MariaDB 11.4 LTS. Environment work is logged in [ENVIRONMENT_CHANGES.md](ENVIRONMENT_CHANGES.md). The existing 10.4 server also holds other databases (see that file), so the cutover is staged. |
| Q2 | **Not registered for sales tax for now,** but tax must be enable-able later from Settings with no code change. | `tax.enabled = false` by default. Rate, label, inclusive/exclusive and per-product override are all settings or product fields. Sale, return and purchase code paths always carry tax columns (zero while disabled). Phase 2 ships the settings. POS use starts in Phase 5. |
| Q4 | **Printer model not final.** Support common 80 mm thermal printers generically and make everything configurable. | Settings group `printing`: printer name/model (label only), paper width (58/80 mm/A4), margins, copies, header, footer, show logo, auto-print on sale, auto-print on job intake. Output is browser-printed HTML, so any driver-installed printer works. |
| Q5 | **Local automatic backup + optional off-site/cloud.** The destination must stay configurable. Backups must cover all critical POS data, and a restore procedure must be tested. | Phase 2 delivers a working backup, verification and **tested restore** at the command line, so data is protected from day one (including DB dump, uploaded files and `.env`-independent manifest). Destinations (`backup_destinations`) are pluggable. The full engine and UI follow in Phase 9. |
| Q8 | **Managers and admins see cost, profit and margin. Cashiers and sales staff must not.** | Already the default in [RBAC.md](RBAC.md). Phase 2 adds a test asserting the Cashier and Technician roles lack `products.view_cost`, `reports.profit` and `dashboard.financials`. Enforced server-side in later phases, including exports and JSON. |

Other owner instructions for Phase 2: `git init` first, initial commit before major changes, enable and verify PHP `intl`, take a backup before any destructive DB or environment change, stay local-first, never ignore backup failures (they must be reported clearly), and document every environment change.

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

---

## 5. Phase 2 findings (what building it taught us)

| # | Finding | What was done |
|---|---|---|
| P2-01 | The old XAMPP MariaDB 10.4 server also holds four **other projects'** databases, has **no root password** and listens on **all network interfaces**. The brief only mentioned upgrading the database | MR-POS runs on a new MariaDB 11.4 beside it. Everything was backed up first; the other databases were copied to 11.4 and matched table-by-table. Replacing 10.4 is left to the owner (INSTALLATION.md §10) |
| P2-02 | Laravel 13 ships `config/app.php` with the time zone hard-coded to UTC and ignores `APP_TIMEZONE`, while MariaDB ran on +05:00. Stored times and the "21:00" backup would have been five hours apart | PHP and the database connection now share Asia/Karachi (+05:00). Regression tests assert that both clocks agree |
| P2-03 | Under PHP's built-in server (and some services) the backup tools failed with Windows error 10106 because the child process had no `SystemRoot` | It is always passed explicitly now. The error was reproduced in a test and the failure was reported correctly on screen, in the audit log and in the history |
| P2-04 | Restoring onto an empty database (replacement PC) tried to make a "safety backup" of data that does not exist, and failed | Skipped when the database has no tables; all other cases still require the safety backup |
| P2-05 | A dump taken while a backup is running contains that backup's own "running" row, which would look like an interrupted failure after a restore | Such rows are dropped after a restore and the real file is re-registered from disk |
| P2-06 | Two administrators demoting each other at the same moment could both pass the "keep one admin" check | The check now runs inside a transaction that locks the admin rows |
| P2-07 | `alpha_dash` rejects dots, but usernames like `ali.raza` are natural | Usernames may contain letters, numbers, dot, dash and underscore |
| P2-08 | Laravel calls `afterRefreshingDatabase()` before *every* test, so seeding there re-created 91 permissions each time (57 s for 19 tests) | Seeding hooks into the one-time migration step (8 s for the same tests) |
| P2-09 | Fortify and Pest (named in ARCHITECTURE) were not worth their weight | Hand-written login controller (fewer packages, full control of throttling and auditing) and plain PHPUnit. ARCHITECTURE.md updated |
| P2-10 | The login page's failed attempts were attributed to the *account being attacked* in the audit log | Failed and refused logins now record the account as the subject and no actor |
| P2-11 | The restore command's password prompt cannot be fed from a pipe (it reads the terminal) | Intended: a restore needs a person at the keyboard. Drills used the service directly |

---

## 6. Phase 3 findings

| # | Finding | What was done |
|---|---|---|
| P3-01 | **Concurrency is real**: three cashiers can press "complete sale" on the last unit together. A test inside one PHP process cannot prove the locking works | Tests start separate PHP processes that hit the same product at the same instant. Exactly one of four buyers gets the last unit; opposite-order multi-product adjustments never deadlock. A "mutation check" (row lock removed on purpose) showed two buyers succeeding and deadlocks, so the test genuinely detects the problem |
| P3-02 | A barcode containing accented letters was happily turned into a label, which a scanner cannot read reliably | SKUs and barcodes must be plain printable ASCII (no spaces in a barcode) in the form and in the import; label printing skips anything else with an explanation |
| P3-03 | Excel's CSV export starts with a byte-order mark. Left in place it hides the quote of a quoted first header, so the "name" column was not recognised | The mark is removed before parsing. Covered by a test with BOM + semicolons + `Rs. 1,200` style numbers |
| P3-04 | Blade pairs the first `@php` with the first `@endphp`, so an inline `@php(...)` before a block silently swallowed half the product list page. `view:cache` does not catch this, and only that page's test failed | A test compiles and parse-checks every view, and another forbids the risky ordering |
| P3-05 | People without the "change prices" permission get read-only price boxes, which browsers do not submit, so their save was rejected | The server fills in the stored prices for them and ignores any price they might post |
| P3-06 | Product costs could leak through pickers, JSON, labels, the import template or the dashboard | Nine screens are tested from both sides with an easy-to-spot cost: cashiers and technicians never see it, managers do; the lookup JSON has no cost or price field for anyone; a cost forged into an adjustment by someone who may not see costs is ignored |
| P3-07 | Bulk import would have written thousands of audit rows and one stock document per product | One audit summary and one opening-stock document for the whole file; all-or-nothing (a failure on the last row also rolls back new categories and brands) |
| P3-08 | Supplier balances cannot be correct without a ledger | The fields exist but are not editable until Phase 4 builds the supplier ledger |
| P3-09 | A product's unit decides whether decimals are allowed, so changing it after stock exists could leave 2.5 pieces | The unit and the stock/service kind are locked once a product has stock history; a unit cannot stop allowing decimals while products with history use it |
| P3-10 | Open questions Q6 (Excel data), barcodes and label printer were not answered | Defaults used: CSV/XLSX import with a downloadable template; labels generated from the barcode or SKU (Code 128) in three sizes, printed from the browser |

---

## 7. Phase 4 decisions and findings

### Defaults used for the questions left open at the end of Phase 3 (change any of them later)
| # | Question | Default used |
|---|---|---|
| P4-D1 | Existing supplier balances | None entered. An administrator can post an **opening balance** per supplier (once) on the supplier's page, with a reason and password re-entry. Corrections use "balance adjustment" |
| P4-D2 | Same supplier invoice number twice | Refused for one supplier (other suppliers may reuse a number). A cancelled purchase frees its number so the corrected bill can be entered |
| P4-D3 | Freight / invoice discount | Shared over the lines in proportion to line value (last line takes the remainder); the result is the landed cost used for the average cost |
| P4-D4 | Payment methods | Cash, Bank Transfer, Card, Easypaisa, JazzCash, Cheque (+ the system "Customer Credit"). Editable in Settings → Payment methods. Bank Transfer, Easypaisa, JazzCash and Cheque ask for a reference number |
| P4-D5 | Purchase tax | Added to the product cost (setting `tax.purchase_tax_in_cost`, on by default); switch it off if the shop ever claims input tax back |
| P4-D6 | Payment when receiving | One payment (any method) can be recorded while receiving; further payments, or a second method, go through Supplier payments |

### Findings
| # | Finding | What was done |
|---|---|---|
| P4-01 | **The integrity check caught a real bug in my first test run.** A payment that had settled a cancelled bill was still counted as "allocated" when it was applied again as an advance, so the check reported it as over-allocated | The check, and `SupplierPayment::allocatedAmount()`, ignore allocations to cancelled bills (that money is an advance again). Covered by tests that apply an advance after a cancellation |
| P4-02 | **A Blade pitfall that blanks a whole page**: a directive glued to the word before it (`yet@endif`) is read like an e-mail address and ignored, so the `@if` stays open and the page crashes. It broke the dashboard once | A test now scans every view for a directive glued to a word; the existing compile and parse test covers the rest |
| P4-03 | Races are real for purchasing too: two people receiving the same draft, paying the same bill, or cancelling the same payment | Five tests start separate PHP processes that act at the same instant. Exactly one receive and one full payment succeed; automatic payments never over-pay a bill; the supplier ledger still adds up. **Mutation check:** with every row lock removed, four processes received the same draft and two paid the same bill, so the tests genuinely detect the problem |
| P4-04 | **Deviation from the design (DATABASE §5):** the design said cancelling a purchase also cancels its payments | Not done. A payment is a real handover of money; cancelling the bill must not silently erase it. It stays recorded and becomes an **advance** with the supplier, which can be applied to another bill, or cancelled on its own if it was a mistake |
| P4-05 | Models returned by an action did not contain the database defaults of guarded columns (a new draft had no `status` in memory) | Actions return the row as the database holds it |
| P4-06 | Unit costs are stored to 4 decimals, so a landed cost cannot always reproduce a bill to the paisa | Shares are calculated to 8 decimals and the last line takes the remainder; the maximum error is 0.00005 per unit and is documented in DATABASE §10 and tested |
| P4-07 | Refunds received from a supplier have no document to belong to yet | Deferred to Phase 8 together with purchase returns. The `refund` type and ledger entry type exist but nothing creates them yet |
| P4-08 | Purchase screens necessarily show costs | They (and the supplier account) belong to Manager and Admin only. Tests prove a cashier and a technician see no cost or supplier information on the dashboard, product pages, the product search JSON, the stock card or any purchasing URL |
| P4-09 | The test helper that empties the data tables also emptied the payment methods | `payment_methods` joined the list of reference tables that are kept (roles, permissions, units, categories, brands, numbering) |
| P4-10 | Two tests of mine used `array + array` (which keeps the left-hand value) and silently did not test what they claimed | Replaced with `array_merge`; noted so it is not repeated |

---

## 8. Phase 5 decisions and findings

### Defaults used for the questions put at the end of Phase 4 (the owner can change any of them)
| # | Question | Default used |
|---|---|---|
| P5-D1 | Credit and partial payments | Customers arrive in Phase 6, so Phase 5 sells to the **walk-in customer only, paid in full** (cash, card, bank, wallet, split across methods). Named customers, credit, partial payment and customer credit are refused with a clear message until then |
| P5-D2 | Cashier discount limit | 5 % of the sale without approval (`sales.max_discount_percent`); any discount above it, or any discount at all for someone without the discount permission, needs a manager |
| P5-D3 | Rounding at the till | None by default (`sales.round_to`); Rs. 1 / 5 / 10 can be chosen in Settings |
| P5-D4 | Wholesale price | The cashier picks Retail or Wholesale for the whole sale; a cashier needs a manager's approval for Wholesale, managers do not. A product without a wholesale price sells at its normal price |
| P5-D5 | Void window | Managers: 24 hours (`sales.void_window_hours`); administrators: any time; always with a reason and the password; never after goods were returned |
| P5-D6 | A4 "PDF" | A server-built PDF (dompdf, installed with Composer, works offline). It is a copy of an invoice already issued, so it is marked DUPLICATE and audited |
| P5-D7 | Quick-pick grid | The shop's fast movers of the last 30 days; until there are enough sales, the most recently touched products. (A manual "favourites" flag can be added later if wanted) |
| P5-D8 | Customer search (F4) | Phase 6, with the customer module |

### Findings
| # | Finding | What was done |
|---|---|---|
| P5-01 | **A test caught a real bug:** saving a held cart through form validation kept only the fields that were validated (product and quantity) and silently dropped prices, discounts, price list and notes, so a resumed cart would have lost them | The whole cart is saved; a test holds, changes a price, resumes and compares |
| P5-02 | `crypto.randomUUID()` does not exist on plain `http://` pages that are not `localhost`, and the tills open the site over `http://192.168.x.x`. The checkout key would have failed to be created on every till | The key is made from `crypto.getRandomValues` (available everywhere) with a last-resort fallback; tested with and without it |
| P5-03 | The screen must never show a total that the server then refuses, but floating-point arithmetic can differ by a paisa | The browser maths uses exact integers (BigInt) and is compared with the PHP calculator on ~4,000 random sales (tax inclusive and exclusive, discounts, round-off) inside the normal test run; the server also refuses a sale whose total differs from the screen's and tells the cashier the new figure |
| P5-04 | **Real-browser drill.** Unit tests cannot show that the till works on a screen | A script drives the real till in headless Edge (real key presses and mouse clicks) against the real server and database: barcode scan with Enter, quantity change, stock warning, F9 / F10, change calculation, receipts, manager approval with a wrong and a right password, hold and resume, page refresh restoring the cart, a triple click on Complete, manager's invoice page. 31 checks, five runs in a row clean. Two earlier "failures" were the script typing faster than a person can (before a dialog appeared); fixed in the script, not the till |
| P5-05 | Two independent guards stop a double sale (the named lock and the unique index) | A mutation check removed the lock: the test still passed, because the unique index alone catches it; removing **both** made the test fail with a raw duplicate-key error, so the test is sensitive. Removing the stock row locks made four sales succeed for one unit |
| P5-06 | "The last line takes the remainder" (the first design) can give a small line more discount than it is worth | The invoice discount is shared with the largest-remainder method: exact to the paisa and never more than a line is worth. Tested with random sales |
| P5-07 | Anyone with access could print the "original" receipt again and again, which defeats the DUPLICATE mark | The original opens only through a signed link valid for ten minutes; everything later is a reprint (marked, audited, own invoices only for cashiers) |
| P5-08 | dompdf does not draw inline SVG, so the invoice barcode was missing from the PDF at first | The barcode is handed to it as an image; checked by rendering the PDF to a picture |
| P5-09 | A throw-away command I ran to look at how settings are serialised used a test factory against the real database and created a stray user | Noticed at once and removed (users 0, audit 0 again); the lesson is now in my notes: never run factories against the real database |
| P5-10 | Passing the till's settings to the page as JSON inside an HTML attribute escapes the quotes twice | The test decodes both layers; documented so the next page that does it is not surprised |
| P5-11 | **Windows holds a file for a moment after it is written** (antivirus, the search indexer). In the full test run `rename()` was once refused with "Access is denied" on the freshly written database dump, and once `ZipArchive::open()` refused a freshly written backup. The same could happen in the nightly backup on the shop PC and turn a good backup into a reported failure | `App\Support\SafeRename` and `SafeZip` retry for a few seconds (then fall back to copy-and-delete for a rename) in every place the backup, verify, restore and transfer code renames or opens a file it has just written. Tests reproduced the real Windows refusal on this PC (a file held open) and the recovery, and a missing file still fails at once |

## 9. Phase 6 decisions and findings

### Defaults used for the questions put at the end of Phase 5 (the owner can change any of them)
| # | Question | Default used |
|---|---|---|
| P6-D1 | Credit sales | A customer buys on credit only if marked **allow credit**, with an optional **credit limit** (blank = unlimited). A sale that would take the customer over the limit asks for a manager (`sales.override_credit_limit`); a cashier without `sales.credit` also needs a manager for any credit sale |
| P6-D2 | Customer price list | A customer is **Retail** or **Wholesale**. Choosing a wholesale customer switches the till to the wholesale list with no approval; the cashier's own use of the wholesale list for a retail customer still needs a manager. Going back to the walk-in customer undoes it |
| P6-D3 | Who sets credit terms | Only people with `sales.override_credit_limit` (managers and the administrator). A **cashier can add a customer** (at the till or on the Customers page) but the customer is created retail, with no credit; editing a customer as a cashier keeps the terms a manager set |
| P6-D4 | Collecting money | Settles the **oldest invoice first** (by invoice date), or exactly the invoices chosen, or is kept as an advance; anything beyond what is owed stays as the customer's credit, which they can spend at the till ("Customer credit") or have applied to the next invoices |
| P6-D5 | Statements | A printable statement (and PDF) per customer for a date range, with "brought forward", a running balance and the closing balance |
| P6-D6 | Opening balances | Entered **once** per customer by the Administrator only (`customers.adjust_balance`), with the password and a reason. Later corrections are balance adjustments (a reason each time), never edits |
| P6-D7 | Deleting a customer | Possible only for a customer with no invoice, payment or account history, with the password. Everyone else is **deactivated** (they keep their account but cannot buy) |
| P6-D8 | Duplicate phone | Adding a customer whose phone number (digits only, so `0300-1234567` equals `0300 1234567`) already belongs to someone shows who, and needs "save anyway" |
| P6-D9 | Walk-in customer | Never has an account: it pays in full, cannot be given credit, has no profile, statement or payment collection (the database enforces it) |
| P6-D10 | Who sees customers' money | `customers.view` shows names and phones only. Balances, limits, advances, payments, statements and "Who owes us" need `customer_payments.view`, `customers.statement` and `reports.customers` respectively. Cashiers see balances and can collect money, but cannot cancel a payment (`customer_payments.void`: managers) |

### Findings
| # | Finding | What was done |
|---|---|---|
| P6-01 | **A concurrency test caught a real bug that no single-process test could.** Two tills spending the same customer credit at the same moment both succeeded. Cause: the sale's first database read was "which customer is the walk-in one?", an ordinary read, which fixes the transaction's snapshot **before** it waited for the customer lock. When it got the lock it still saw the credit as it was before the other till finished | The customer lock is now the first read of the sale and of a void (`CompleteSale::customer()`, `VoidSale`); the rule and its reason are written in DATABASE §12. Two **mutation checks**: removing the row lock makes four of the six concurrency tests fail; putting the early read back makes the credit-spent-twice test fail. Both were restored |
| P6-02 | The payment page would have crashed (HTTP 500): the `CustomerPayment` model had no `creator`, `voider` or `sale` relations that the page and the controller use. The first 49 backend tests did not render pages | Found by the new web tests; relations added; every Phase 6 page is now rendered by a test and by the browser drill |
| P6-03 | A first version of the integrity query used `HAVING` without `GROUP BY`, which MariaDB rejects | Rewritten with derived tables; the check is proven by tests that tamper with a balance, an invoice and a receipt |
| P6-04 | A customer who holds credit and pays with it must not create a second ledger line (the money was already credited when they paid in) | "Customer credit" allocates existing receipts and writes no ledger line; tests prove the balance and the statement stay exact, and that the same credit cannot be spent twice (see P6-01) |
| P6-05 | **Real-browser drill** with a real server and database: 43 checks (F4 customer box, search, quick add, duplicate phone confirmation, walk-in and cash-only refusals, wholesale switch, credit sale with "left on account", over-limit approval by a manager, collecting 1,500 against 1,400 owed, receipt, spending the 100 of credit at the till, statement and its PDF, "Who owes us", the dashboard tile, customer and sales lists). 42 passed on the final run; the one "failure" was the script looking for the words in mixed case while the page draws them in capitals (the tile itself was correct on the screenshot) | Fixed in the script, not in the page |
| P6-06 | The dashboard's "Coming next" card still described Phase 3 | Updated |
| P6-07 | After a restore the backup list shows older backups as "verification pending", because the backup table that comes back is the copy inside the backup (written before its own verification result existed) | Expected and harmless; verification is re-run with `pos:backup:verify`. Left for Phase 9 (backup completion) to improve |
| P6-08 | **The project's `.env` was overwritten with `.env.example` while the full test suite was running** (secrets blanked), which made 220 tests fail with "Access denied (using password: NO)" at the same moment. The traces match the installation steps (INSTALLATION.md §5: `composer install`, `npm run build`, `copy .env.example .env`) being carried out by hand in the same folder at the same time; a second run was disturbed the same way by a rebuild and a re-save of `.env` | `.env` rebuilt from the saved credentials with a new application key; a copy is kept in `D:\_env_backups`; details and what I need from you in ENVIRONMENT_CHANGES (Phase 6). Lesson: a test run shares the folder and the database server with whatever else happens on the PC, so a mass "Access denied" is checked against `.env` first and the run is repeated |
| P6-09 | **Look and feel, from the shop logo (dark grey "M", red "R", pale ring).** The program is now a **light** theme in the logo's colours: red for the main action, the active menu item, focus and links; neutral greys everywhere else; errors and "danger" buttons keep their own red (danger is an outline so it is never mistaken for the main action). Modern touches: white menu with an animated red marker on the current page, rounded cards with soft shadows that lift on hover, buttons that rise and press in, soft red focus rings, frosted top bar, pages and dashboard cards that fade in one after another, dialogs that pop in, and a sign-in page with the logo on a softly moving background. All motion is switched off for people whose computer asks for reduced motion, and nothing is loaded from the internet | The picture of the logo reached me only in the chat, so the built-in mark (`public/brand/mr-logo.svg`, also the browser tab icon) is my vector redrawing of it. **Upload the original file in Settings → Shop** and it replaces my version in the menu and on the sign-in page and receipts. The real-browser drill (43 checks) was rerun on the new look: every screen and dialog works |

### Questions for the owner (answers shape Phase 7 and later)
| # | Question | Default if not answered |
|---|---|---|
| P6-Q1 | Should a **Technician** see the customer list (names, phones)? Today Technicians have `customers.view` so they can find a customer when taking in a repair, but never any money | Keep it; Phase 7 will limit technicians to the customers of their own jobs if you prefer |
| P6-Q2 | May a **Cashier** set credit terms and the wholesale list for a customer? | No: only a manager (a cashier can add a customer, retail and cash only). Say if you want it |
| P6-Q3 | Repair jobs (Phase 7): may a job be taken for the **walk-in customer**, or must it always have a named customer? | Walk-in allowed, paid in full at delivery; a named customer can have the invoice on account |
| P6-Q4 | Advances paid on a repair job: keep them as the customer's **credit** (usable at the till) or as a deposit that belongs to the job only? | The customer's credit, so one account tells the whole story |
| P6-Q5 | Do customers owe you money today? | Give me names, phone numbers and amounts: they become opening balances |

## 10. Phase 7 decisions and findings

### Defaults used for the questions put at the end of Phase 6 (the owner can change any of them)
| # | Question | Default used |
|---|---|---|
| P7-D1 | Technicians and customers | A Technician keeps `customers.view` (names and phones, never money) and sees the customer name and phone of the jobs assigned to them. Limiting the customer list to the customers of their own jobs is **not** built |
| P7-D2 | Jobs for the walk-in customer | Allowed, with a contact name and phone number (needed to call when it is ready). The walk-in customer pays the whole job at delivery, and can leave an advance (a receipt without an account) |
| P7-D3 | Advances on a job | The advance is a numbered receipt that **pays the job** (it is allocated to it), so it shows on the job and is not free credit that could be spent elsewhere. If the job is cancelled it is kept as the customer's credit or handed back; the walk-in customer always gets it back. An advance can never be more than the job is worth |
| P7-D4 | Delivering on credit | What the customer does not pay at delivery stays on their account only for a named customer who is allowed credit, within their limit. The cashier needs `sales.credit` for it and `sales.override_credit_limit` to pass the limit; otherwise a manager delivers the job. (No approval box: the manager simply does it on their own screen) |
| P7-D5 | Parts and price | A part is billed at the product's price (the wholesale price for a wholesale customer). A different price or a discount needs `service_jobs.set_charges`. Only stock products can be parts; a service item is added as a charge |
| P7-D6 | Written-off parts | A part that cannot be reused is written off with a reason. It has already left the stock, so **no further stock movement** is made (DATABASE §5 had said "an adjustment_out document is created", which would take it out twice); its cost stays on the line as a loss for the profit report (Phase 8) |
| P7-D7 | Who writes what on the card | The counter (`service_jobs.edit`) edits contact, device, problem, accessories, promised date, priority and the note for the customer; the diagnosis and the internal notes need `service_jobs.manage_parts` (the workshop) |
| P7-D8 | Tax and rounding on a job | Tax follows the shop setting (inclusive or exclusive, snapshotted when the job is received); no round-off on jobs |
| P7-D9 | Cancelling | Only along the state machine: a **completed** job is reopened (with a reason) before it can be cancelled. Managers and administrators only (`service_jobs.cancel`) |
| P7-D10 | Change on delivery | The cash handed over and the change are shown on screen at the time and written in the audit entry; unlike a sale, the job does not store them, so a reprint does not show them |
| P7-D11 | Overdue and "ready to collect" | A job is overdue when its promised date has passed and it is not completed, delivered or cancelled; "ready to collect" = completed and not delivered (there is no "N days" cut-off yet) |

### Findings
| # | Finding | What was done |
|---|---|---|
| P7-01 | **A job left on account is a receivable like an invoice**, but Phase 6 only knew invoices: collecting a payment, applying an advance, the aging report, the statement and the customer page would all have ignored it | One list of "open documents" (`CustomerAccountService::openDocuments`): invoices and delivered jobs together, oldest first. The collect form accepts both (keys `12` and `j7`); cancelling a payment reopens jobs too; the aging report and customer page list both |
| P7-02 | A test caught a false alarm in the integrity check: after a job with an advance was cancelled and the advance handed back, the receipt looked "used for more than its amount" (the old allocation to the cancelled job still counted) | The check counts only allocations that still stand (invoices not cancelled, jobs not cancelled) plus refunds, the same rule that defines the customer's credit |
| P7-03 | Two slips in the first web version: the allocation model refused mass assignment (every job payment would have crashed) and the intake form built its empty job the same way | Found by the new tests before any screen was used; fixed |
| P7-04 | **Real concurrency, with mutation checks.** Three tills delivering one job: exactly one delivery, one invoice number, one ledger entry, one receipt. Two jobs of one customer delivered on credit together: only one fits under the limit. Two workshops promising the last toner: one gets it, the other leaves no line behind. An advance racing a cancellation, two people moving one job on at once, four parts added to one job at once (no deadlock). Without the customer and job locks the delivery tests fail | Locks are in place; the customer lock is the first read of delivery, advance and cancel (the rule found in Phase 6, applied from the start) |
| P7-05 | **Real-browser drill, 30 checks:** intake with customer search, the suggested charge and an advance; a walk-in job refused without a phone and then received; scanning a job number; a technician who sees only their own job and none of the money; part search and adding, a charge, status moves to completed; the cashier delivers with change; the invoice and its PDF; a manager cancels a job; dashboard, types and customer page. Two early script failures were mine (my new smooth scrolling made the script click where the button *used to be*; a confirm dialog button with the same label as the form button) | Fixed in the script, not the pages |
| P7-06 | The full-text of the job list's "find" box also matches the job number exactly: a scanner types the number from the slip's barcode and presses Enter, and the job opens (SRV-12) | A technician typing a number of a job that is not theirs gets the list, not the job |
| P7-07 | The PC had restarted: MariaDB is **not yet a Windows service** and was down when work resumed (all tests failed with "connection refused") | Restarted by hand with the documented command; the service installation is still waiting for an Administrator (carried over) |
| P7-08 | The real database now holds the owner's own administrator (`pos:install` was run), so it can no longer be reset for drills | A backup was taken first, then the one additive migration was applied; the browser drill ran on the scratch schema `mrpos_test`; backup and restore of a database full of service data is proven by an automated test in the sandbox (data, files, constraints and triggers) |

### Questions for the owner
| # | Question | Default if not answered |
|---|---|---|
| P7-Q1 | A delivered job was invoiced by mistake (wrong amount, wrong customer): may a manager **void the invoice** (like cancelling a sale, within 24 hours, with a reason and the password), putting the job back to "completed"? | Not built yet: today a delivered job is locked and a correction would be a manual entry. Say yes and I will add it |
| P7-Q2 | The customer declines the estimate but you want to charge an **inspection fee**: should a cancelled job be able to produce a small invoice? | Not built (it was a "Should"); today the advance is handed back or kept as the customer's credit |
| P7-Q3 | Read the **terms printed on the job slip** (Settings → Receipt → Service job terms; the default says devices not collected within 30 days are not the shop's responsibility) | Default wording kept |
| P7-Q4 | How long may a finished job wait before it shows in a "forgotten jobs" list (SRV-14)? | 30 days, as in the default slip text; the list itself is the "Ready to collect" tab today |
| P7-Q5 | Phase 8 covers **returns, expenses, profit and the reports**: which report do you want first (daily sales, who owes us, profit, stock value, technician work)? | Daily sales and profit first |

## 11. Phase 8 decisions and findings

### Defaults used for the questions put at the end of Phase 7 (the owner can change any of them)
| # | Question | Default used |
|---|---|---|
| P8-D1 | First report wanted | **All of them are built**, starting with the ones asked for: daily sales (sales summary and closing summary), profit, who owes us, stock value, technician work |
| P8-D2 | Service charges in profit | Shown in profit as asked: **service revenue (charges and parts, without tax, after job discounts) is counted when the job is delivered and invoiced, with the cost of its parts**; a labour-only job is pure profit; cancelled and unfinished jobs count for nothing; parts that were written off are a separate loss |
| P8-D3 | Who may take goods back | Managers and administrators (`sale_returns.create`); a cashier can look at returns and print a copy of a receipt. Handing money back needs `sale_returns.refund` as well; without it the manager chooses store credit or taking it off the debt |
| P8-D4 | A return always has an invoice | Yes: goods are returned **against an invoice** (the customer gets back exactly what they paid after discounts). Goods without a receipt can only be returned to a **supplier** without a bill, not from a customer |
| P8-D5 | What a return is worth | The returned units' share of the line's net amount and tax (both discounts included); the last units of a line take what is left. Never more than was paid |
| P8-D6 | Where the value goes | First off what the customer still owes on that invoice, then money back or store credit (a named customer; the walk-in customer always gets money back). Store credit is the customer's credit in the "Customer credit" method, spendable at the till |
| P8-D7 | Stock on a return | Only lines marked "put back in stock" (default for *resellable*) add stock, at the cost on the invoice line; a damaged or defective line is a write-off and a service item has no stock |
| P8-D8 | Purchase returns | Against a received purchase (never more than received less already sent back) or without a bill. The supplier credits what it billed for the units; the stock leaves at the landed cost; the credit first reduces what is owed on the bill; the rest stays as credit on the supplier's account or comes back as money |
| P8-D9 | Cancelling a return | `sales.void` / `purchases.void` (managers), with the password and a reason. A sales return whose store credit was already spent cannot be cancelled; goods sold again cannot be taken out of stock again |
| P8-D10 | Expenses | Cashiers may record them (`expenses.create`), managers correct (the same day only) and cancel; the bill (jpg, png, pdf up to 5 MB) is private. Expenses have no ledger |
| P8-D11 | Reports and cost | Every report needs the permission of its area; cost columns need `products.view_cost`, profit columns `reports.profit`; PDF / Excel / CSV need `reports.export` and are audited; **printing is allowed with the report's own permission** |
| P8-D12 | Money by method | Customer credit is not money, so it is left out of the money-in-and-out report; the report adds customer receipts, refunds, supplier payments and refunds, and expenses per method |

### Findings
| # | Finding | What was done |
|---|---|---|
| P8-01 | The design said a return is "credited or refunded at the user's choice". That would let a refund be paid while the customer still owed money on the same invoice | Settlement order is fixed (SRT-04): the invoice's debt first, then money or store credit |
| P8-02 | **Store credit is not a new kind of money.** Giving it as a ledger line would credit the account twice (the return already did) | Store credit is a receipt in the "Customer credit" method with **no ledger line**, so the Phase 6 mechanism lets the customer spend it; the integrity check leaves such receipts out of "every receipt is on the account" (check 22) |
| P8-03 | A purchase return against a bill that is still unpaid and a refund from the supplier would have made the bill say "owes 700" while the ledger said "owes 1000" | The credit is applied to the bill's debt first (as for customers); only what is left over can be refunded or kept as credit |
| P8-04 | The supplier credits what it billed, but the stock leaves at the landed cost (freight and invoice discount are spread over the lines): the two differ | Both are kept; the **difference is a line in the profit report** ("difference on goods sent back to suppliers") so profit stays true |
| P8-05 | The database's `CHECK` ("a refund return points at its payment") refused the first version, which wrote the return and then the payment | The payment is written first (the new test caught it before any screen was used) |
| P8-06 | Two slips found by the tests and the browser drill: a Blade directive glued to a word (`each@endcan`) is not recognised and broke a page; and a JavaScript method I had named `valueOf` clashes with the built-in one and left the live value of the return empty | Fixed; the drill now checks the live figures. Also found: a duplicate key in the settings list and a crafted address (`?preset[]=x`) that crashed the dashboard |
| P8-07 | **The profit engine** was written once (`ProfitService`) and everything reads it. A known-shop test (every figure worked out by hand: sales, a 60 discount, a returned and a damaged unit, a delivered job with charges, parts and a discount, a cancelled job, a written-off part, an expense and a cancelled one, a loss, the opening stock, a supplier return) proves it, and another proves that day / week / month / year groups add up to the whole period | Net profit of the known shop is exactly 80.00; labour 466.67 and parts 233.33 of the 700.00 job |
| P8-08 | **Real concurrency, with mutation checks.** Four terminals returning the same units: only the units that exist come back, the rest are refused; a return racing the cancelling of its invoice (one wins); a return racing a payment (same end state either way); the same return cancelled by three people (reversed once); the same for purchase returns; and five different operations on one customer at once (no deadlock). **With the row locks removed, the tests fail** (all four terminals "succeeded" and returned 8 of 5 units) | Locks are in place and tested |
| P8-09 | **Real-browser drill, 39 checks** (cashier: expense, no reports, no returns; manager: dashboard with profit, charts, periods and the phone view, a return with live values, the over-return guard, the signed return receipt, stock back on the shelf, cancel with the password, purchase returns with and without a bill, reports, the PDF / Excel / CSV downloads, the find box) | One script slip was mine (the 403 page has no sign-out button) |
| P8-10 | Comparing the SRS with what was built found gaps in the first version: the customer page had no returns, the profit report did not show the damaged-returns write-off (SRT-06 / PFT-05), no warranty indicator on the return screen (SRT-07), no search in reports, and the dashboard lacked money received, credit sales, purchases and the latest-activity lists (DASH-02 / 04) | All added and tested |
| P8-11 | The real database holds the owner's administrator, so it was **not reset**: a backup was taken first, then the one additive migration was applied; the drills ran on the scratch schema `mrpos_test` | See ENVIRONMENT_CHANGES |

### Questions for the owner
| # | Question | Default if not answered |
|---|---|---|
| P8-Q1 | **Credit with a supplier:** when goods are returned for a bill that was already paid, the credit stays on the supplier's account (it shows as a negative balance, and in the supplier report). Do you want a button "use this credit on the next bill"? | Not built: today you pay the next bill's amount less the credit and note it in the payment |
| P8-Q2 | Should there be a **time limit** on customer returns (for example 14 days from the invoice)? | No limit; managers decide |
| P8-Q3 | Should a **restocking fee** (keeping part of the money on a return) be possible? | Not built: a return gives back exactly what was paid for the goods |
| P8-Q4 | Do you want an **Exchange** button (return plus new sale in one screen)? | Not built: give store credit, then sell with "Customer credit" |
| P8-Q5 | Should **rounding differences** on invoices (the "round to nearest 10" setting) be a line in the profit report? | Not included: profit is worked out on net amounts |
| P8-Q6 | Who may see **expenses**? Cashiers may record them and see the list | Default kept; remove `expenses.view` from the Cashier role in Roles & permissions if you prefer |