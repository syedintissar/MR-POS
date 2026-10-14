# Implementation Roadmap

Each phase ends only when its **exit criteria** pass:
- All tests are green (`php artisan test`).
- Pint and Larastan are clean.
- `pos:integrity:check` reports zero drift on seeded and test data.
- Authorization is verified against the RBAC matrix for every new route.
- The docs are updated.

Later phases must not break earlier tests.

| Phase | Scope | Key deliverables | Exit criteria |
|---|---|---|---|
| **1** ✅ | Analysis & design | SRS, Architecture, Database + ERD, RBAC, Backup/Restore, Design Review, Roadmap, draft Installation and User Guide | Owner review of [DESIGN_REVIEW.md](DESIGN_REVIEW.md) open questions |
| **2** ✅ | Foundation | Laravel 13 project, `.env` for local MariaDB, hand-written login (username, throttling, audit), users, spatie roles and permissions + seeders, settings service + UI, layout (sidebar, top bar, toasts, modals), audit logger + triggers, document sequences, `pos:install` command, **minimal `pos:backup:run` (manual dump + zip)** so data is protected from the start | Login/throttle/inactive tests; role-route matrix test; settings audit; audit triggers reject UPDATE/DELETE |
| **3** ✅ | Catalogue & inventory | Categories (tree), brands, units, products (image upload, cost visibility), suppliers (master data), InventoryService + CostingService, stock movements, stock adjustments, stock card, low/out-of-stock lists, CSV/XLSX import, barcode labels | Product CRUD + validation; adjustment posting; negative-stock policy; concurrency test on `move()`; import with opening stock |
| **4** ✅ | Purchases & supplier accounts | Purchase draft → receive (landed cost, average cost), supplier ledger, supplier payments + allocation, purchase void, supplier profile and statement | Stock addition; average-cost math; payable balance; void reversal; rollback test |
| **5** ✅ | POS, sales & invoices | POS screen (search, scanner, cart, tiers, discounts, tax, round-off, split/partial payments, change), CompleteSale with locking and idempotency, held carts, manager approval, void, 80 mm and A4 print views, A4 PDF, reprint | Sale totals; stock deduction; last-unit race; duplicate submit; discount/price permissions; void reversal |
| **6** ✅ | Customers & receivables | Customer CRUD + profile, ledger, opening balances, credit limits, payment collection (FIFO/manual), payment void, statements, outstanding and aging reports, store credit | Credit sale; payment allocation; payment void; statement running balance; walk-in restrictions |
| **7** ✅ | Service / repair / refill | Service types, job intake + slip, state machine + history, technician assignment and scoping, parts consumption/return, charges, advances, deliver and invoice, cancel handling, service invoice prints, technician dashboard | Transitions allowed/refused; parts stock in/out; technician isolation; delivery ledger posting |
| **8** ✅ | Expenses, returns, profit & reports | Expenses (+ attachments), sales returns (settlement order, restock), purchase returns (with refunds received from a supplier), profit engine, the full report set with filters, print, PDF, CSV/XLSX, dashboard KPIs + charts | Return math including discount share; purchase return cost; known-dataset profit test; export permission test |
| **8b** ✅ | Owner decisions after Phase 8 (2026-10-13) | Supplier credit "Use supplier credit"; 7-day return period with manager exception; optional restocking fee; one-click exchange; rounding in net profit; expenses closed to cashiers | Credit never used twice (parallel test); exchange atomic; fee and period rules; cashier 403 on expenses |
| **9** ✅ | Backup/restore & audit completion | Full backup engine (tiers, GFS, catch-up, encryption), quick and deep verify, retention, destinations + transfer queue + retry, health warnings + heartbeat, restore UI + CLI with safety backup and rollback, audit hash chain + JSONL mirror + viewer, Recovery Kit | All BACKUP_RESTORE §14 tests; a manual disaster-recovery drill onto a second PC |
| **10** | Hardening & deployment | Security review (OWASP checklist, dependency audit, CSP), performance tuning (EXPLAIN, indexes, a 20k-product/500k-line dataset), final docs (Installation, User Guide), Windows deployment scripts (vhost, Task Scheduler XML, firewall rule), go-live checklist and data import | Load test meets NFR-02/03; security checklist signed off; install from scratch on a clean PC following the docs |

## Suggested go-live sequence
1. Phases 2–5 make a usable "cash sales" system. It can go live early for billing if the owner wants to.
2. Phases 6–7 add credit and service, the shop's main differentiators.
3. Phases 8–10 complete reporting, backup hardening and deployment.

Even during an early go-live, the Phase 2 minimal backup plus a manual daily copy to USB must be in place before any real data is entered.
