# MR-POS: Mr Printer & Tuner

A local-first POS, inventory, accounting, service/repair and shop management system. It is a Laravel 13 web app that runs on the shop's Windows server PC (Apache + PHP + MariaDB 11.4) and is used from browsers on the shop network. Normal operation never needs the internet.

**Current status:** all ten phases are built (Phase 10, hardening and deployment, awaits owner approval; Phases 1-9 are committed). See docs/GO_LIVE.md. Built so far: login and security, users, roles and permissions, settings, audit trail, document numbering, backup / restore, products, categories, brands, units, suppliers, stock ledger and adjustments, Excel/CSV import, barcode labels, purchases with landed cost and average cost, supplier payments, supplier accounts and statements, payment methods, and **the till: barcode POS, split payments with change, manager approval, held carts, 80 mm receipts, A4 invoices and PDFs, sales history and cancelling**. Phase 6 added **customers: credit sales up to a limit, customer payments (oldest invoice first or chosen invoices), customer credit, statements and "who owes us" aging**. Phase 7 added **service, repair and refill jobs: device intake with a printed slip, technician assignment and a status flow, parts taken from stock, advances, delivery with an invoice, cancellation, and what is left on account**. Phase 8 added **expenses with private bills, sales returns (the customer gets back what they paid; money, store credit or off their debt) and purchase returns, one profit engine that counts service charges and parts, seventeen reports with print, PDF, Excel and CSV (cost and profit only for people allowed to see them), and a dashboard with period filter, money cards, profit cards and charts**.

## Quick start (developer / first install)
```powershell
composer install
copy .env.example .env      # then fill in the database passwords
php artisan key:generate
npm ci; npm run build
php artisan pos:install     # migrate, load roles/permissions, create the first Admin
php artisan pos:doctor      # checks PHP extensions, database, clocks, folders, backup tools
vendor\bin\phpunit          # automated tests (use the mrpos_test schema)
```
Full instructions: [docs/INSTALLATION.md](docs/INSTALLATION.md).

## Documentation
| Document | Contents |
|---|---|
| [docs/SRS.md](docs/SRS.md) | Requirements, business rules, acceptance tests |
| [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) | Stack, topology, layers, key flows, security, decisions |
| [docs/DATABASE.md](docs/DATABASE.md) | Tables, relationships, ER diagrams, posting rules |
| [docs/RBAC.md](docs/RBAC.md) | Permissions and the default role matrix |
| [docs/BACKUP_RESTORE.md](docs/BACKUP_RESTORE.md) | Backup design, verification, retention, restore, and what is built |
| [docs/DESIGN_REVIEW.md](docs/DESIGN_REVIEW.md) | Inconsistencies found, owner decisions, Phase 2 findings |
| [docs/ENVIRONMENT_CHANGES.md](docs/ENVIRONMENT_CHANGES.md) | Every change made to the server PC, and what was *not* done |
| [docs/ROADMAP.md](docs/ROADMAP.md) | Phases and exit criteria |
| [docs/INSTALLATION.md](docs/INSTALLATION.md) | Windows setup, LAN access, firewall, scheduler, troubleshooting |
| [docs/USER_GUIDE.md](docs/USER_GUIDE.md) | Staff guide (grows with each phase) |
| [docs/PHASE2_REPORT.md](docs/PHASE2_REPORT.md) | Phase 2 summary for the owner |
| [docs/PHASE3_REPORT.md](docs/PHASE3_REPORT.md) | Phase 3 summary for the owner |
| [docs/PHASE4_REPORT.md](docs/PHASE4_REPORT.md) | Phase 4 summary for the owner |
| [docs/PHASE5_REPORT.md](docs/PHASE5_REPORT.md) | Phase 5 summary for the owner |
| [docs/PHASE6_REPORT.md](docs/PHASE6_REPORT.md) | Phase 6 summary for the owner |
| [docs/PHASE7_REPORT.md](docs/PHASE7_REPORT.md) | Phase 7 summary for the owner |
| [docs/PHASE8_REPORT.md](docs/PHASE8_REPORT.md) | Phase 8 summary for the owner |

Windows helper scripts (Administrator PowerShell where noted) are in [deploy/windows](deploy/windows).
