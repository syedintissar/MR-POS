# Security review (Phase 10)

Checked against the OWASP Top 10 for a shop system used on a local network. "Evidence" names the test or file.

| # | Risk | Status | Evidence |
|---|---|---|---|
| A01 Broken access control | Every route has a permission check and the actions check again; technicians reach only their own jobs; cost, profit and expenses are cut out for people without the permission (screens, prints, PDF, CSV, Excel, JSON) | Pass | Role-matrix tests of every phase (Phase 8: 163 cases), `CostVisibilityTest`, `ReportsTest`, `ExpenseTest` |
| A02 Cryptographic failures | Passwords hashed (bcrypt); no plain-text secrets in the database (backup password and destination credentials are encrypted with the application key); backups can be AES-256 encrypted; no card data is handled | Pass | `PasswordTest`, backup tests |
| A03 Injection | Every query is parameterised; the only dynamic SQL is built from fixed lists (report groupings, column names), never from input; Blade escapes output; CSV and Excel exports neutralise formulas (`=`, `+`, `-`, `@`) | Pass | `ReportsTest::test_a_spreadsheet_cannot_be_made_to_run_a_formula`, view-compile tests |
| A04 Insecure design | Money is exact decimals; every critical step is one database transaction with a fixed lock order; real parallel-process tests with mutation checks; append-only ledgers and audit chain enforced by the database | Pass | Concurrency tests of Phases 4 to 8b |
| A05 Security misconfiguration | Safety headers on every page (a content security policy that allows nothing from other sites, no framing, no type sniffing, strict referrer, no camera or location); IP allow-list option; `APP_DEBUG=false` for production (INSTALLATION.md); no page fetches anything from the internet | Pass | `SecurityHeadersTest` |
| A06 Vulnerable components | `composer audit`: no advisories; `npm audit --omit=dev`: 0 vulnerabilities (2026-10-14). Re-run before every release | Pass | commands |
| A07 Identification and authentication failures | Login throttling, inactive users blocked, forced password change, sessions ended on deactivation or password change, password re-entry for sensitive actions (ten-minute window) | Pass | `LoginTest`, `PasswordTest` |
| A08 Software and data integrity | Backups are checksummed and verified, and restored with a safety backup; the audit log is a hash chain checked by `pos:audit:verify`; the twice-daily integrity check recomputes every cache from its ledger | Pass | backup and integrity tests |
| A09 Logging and monitoring | Every sale, void, return, exchange, refund, price change, permission change, export, print copy and backup action is audited with user, time and address; backup problems show as a red dot | Pass | audit tests |
| A10 Server-side request forgery | The system makes no outgoing requests | Pass | no HTTP client in use |

**Upload safety:** logos are re-encoded; expense bills (jpg, png, pdf up to 5 MB) are stored outside the web folder and served with `nosniff` only to people allowed to see expenses; backup files are verified before they are kept.

**Known limits:** the system is meant for the shop's local network over plain HTTP; if it is ever exposed beyond it, put HTTPS in front (and add HSTS). The content security policy keeps `unsafe-inline` and `unsafe-eval` for scripts because the screens use Alpine.js.
