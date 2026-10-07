# MR-POS: Mr Printer & Tuner

A local-first POS, inventory, accounting, service/repair and shop management system. It is a Laravel 13 web app that runs on the shop's Windows server PC (Apache + PHP + MariaDB 11.4) and is used from browsers on the shop network. Normal operation never needs the internet.

**Current status:** Phase 4 (purchases and supplier accounts) complete and awaiting owner approval. Phases 1-3 are committed. Built so far: login and security, users, roles and permissions, settings, audit trail, document numbering, backup / restore, products, categories, brands, units, suppliers, stock ledger and adjustments, Excel/CSV import, barcode labels, purchases with landed cost and average cost, supplier payments, supplier accounts and statements, and payment methods. POS, customers, repair jobs and reports follow in Phases 5-8.

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

Windows helper scripts (Administrator PowerShell where noted) are in [deploy/windows](deploy/windows).
