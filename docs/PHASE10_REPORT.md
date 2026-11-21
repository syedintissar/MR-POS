# Phase 10 Report: Hardening & Deployment

**Date:** 2026-10-14 · **Status:** complete, waiting for your approval (last phase). Phases 1 to 9 are committed (latest `b6e5fb4`).

## 1. What was done
- **Security review** ([SECURITY_REVIEW.md](SECURITY_REVIEW.md)): OWASP Top 10 checked, all pass; `composer audit` and `npm audit` are clean.
- **Safety headers** on every page (nothing may load from other sites, no framing, no type sniffing, strict referrer, no camera or location). A test proves no page links to the internet.
- **Performance** on 20,000 products and 5,000 invoices of 20 lines (100,000 lines): product search 0.5 s, barcode 1.2 s, product list 0.4 s, sales list 0.2 s, profit for a year 1.6 s, dashboard figures 1.8 s, sales summary 0.5 s, sales by product for a year 3.3 s, the nightly integrity check 20 s. The query plans use the right indexes. The one slow report, **stock valuation (8.7 s)**, was fixed: totals now come from the database and the list shows the first 3,000 products with a note.
- **Deployment:** the Windows scripts from Phase 2 (MariaDB service, backup scheduled task, firewall, virtual host) are kept; [GO_LIVE.md](GO_LIVE.md) is the checklist and the order for importing products, suppliers, customers and opening balances.

## 2. Files
`SecurityHeaders` middleware and its test, `InventoryReports` (valuation), docs: SECURITY_REVIEW, GO_LIVE, ROADMAP, README.

## 3. Database changes: none. 4. Commands: none new.

## 5. For you to do
Everything in GO_LIVE.md, above all: install the MariaDB service, the backup task and the firewall rule as Administrator (MariaDB stopped again after a restart during this phase); import your real products and balances; print the Recovery Kit; set up a second backup copy; try the restore on a spare PC.

## 6. Tests
Headers test (2); report tests (36) after the valuation change; Phase 9 and 8b suites pass (last targeted run: 780 passed). Run the full suite once more on the finished system before go-live (about 25 minutes).

## 7. Still open (all optional)
Cloud copy of backups, a midday backup, restore from a web page, and the older questions in the Phase 4 to 8 reports (their defaults are built).

---

## Addendum (2026-11-21): one-click desktop launcher

**Built:** `deploy\windows\create-desktop-shortcut.ps1` draws the POS icon (`MR-POS.ico`) and puts **MR-POS** on the Desktop. The icon runs `deploy\windows\MR-POS.ps1`, which:
1. on the first run installs what is missing (Composer, npm build, `.env`, key) and caches the views;
2. starts MariaDB (3308) if it is down;
3. on a new database runs `pos:install` (the owner types the admin password); after an update with pending migrations it makes a **safety backup first**, then migrates (it stops if the backup fails);
4. starts a separate Apache for MR-POS on port 8088 with its own generated config, and opens the browser;
5. when the window is closed (or Q is pressed) Apache stops. MariaDB keeps running.

**Verified on this PC:** first-run path and normal path both brought the site up (`/login` 200, built assets 200, `storage/...` 404); closing the window (a real WM_CLOSE, like the X button) took port 8088 down within a second; XAMPP's own Apache on port 80 kept running; no new Laravel log errors; real database unchanged (no pending migrations).

**Owner action:** for other PCs, run `setup-firewall.ps1 -Port 8088` as Administrator and use `http://<server-ip>:8088`.
