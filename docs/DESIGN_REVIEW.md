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
