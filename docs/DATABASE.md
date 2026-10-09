# Database Design

**Engine:** MariaDB 10.11+/11.4 LTS or MySQL 8.0+ · InnoDB · `utf8mb4_unicode_ci` · **Version:** 1.0 (Phase 1)

---

## 1. Conventions

| Item | Convention |
|---|---|
| Primary keys | `id BIGINT UNSIGNED AUTO_INCREMENT` |
| Foreign keys | `<table_singular>_id`, always declared with a constraint. `ON DELETE RESTRICT` for anything financial; `ON DELETE CASCADE` only for pure child lines of an unposted parent (held carts) |
| Money | `DECIMAL(15,2)` (up to Rs. 9,999,999,999,999.99) |
| Unit cost | `DECIMAL(15,4)` (average cost precision) |
| Quantity | `DECIMAL(12,3)`, so toner powder in grams and ink in ml are supported |
| Rates | `DECIMAL(5,2)` percent |
| Status/type | `VARCHAR(30)` backed by PHP enums. Not DB ENUM, so adding a value does not need an ALTER on big tables. Valid values are listed below and enforced by CHECK constraints on key tables |
| Timestamps | `created_at`, `updated_at` (TIMESTAMP, app timezone Asia/Karachi). Business dates are separate columns (`sale_date`, `purchase_date`, …) |
| Soft deletes | `deleted_at` **only** on master data (products, customers, suppliers, categories, brands, units). Never on financial documents |
| Actor columns | `created_by`, `updated_by` → `users.id` (nullable for system/scheduler) |
| Snapshots | Lines store names, prices, costs and rates as of posting time, so later master-data edits never change history |
| Polymorphic refs | Used **only** for ledger/movement/audit back-references (`source_type`, `source_id`), never for core relationships. A morph map in `AppServiceProvider` keeps stored type names short and stable (`sale`, `purchase`, …) |

---

## 2. Entity catalogue

40 domain tables, plus the Laravel framework tables (`sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `password_reset_tokens`, `migrations`) and the spatie permission tables.

### 2.1 Identity, access & configuration

**users**
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| name | varchar(100) | |
| username | varchar(50) UNIQUE | login |
| email | varchar(150) UNIQUE NULL | |
| phone | varchar(30) NULL | |
| password | varchar(255) | bcrypt/argon2id hash |
| is_active | bool default 1 | |
| must_change_password | bool default 0 | |
| last_login_at, last_login_ip | timestamp NULL, varchar(45) NULL | |
| remember_token | varchar(100) NULL | |
| timestamps | | Users are never deleted, only deactivated (they are referenced everywhere) |

**roles, permissions, model_has_roles, model_has_permissions, role_has_permissions**: the standard spatie/laravel-permission schema (`guard_name = web`). The permission catalogue is in [RBAC.md](RBAC.md).

**settings**
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| group | varchar(50) | shop, sales, tax, inventory, receipt, backup, security |
| key | varchar(100) UNIQUE | e.g. `sales.max_discount_percent` |
| value | text NULL | |
| type | varchar(20) | string, int, decimal, bool, json, encrypted |
| updated_by | FK users NULL | |
| timestamps | | |

**payment_methods**
| id | name varchar(50) UNIQUE | code varchar(30) UNIQUE | kind (`cash`,`bank`,`card`,`wallet`,`customer_credit`,`other`) | requires_reference bool | is_active bool | is_system bool (cash and customer_credit cannot be removed) | sort_order int | timestamps |
|---|---|---|---|---|---|---|---|---|

Seeded with: Cash, Bank Transfer, Card, Easypaisa, JazzCash, Cheque, Customer Credit.

**document_sequences**
| id | doc_type varchar(30) UNIQUE | label varchar(60) | prefix varchar(20) | next_number BIGINT UNSIGNED | padding tinyint | reset_yearly bool | current_year smallint NULL | timestamps |
|---|---|---|---|---|---|---|---|

### 2.2 Catalogue

**categories**: id, parent_id FK categories NULL (max depth 2, enforced in the app), name varchar(100), slug, is_active, sort_order, timestamps, deleted_at. UNIQUE(parent_id, name).

**brands**: id, name varchar(100) UNIQUE, is_active, timestamps, deleted_at.

**units**: id, name varchar(30), short_name varchar(10) UNIQUE, allows_decimal bool, timestamps.

**products**
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| type | varchar(20) | `stock` / `service`, CHECK |
| name | varchar(200) | FULLTEXT(name, model) index for search |
| sku | varchar(50) UNIQUE | |
| barcode | varchar(64) UNIQUE NULL | |
| category_id | FK categories | leaf or parent |
| brand_id | FK brands NULL | |
| unit_id | FK units | |
| model | varchar(100) NULL | indexed |
| description | text NULL | |
| last_purchase_cost | DECIMAL(15,4) default 0 | |
| average_cost | DECIMAL(15,4) default 0 | system-maintained |
| sale_price | DECIMAL(15,2) | CHECK ≥ 0 |
| wholesale_price | DECIMAL(15,2) NULL | |
| min_sale_price | DECIMAL(15,2) NULL | CHECK ≤ sale_price (app) |
| tax_rate | DECIMAL(5,2) NULL | NULL = default rate |
| current_stock | DECIMAL(12,3) default 0 | cache of stock_movements; always 0 for `service` |
| min_stock_level | DECIMAL(12,3) NULL | NULL = global threshold |
| rack_location | varchar(50) NULL | |
| default_supplier_id | FK suppliers NULL | |
| warranty_months | smallint NULL | |
| warranty_terms | varchar(255) NULL | |
| image_path | varchar(255) NULL | private disk path |
| is_active | bool | |
| created_by, updated_by | FK users NULL | |
| timestamps, deleted_at | | |

Indexes: (is_active, category_id), (brand_id), (model), plus the unique and fulltext indexes above.

### 2.3 Inventory

**stock_movements** (append-only)
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| product_id | FK products | |
| movement_type | varchar(30) | opening, purchase, purchase_return, purchase_void, sale, sale_return, sale_void, service_consumption, service_return, adjustment_in, adjustment_out (CHECK) |
| quantity | DECIMAL(12,3) | signed: + in, − out; CHECK ≠ 0 |
| qty_before | DECIMAL(12,3) | |
| qty_after | DECIMAL(12,3) | CHECK qty_after = qty_before + quantity |
| unit_cost | DECIMAL(15,4) | |
| avg_cost_after | DECIMAL(15,4) | for audit of the costing |
| source_type, source_id | varchar(30), BIGINT | morph to sale, purchase, sale_return, purchase_return, service_job, stock_adjustment |
| source_line_id | BIGINT NULL | the specific line |
| notes | varchar(255) NULL | |
| user_id | FK users NULL | |
| created_at | timestamp | (no updated_at) |

Indexes: (product_id, id), (source_type, source_id), (movement_type, created_at), (created_at).

**stock_adjustments**: id, adjustment_no UNIQUE, adjustment_date, reason (count_correction, damage, loss, found, expiry, opening, other), notes, status (`posted`,`voided`), voided_at/by/reason, created_by, timestamps.
**stock_adjustment_items**: id, stock_adjustment_id FK, product_id FK, quantity DECIMAL(12,3) signed (≠ 0), unit_cost DECIMAL(15,4), notes.

### 2.4 Parties & ledgers

**customers**
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| code | varchar(20) UNIQUE | auto |
| name | varchar(150) | indexed |
| company | varchar(150) NULL | |
| phone | varchar(30) NULL | indexed (not unique: families or companies share numbers) |
| whatsapp | varchar(30) NULL | |
| email | varchar(150) NULL | |
| address | text NULL | |
| price_tier | varchar(20) default `retail` | retail / wholesale |
| allow_credit | bool default 0 | |
| credit_limit | DECIMAL(15,2) NULL | NULL = unlimited when allow_credit |
| opening_balance | DECIMAL(15,2) default 0 | informational; posted as a ledger entry |
| balance | DECIMAL(15,2) default 0 | cache; + = customer owes the shop, − = shop owes the customer (store credit) |
| is_walk_in | bool default 0 | exactly one row; a generated column + UNIQUE enforces at most one |
| is_active | bool | |
| notes | text NULL | |
| created_by, updated_by, timestamps, deleted_at | | |

**suppliers**: id, code UNIQUE, name, company, contact_person, phone, whatsapp, email, address, ntn, opening_balance, balance (cache; + = the shop owes the supplier), is_active, notes, created_by, updated_by, timestamps, deleted_at.

**customer_ledger_entries** (append-only)
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| customer_id | FK customers | |
| entry_date | date | business date |
| entry_type | varchar(30) | opening_balance, sale, sale_void, service_invoice, service_void, payment, payment_void, refund, refund_void, sale_return, balance_adjustment |
| debit | DECIMAL(15,2) default 0 | increases what the customer owes |
| credit | DECIMAL(15,2) default 0 | decreases what the customer owes |
| source_type, source_id | morph | |
| description | varchar(255) | |
| created_by | FK users NULL | |
| created_at | timestamp | |

CHECK `debit >= 0 AND credit >= 0 AND (debit > 0) <> (credit > 0)` (exactly one side). Index (customer_id, entry_date, id).

**supplier_ledger_entries**: the same shape. Types: opening_balance, purchase, purchase_void, payment, payment_void, purchase_return, refund_received, balance_adjustment. **credit** increases the payable, **debit** decreases it.

### 2.5 Purchasing

**purchases**
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| purchase_no | varchar(30) UNIQUE NULL | assigned when received (drafts have none) |
| supplier_id | FK suppliers | |
| supplier_invoice_no | varchar(50) NULL | UNIQUE(supplier_id, supplier_invoice_no) |
| purchase_date | date | |
| status | varchar(20) | draft, received, voided (CHECK) |
| subtotal | DECIMAL(15,2) | Σ line qty×cost − line discounts |
| discount_amount | DECIMAL(15,2) | invoice-level |
| tax_amount | DECIMAL(15,2) | |
| additional_cost | DECIMAL(15,2) | freight etc. |
| grand_total | DECIMAL(15,2) | |
| paid_amount | DECIMAL(15,2) | cache = Σ purchase_payments of non-voided payments |
| returned_amount | DECIMAL(15,2) | cache |
| due_amount | DECIMAL(15,2) | cache = grand_total − returned_amount − paid_amount (≥ 0 as displayed) |
| payment_status | varchar(20) | unpaid, partial, paid |
| notes | text NULL | |
| received_at, received_by | | |
| voided_at, voided_by, void_reason | | |
| created_by, updated_by, timestamps | | |

**purchase_items**: id, purchase_id FK, product_id FK, quantity (> 0), unit_cost DECIMAL(15,4), discount_amount, tax_amount, line_total DECIMAL(15,2), landed_unit_cost DECIMAL(15,4) (set on receive), returned_quantity DECIMAL(12,3) default 0 (cache, CHECK ≤ quantity).

**supplier_payments**: id, payment_no UNIQUE, supplier_id FK, payment_date, payment_method_id FK, type (`payment` = shop pays the supplier, `refund` = supplier refunds the shop), amount DECIMAL(15,2) (> 0), reference_no, notes, status (posted, voided), voided_at/by/reason, created_by, timestamps.

**purchase_payments** (allocation): id, supplier_payment_id FK, purchase_id FK, amount (> 0). UNIQUE(supplier_payment_id, purchase_id). Rule: Σ allocations ≤ payment amount. The unallocated rest is a supplier advance.

**purchase_returns**: id, return_no UNIQUE, supplier_id FK, purchase_id FK NULL, return_date, subtotal, tax_amount, total, settlement (`adjust_balance`, `refund`), supplier_payment_id FK NULL (refund receipt), reason, status (posted, voided), voided_*, created_by, timestamps.
**purchase_return_items**: id, purchase_return_id FK, purchase_item_id FK NULL, product_id FK, quantity, unit_cost DECIMAL(15,4), line_total.

### 2.6 Sales

**sales**
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| invoice_no | varchar(30) UNIQUE | |
| idempotency_key | char(36) UNIQUE | from the POS client |
| customer_id | FK customers | walk-in if none |
| user_id | FK users | cashier |
| approved_by | FK users NULL | manager approval, if any |
| sale_date | datetime | |
| price_tier | varchar(20) | snapshot |
| status | varchar(20) | completed, voided (CHECK) |
| items_total | DECIMAL(15,2) | Σ qty × unit_price |
| line_discount_total | DECIMAL(15,2) | |
| invoice_discount_amount | DECIMAL(15,2) | |
| invoice_discount_percent | DECIMAL(5,2) NULL | if entered as % |
| tax_total | DECIMAL(15,2) | |
| round_off | DECIMAL(15,2) | may be negative |
| grand_total | DECIMAL(15,2) | |
| paid_amount | DECIMAL(15,2) | cache: Σ sale_payments of non-voided receipts − refunds against this sale |
| returned_amount | DECIMAL(15,2) | cache |
| due_amount | DECIMAL(15,2) | cache |
| payment_status | varchar(20) | paid, partial, unpaid |
| amount_tendered, change_amount | DECIMAL(15,2) | cash handling, for the receipt |
| notes | text NULL | |
| print_notes | bool | |
| voided_at, voided_by, void_reason | | |
| client_ip | varchar(45) | terminal |
| timestamps | | |

Indexes: (sale_date), (customer_id, sale_date), (user_id, sale_date), (status, payment_status).

**sale_items**
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| sale_id | FK sales | |
| product_id | FK products | |
| product_name, sku | snapshots | |
| quantity | DECIMAL(12,3) > 0 | |
| list_price | DECIMAL(15,2) | price before any override (to report overrides) |
| unit_price | DECIMAL(15,2) | charged price |
| discount_amount | DECIMAL(15,2) | line discount |
| invoice_discount_share | DECIMAL(15,2) | allocated header discount |
| tax_rate | DECIMAL(5,2) | |
| tax_amount | DECIMAL(15,2) | |
| net_amount | DECIMAL(15,2) | excl. tax, after both discounts (= revenue) |
| line_total | DECIMAL(15,2) | net_amount + tax |
| unit_cost | DECIMAL(15,4) | average cost at sale (0 for service products) |
| serial_number | varchar(100) NULL | |
| warranty_until | date NULL | |
| returned_quantity | DECIMAL(12,3) default 0 | cache, CHECK ≤ quantity |

Index (product_id, sale_id).

**held_sales**: id, user_id FK, customer_id FK NULL, label varchar(100), cart JSON, timestamps. It is not a financial document and is deleted when resumed or discarded.

**customer_payments**
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| receipt_no | varchar(30) UNIQUE | |
| customer_id | FK customers | |
| payment_date | datetime | |
| payment_method_id | FK payment_methods | |
| type | varchar(20) | `receipt` (money in) / `refund` (money out) (CHECK) |
| amount | DECIMAL(15,2) > 0 | |
| reference_no | varchar(100) NULL | bank/wallet transaction id |
| notes | varchar(255) NULL | |
| status | varchar(20) | posted, voided |
| voided_at, voided_by, void_reason | | |
| created_by, timestamps | | |

Payment method `customer_credit` is special. It does not post a new ledger credit, because the credit already exists; it only allocates the customer's existing credit balance to the invoice.

**sale_payments** (allocation): id, customer_payment_id FK, sale_id FK, amount (> 0). UNIQUE(customer_payment_id, sale_id).
**service_job_payments** (allocation): id, customer_payment_id FK, service_job_id FK, amount (> 0). UNIQUE(customer_payment_id, service_job_id).

**sale_returns**
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| return_no | varchar(30) UNIQUE | |
| sale_id | FK sales | required |
| customer_id | FK customers | |
| return_date | datetime | |
| net_total | DECIMAL(15,2) | excl. tax |
| tax_total | DECIMAL(15,2) | |
| total | DECIMAL(15,2) | |
| applied_to_due | DECIMAL(15,2) | part that reduced the invoice's outstanding amount |
| refund_amount | DECIMAL(15,2) | cash/bank paid back |
| store_credit_amount | DECIMAL(15,2) | left as a credit on the customer account |
| refund_payment_id | FK customer_payments NULL | the `refund` payment row |
| exchange_sale_id | FK sales NULL | |
| reason | varchar(255) | |
| status | varchar(20) | posted, voided |
| voided_*, created_by, timestamps | | |

CHECK `applied_to_due + refund_amount + store_credit_amount = total`.

**sale_return_items**: id, sale_return_id FK, sale_item_id FK, product_id FK, quantity (> 0), unit_net_price DECIMAL(15,4), unit_tax DECIMAL(15,4), net_amount, tax_amount, line_total, unit_cost DECIMAL(15,4) (from sale_item), condition (resellable, damaged, defective), restock bool.

### 2.7 Service

**service_types**: id, name UNIQUE, default_charge DECIMAL(15,2) NULL, is_active, sort_order, timestamps.

**service_jobs**
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| job_no | varchar(30) UNIQUE | |
| invoice_no | varchar(30) UNIQUE NULL | assigned at delivery |
| customer_id | FK customers | walk-in allowed only if fully paid at delivery |
| contact_phone | varchar(30) | snapshot |
| service_type_id | FK service_types | |
| device_type | varchar(20) | printer, toner, cartridge, other |
| brand_id | FK brands NULL | |
| model, serial_number, cartridge_model | varchar NULL | serial indexed |
| reported_problem | text | |
| diagnosis | text NULL | |
| accessories_received | varchar(255) NULL | |
| received_at | datetime | |
| expected_delivery_at | datetime NULL | |
| technician_id | FK users NULL | indexed |
| priority | varchar(10) | normal, urgent |
| status | varchar(30) | see §7.4 (CHECK) |
| estimate_amount | DECIMAL(15,2) NULL | |
| approved_at | datetime NULL | |
| approval_method | varchar(20) NULL | in_person, phone, whatsapp |
| service_charges | DECIMAL(15,2) | Σ charge lines (cache) |
| parts_amount | DECIMAL(15,2) | Σ billed part lines (cache) |
| parts_cost | DECIMAL(15,2) | Σ qty × unit_cost of consumed parts (cache, internal) |
| discount_amount | DECIMAL(15,2) | |
| tax_amount | DECIMAL(15,2) | |
| grand_total | DECIMAL(15,2) | |
| paid_amount, due_amount | DECIMAL(15,2) | caches |
| payment_status | varchar(20) | |
| completed_at, delivered_at, invoiced_at | datetime NULL | |
| cancelled_at, cancel_reason | | |
| internal_notes, customer_notes | text NULL | |
| created_by, updated_by, timestamps | | |

Indexes: (status, technician_id), (customer_id), (received_at), (serial_number).

**service_job_items**: id, service_job_id FK, line_type (`part`, `charge`), product_id FK NULL (required for `part`, CHECK), description varchar(255), quantity DECIMAL(12,3) > 0, unit_price DECIMAL(15,2) (billed), unit_cost DECIMAL(15,4) (snapshot), discount_amount, line_total, status (`active`, `removed`), added_by, added_at, removed_by, removed_at, removal_disposition (`returned_to_stock`, `written_off`) NULL.

**service_status_history**: id, service_job_id FK, from_status NULL, to_status, note varchar(255) NULL, changed_by FK users, changed_at. Append-only.

### 2.8 Expenses

**expense_categories**: id, name UNIQUE, is_active, timestamps.
**expenses**: id, expense_no UNIQUE, expense_category_id FK, expense_date, amount (> 0), payment_method_id FK, payee varchar(150) NULL, description text, attachment_path NULL, status (posted, voided), voided_*, created_by (= "entered by"), updated_by, timestamps. Index (expense_date), (expense_category_id, expense_date).

### 2.9 Backup & audit

**backups**
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| tier | varchar(20) | intraday, daily, weekly, monthly, manual, safety, uploaded |
| status | varchar(20) | running, success, failed |
| filename | varchar(255) | `mrpos_2026-10-06_2100_daily.zip` |
| disk_path | varchar(500) | relative to the backup root |
| size_bytes | BIGINT | |
| sha256 | char(64) | of the zip |
| encrypted | bool | |
| includes_files | bool | |
| db_schema_version | varchar(255) | last migration name |
| app_version | varchar(20) | |
| row_counts | JSON | per table (also in the manifest) |
| started_at, finished_at | datetime | |
| verify_status | varchar(20) | pending, passed, failed |
| verify_level | varchar(10) | quick, deep |
| verified_at | datetime NULL | |
| verify_notes | text NULL | |
| error_message | text NULL | |
| is_protected | bool | excluded from retention |
| triggered_by | FK users NULL | NULL = scheduler |
| pruned_at | datetime NULL | file removed by retention; the row is kept |
| timestamps | | |

**backup_destinations**: id, name, driver (`local_path`, `sftp`, `s3`, `rclone`), config (encrypted JSON), is_active, requires_encryption bool, last_success_at, last_error, timestamps.

**backup_transfers**: id, backup_id FK, backup_destination_id FK, status (pending, running, success, failed), attempts int, next_attempt_at, last_attempt_at, remote_path, error_message, completed_at, timestamps. UNIQUE(backup_id, backup_destination_id).

**backup_restores**: id, backup_id FK NULL (NULL if the restored backup row no longer exists), source_filename, safety_backup_filename, status (success, failed, rolled_back), started_at, finished_at, performed_by FK users NULL, error_message. This row is **inserted after** the restore, from data held in memory and in the restore log file, so the restored database records its own restore.

**audit_logs** (append-only, protected by triggers)
| Column | Type | Notes |
|---|---|---|
| id | PK | |
| user_id | FK users NULL | |
| event | varchar(50) | created, updated, voided, completed, refunded, login, login_failed, permission_changed, backup_created, restore_performed, … |
| module | varchar(50) | sales, inventory, … |
| auditable_type, auditable_id | morph NULL | |
| old_values, new_values | JSON NULL | sensitive fields masked |
| description | varchar(500) | |
| ip_address | varchar(45) | |
| user_agent | varchar(255) | |
| url | varchar(500) | |
| prev_hash | char(64) | |
| hash | char(64) | SHA-256(prev_hash ‖ canonical row JSON) |
| created_at | timestamp(6) | |

Indexes: (auditable_type, auditable_id), (user_id, created_at), (module, created_at), (event).

Triggers (created in a migration):
```sql
CREATE TRIGGER audit_logs_no_update BEFORE UPDATE ON audit_logs FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
CREATE TRIGGER audit_logs_no_delete BEFORE DELETE ON audit_logs FOR EACH ROW
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs is append-only';
```
The same triggers are applied to `stock_movements`, `customer_ledger_entries`, `supplier_ledger_entries` and `service_status_history`. A restore drops and recreates whole tables, so it is unaffected; triggers fire on row changes only.

---

## 3. Relationship summary

| Parent | Child | Cardinality |
|---|---|---|
| categories | categories (parent_id) | 1 : N (depth ≤ 2) |
| categories / brands / units / suppliers | products | 1 : N |
| products | stock_movements, sale_items, purchase_items, service_job_items, stock_adjustment_items, sale_return_items, purchase_return_items | 1 : N |
| customers | sales, customer_payments, sale_returns, service_jobs, customer_ledger_entries | 1 : N |
| suppliers | purchases, supplier_payments, purchase_returns, supplier_ledger_entries | 1 : N |
| sales | sale_items, sale_payments, sale_returns | 1 : N |
| customer_payments | sale_payments, service_job_payments | 1 : N (one payment settles many documents) |
| sales ↔ customer_payments | via sale_payments | M : N |
| service_jobs ↔ customer_payments | via service_job_payments | M : N |
| purchases ↔ supplier_payments | via purchase_payments | M : N |
| purchases | purchase_items, purchase_returns | 1 : N |
| sale_items | sale_return_items | 1 : N |
| purchase_items | purchase_return_items | 1 : N |
| service_types | service_jobs | 1 : N |
| service_jobs | service_job_items, service_status_history | 1 : N |
| users | sales (cashier), service_jobs (technician), every created_by | 1 : N |
| payment_methods | customer_payments, supplier_payments, expenses | 1 : N |
| expense_categories | expenses | 1 : N |
| backups | backup_transfers | 1 : N |
| backup_destinations | backup_transfers | 1 : N |

---

## 4. ER diagrams

The model is split into four domain diagrams for readability. Shared entities (products, customers, users) appear in more than one.

### 4.1 Catalogue, inventory & purchasing

```mermaid
erDiagram
  CATEGORIES ||--o{ CATEGORIES : "parent of"
  CATEGORIES ||--o{ PRODUCTS : classifies
  BRANDS ||--o{ PRODUCTS : brands
  UNITS ||--o{ PRODUCTS : measures
  SUPPLIERS ||--o{ PRODUCTS : "default supplier"
  PRODUCTS ||--o{ STOCK_MOVEMENTS : "ledger of"
  STOCK_ADJUSTMENTS ||--|{ STOCK_ADJUSTMENT_ITEMS : contains
  PRODUCTS ||--o{ STOCK_ADJUSTMENT_ITEMS : adjusted
  SUPPLIERS ||--o{ PURCHASES : supplies
  PURCHASES ||--|{ PURCHASE_ITEMS : contains
  PRODUCTS ||--o{ PURCHASE_ITEMS : bought
  SUPPLIERS ||--o{ SUPPLIER_PAYMENTS : receives
  SUPPLIER_PAYMENTS ||--o{ PURCHASE_PAYMENTS : "allocated via"
  PURCHASES ||--o{ PURCHASE_PAYMENTS : "settled by"
  SUPPLIERS ||--o{ PURCHASE_RETURNS : "returned to"
  PURCHASES |o--o{ PURCHASE_RETURNS : "returned from"
  PURCHASE_RETURNS ||--|{ PURCHASE_RETURN_ITEMS : contains
  PURCHASE_ITEMS |o--o{ PURCHASE_RETURN_ITEMS : "returned line"
  SUPPLIERS ||--o{ SUPPLIER_LEDGER_ENTRIES : "account of"
  PAYMENT_METHODS ||--o{ SUPPLIER_PAYMENTS : via

  PRODUCTS {
    bigint id PK
    string type
    string sku UK
    string barcode UK
    bigint category_id FK
    bigint brand_id FK
    bigint unit_id FK
    decimal average_cost
    decimal sale_price
    decimal min_sale_price
    decimal current_stock
    decimal min_stock_level
  }
  STOCK_MOVEMENTS {
    bigint id PK
    bigint product_id FK
    string movement_type
    decimal quantity
    decimal qty_before
    decimal qty_after
    decimal unit_cost
    string source_type
    bigint source_id
    bigint user_id FK
  }
  PURCHASES {
    bigint id PK
    string purchase_no UK
    bigint supplier_id FK
    string supplier_invoice_no
    string status
    decimal grand_total
    decimal paid_amount
    decimal due_amount
  }
  PURCHASE_ITEMS {
    bigint id PK
    bigint purchase_id FK
    bigint product_id FK
    decimal quantity
    decimal unit_cost
    decimal landed_unit_cost
    decimal returned_quantity
  }
  SUPPLIER_LEDGER_ENTRIES {
    bigint id PK
    bigint supplier_id FK
    string entry_type
    decimal debit
    decimal credit
  }
```

### 4.2 Sales, payments & returns

```mermaid
erDiagram
  CUSTOMERS ||--o{ SALES : buys
  USERS ||--o{ SALES : "cashier of"
  SALES ||--|{ SALE_ITEMS : contains
  PRODUCTS ||--o{ SALE_ITEMS : sold
  CUSTOMERS ||--o{ CUSTOMER_PAYMENTS : pays
  PAYMENT_METHODS ||--o{ CUSTOMER_PAYMENTS : via
  CUSTOMER_PAYMENTS ||--o{ SALE_PAYMENTS : "allocated via"
  SALES ||--o{ SALE_PAYMENTS : "settled by"
  SALES ||--o{ SALE_RETURNS : "returned from"
  SALE_RETURNS ||--|{ SALE_RETURN_ITEMS : contains
  SALE_ITEMS ||--o{ SALE_RETURN_ITEMS : "returned line"
  SALE_RETURNS |o--o| CUSTOMER_PAYMENTS : "refund paid by"
  CUSTOMERS ||--o{ CUSTOMER_LEDGER_ENTRIES : "account of"
  USERS ||--o{ HELD_SALES : holds

  SALES {
    bigint id PK
    string invoice_no UK
    char idempotency_key UK
    bigint customer_id FK
    bigint user_id FK
    datetime sale_date
    string status
    decimal grand_total
    decimal paid_amount
    decimal due_amount
    string payment_status
  }
  SALE_ITEMS {
    bigint id PK
    bigint sale_id FK
    bigint product_id FK
    decimal quantity
    decimal unit_price
    decimal invoice_discount_share
    decimal net_amount
    decimal unit_cost
    decimal returned_quantity
  }
  CUSTOMER_PAYMENTS {
    bigint id PK
    string receipt_no UK
    bigint customer_id FK
    bigint payment_method_id FK
    string type
    decimal amount
    string status
  }
  SALE_PAYMENTS {
    bigint id PK
    bigint customer_payment_id FK
    bigint sale_id FK
    decimal amount
  }
  SALE_RETURNS {
    bigint id PK
    string return_no UK
    bigint sale_id FK
    decimal total
    decimal applied_to_due
    decimal refund_amount
    decimal store_credit_amount
  }
  CUSTOMER_LEDGER_ENTRIES {
    bigint id PK
    bigint customer_id FK
    string entry_type
    decimal debit
    decimal credit
    string source_type
    bigint source_id
  }
```

### 4.3 Service / repair

```mermaid
erDiagram
  CUSTOMERS ||--o{ SERVICE_JOBS : brings
  SERVICE_TYPES ||--o{ SERVICE_JOBS : "type of"
  BRANDS |o--o{ SERVICE_JOBS : "device brand"
  USERS |o--o{ SERVICE_JOBS : "technician of"
  SERVICE_JOBS ||--o{ SERVICE_JOB_ITEMS : uses
  PRODUCTS |o--o{ SERVICE_JOB_ITEMS : "part consumed"
  SERVICE_JOBS ||--|{ SERVICE_STATUS_HISTORY : "status trail"
  CUSTOMER_PAYMENTS ||--o{ SERVICE_JOB_PAYMENTS : "allocated via"
  SERVICE_JOBS ||--o{ SERVICE_JOB_PAYMENTS : "settled by"

  SERVICE_JOBS {
    bigint id PK
    string job_no UK
    string invoice_no UK
    bigint customer_id FK
    bigint service_type_id FK
    bigint technician_id FK
    string device_type
    string serial_number
    string status
    decimal service_charges
    decimal parts_amount
    decimal parts_cost
    decimal grand_total
    decimal paid_amount
    decimal due_amount
  }
  SERVICE_JOB_ITEMS {
    bigint id PK
    bigint service_job_id FK
    string line_type
    bigint product_id FK
    decimal quantity
    decimal unit_price
    decimal unit_cost
    string status
  }
  SERVICE_STATUS_HISTORY {
    bigint id PK
    bigint service_job_id FK
    string from_status
    string to_status
    bigint changed_by FK
    datetime changed_at
  }
```

### 4.4 Administration, expenses, backup & audit

```mermaid
erDiagram
  USERS }o--o{ ROLES : "model_has_roles"
  ROLES }o--o{ PERMISSIONS : "role_has_permissions"
  USERS }o--o{ PERMISSIONS : "model_has_permissions (direct)"
  EXPENSE_CATEGORIES ||--o{ EXPENSES : groups
  PAYMENT_METHODS ||--o{ EXPENSES : "paid via"
  USERS ||--o{ EXPENSES : "entered by"
  BACKUPS ||--o{ BACKUP_TRANSFERS : "copied by"
  BACKUP_DESTINATIONS ||--o{ BACKUP_TRANSFERS : "target of"
  BACKUPS |o--o{ BACKUP_RESTORES : "restored from"
  USERS |o--o{ BACKUPS : triggered
  USERS |o--o{ AUDIT_LOGS : performed

  EXPENSES {
    bigint id PK
    string expense_no UK
    bigint expense_category_id FK
    date expense_date
    decimal amount
    bigint payment_method_id FK
    string status
  }
  BACKUPS {
    bigint id PK
    string tier
    string status
    string filename
    char sha256
    string verify_status
    bool is_protected
  }
  BACKUP_TRANSFERS {
    bigint id PK
    bigint backup_id FK
    bigint backup_destination_id FK
    string status
    int attempts
    datetime next_attempt_at
  }
  AUDIT_LOGS {
    bigint id PK
    bigint user_id FK
    string event
    string module
    string auditable_type
    bigint auditable_id
    json old_values
    json new_values
    char prev_hash
    char hash
  }
  SETTINGS {
    bigint id PK
    string key UK
    text value
    string type
  }
  DOCUMENT_SEQUENCES {
    bigint id PK
    string doc_type UK
    string prefix
    bigint next_number
  }
```

---

## 5. Posting rules (what each operation writes)

This table is the contract the Action classes and their tests implement. "Ledger Dr/Cr" refers to the party ledger.

| Operation | Documents | Stock movement | Customer ledger | Supplier ledger | Payments |
|---|---|---|---|---|---|
| Complete sale | sales, sale_items | `sale` −qty per stock line | Dr grand_total (named customers only; the walk-in customer gets no ledger entries because it must be fully paid) | – | customer_payments `receipt` + sale_payments, Cr each |
| Sale paid with customer credit | as above | as above | Dr grand_total only (credit already on account) | – | sale_payments allocation from the existing advance payment rows |
| Void sale | status=voided | `sale_void` +qty | Cr grand_total (`sale_void`) | – | each linked receipt voided → Dr (`payment_void`) |
| Customer payment | customer_payments, sale_payments / service_job_payments | – | Cr amount | – | – |
| Void customer payment | status=voided | – | Dr amount (`payment_void`) | – | allocations reversed (caches recomputed) |
| Sales return | sale_returns, sale_return_items | `sale_return` +qty (restock lines only) | Cr total (`sale_return`); if refund: Dr refund (`refund`) | – | refund → customer_payments `refund` |
| Receive purchase | purchases.status=received | `purchase` +qty | – | Cr grand_total | optional supplier_payments + purchase_payments, Dr each |
| Void purchase | status=voided | `purchase_void` −qty | – | Dr grand_total | linked payments voided → Cr |
| Supplier payment | supplier_payments, purchase_payments | – | – | Dr amount | – |
| Purchase return | purchase_returns, items | `purchase_return` −qty | – | Dr total; if refund received: Cr refund + supplier_payments `refund` | |
| Add service part | service_job_items | `service_consumption` −qty | – | – | – |
| Remove service part | item.status=removed | `service_return` +qty at the cost it left at, or **none if written off** (the part already left the stock when it was added; an `adjustment_out` as well would take it out twice: see §13) | – | – | – |
| Service advance / payment | customer_payments, service_job_payments | – | Cr amount | – | – |
| Deliver service job | service_jobs invoice_no, delivered_at | – | Dr grand_total (`service_invoice`) | – | final payment as above |
| Stock adjustment | stock_adjustments, items | `adjustment_in/out` | – | – | – |
| Expense | expenses | – | – | – | – (cash-book reporting uses expenses.payment_method_id) |
| Opening balance | customers/suppliers | – | Dr opening (customer) | Cr opening (supplier) | – |

**Invariants checked by `pos:integrity:check`** (and by tests):
1. `products.current_stock = Σ stock_movements.quantity` per product.
2. The latest movement's `qty_after` = `current_stock`.
3. `customers.balance = Σ(debit − credit)` per customer; the same for suppliers (`Σ(credit − debit)`).
4. `sales.paid_amount`, `due_amount`, `payment_status` and `returned_amount` agree with their children.
5. Σ allocations of a payment ≤ payment amount.
6. `sale_items.returned_quantity = Σ sale_return_items.quantity` (non-voided).
7. The walk-in customer balance = 0.
8. Audit hash chain intact.

---

## 6. Indexing & performance notes

- POS search: `UNIQUE(sku)`, `UNIQUE(barcode)`, and `FULLTEXT(name, model)` with a prefix `LIKE 'term%'` fallback for short terms. Results are limited to 20 and to active products.
- Reports aggregate on `(sale_date)`, `(product_id, sale_id)` and `(expense_date)`, with covering indexes added where EXPLAIN shows a need (Phase 10).
- Expected volume: about 200 invoices per day, roughly 75k per year and 250k lines per year, which is comfortably small for InnoDB. No partitioning is needed.

---

## 7. State machines and enumerations

### 7.1 Sale
`completed → voided` (only within the void window, with no returns).

### 7.2 Purchase
`draft → received → voided`; `draft → (deleted)`. A draft has no financial effect and can be deleted.

### 7.3 Payment status (derived)
`unpaid` (paid = 0), `partial` (0 < paid < net due), `paid` (paid ≥ net due).

### 7.4 Service job state machine

```mermaid
stateDiagram-v2
  [*] --> Received
  Received --> InspectionPending
  Received --> Diagnosing
  InspectionPending --> Diagnosing
  Diagnosing --> WaitingForApproval
  Diagnosing --> InProgress : no approval needed (e.g. refill)
  WaitingForApproval --> Approved
  WaitingForApproval --> Cancelled : customer declined
  Approved --> InProgress
  InProgress --> WaitingForParts
  WaitingForParts --> InProgress
  InProgress --> Completed
  Completed --> InProgress : reopen (reason required)
  Completed --> Delivered : invoice + payment
  Received --> Cancelled
  InspectionPending --> Cancelled
  Diagnosing --> Cancelled
  Approved --> Cancelled
  InProgress --> Cancelled
  WaitingForParts --> Cancelled
  Delivered --> [*]
  Cancelled --> [*]
```

Stored values: `received, inspection_pending, diagnosing, waiting_for_approval, approved, in_progress, waiting_for_parts, completed, delivered, cancelled`.

Rules: parts can be added only in `diagnosing … completed`. `Delivered` and `Cancelled` are terminal and lock the job. A cancelled job with charges can still be invoiced for an inspection fee: the user enters the fee before cancelling, and the system creates a minimal invoice. That variant is "Should" priority.

---

## 8. Seed data
- Roles and permissions (RBAC.md); an admin user created by an install command (`php artisan pos:install`) that prompts for the password. **No default password is shipped.**
- Walk-in customer (code `WALKIN`).
- Payment methods, units, categories, service types, expense categories (SRS lists).
- Document sequences with default prefixes.
- Settings defaults (shop name "Mr Printer & Tuner", PKR, Rs., tax off, negative stock off, 80 mm receipts, backup 21:00, retention 7/4/6).

---

## 9. Phase 3 implementation notes (catalogue, suppliers, inventory)

Tables added in Phase 3 (migrations `2026_10_07_2000xx_*`): `categories`, `brands`, `units`, `suppliers`, `products`, `stock_adjustments`, `stock_adjustment_items`, `stock_movements`. Differences from §2 above:

| Item | As built | Why |
|---|---|---|
| `categories`, `brands`, `units` | **No soft deletes and no `slug`.** An unused row is deleted for real; a used one cannot be deleted (foreign key `RESTRICT`, plus a friendly message), only hidden with `is_active` | They hold no history of their own, and soft-deleted names would block re-using the name under the unique index |
| `categories.unique(parent_id, name)` | Enforced for sub-categories by the index and for main categories by validation (MariaDB treats `NULL` parents as distinct) | |
| `document_sequences` | New type `product` (prefix `P-`) for automatically suggested SKUs | SRS PRD-01 |
| `suppliers.opening_balance` / `balance` | Columns exist, default 0, **not editable yet** | The supplier ledger that must back them arrives in Phase 4. A balance without a ledger entry would break the invariant in §5 |
| `products` check constraints | `type IN ('stock','service')`, prices `>= 0`, costs `>= 0`, `type='stock' OR current_stock = 0` | The database refuses nonsense even if the application has a bug |
| `products` cost/stock columns | `last_purchase_cost`, `average_cost`, `current_stock` are **not mass-assignable**; only `InventoryService` writes them | One write path (ARCHITECTURE §4.2) |
| `stock_movements` | Append-only (`BEFORE UPDATE/DELETE` triggers) and `CHECK (qty_after = qty_before + quantity)`, `quantity <> 0`, `movement_type IN (...)` | Same protection as `audit_logs` |
| Movement types | `opening, purchase, purchase_return, purchase_void, sale, sale_return, sale_void, service_consumption, service_return, adjustment_in, adjustment_out` (all 11 are accepted by the ledger now; Phases 4 to 8 start using them) | |
| Unique SKU / barcode | Unique across soft-deleted rows too, so a deleted product's SKU stays reserved. A barcode may not equal another product's SKU and vice versa; both are plain printable ASCII | A scanner types the code and presses Enter: ambiguity or accents would pick the wrong product or print unreadable labels |

### Costing rules as implemented (`CostingService`, 4-decimal moving weighted average)
| Event | Effect on average cost |
|---|---|
| Stock in at a cost (purchase, opening stock, found/added, restocked return, voided sale) | `avg' = (max(S,0)·avg + q·c) / (max(S,0)+q)`. A negative balance never drags the average down |
| Stock out at original cost (purchase return, void of a purchase, **void of an adjustment that added stock**) | `avg' = (S·avg − q·c) / (S − q)`, never below 0; unchanged if nothing remains |
| Stock out at the current average (sale, repair part, adjustment out) | unchanged; the cost is snapshotted on the movement |

### Integrity checks that exist now (`pos:integrity:check`, twice a day)
1. `products.current_stock = Σ stock_movements.quantity` for every stock item.
2. The latest movement's `qty_after` equals `current_stock`.

A mismatch is never fixed automatically: it is written to the audit log, shown as a red warning on the dashboard for people who can adjust stock, and the command exits with code 1.

---

## 10. Phase 4 implementation notes (purchases & supplier accounts)

Tables added in Phase 4 (migrations `2026_10_07_3000xx_*`): `payment_methods`, `supplier_ledger_entries`, `purchases`, `purchase_items`, `supplier_payments`, `purchase_payments`.

| Item | As built | Why |
|---|---|---|
| `payment_methods` | `name` and `code` unique; `kind` in `cash, bank, card, wallet, customer_credit, other`; `requires_reference`; `is_active`; `is_system`; `sort_order`. Seeded: Cash, Bank Transfer, Card, Easypaisa, JazzCash, Cheque, Customer Credit. Cash and Customer Credit are **system** methods (cannot be switched off or deleted). A used method can only be switched off | Owner-editable list (Settings → Payment methods); Phase 5 reuses it for sales |
| `supplier_ledger_entries` | **Append-only** (`BEFORE UPDATE/DELETE` triggers) with `CHECK (debit >= 0 AND credit >= 0 AND ((debit > 0) <> (credit > 0)))`, i.e. exactly one side per row. Types: `opening_balance, purchase, purchase_void, payment, payment_void, purchase_return, refund_received, balance_adjustment` | Same protection as `audit_logs` and `stock_movements` |
| Sign convention | `balance = SUM(credit) - SUM(debit)`. **Positive = the shop owes the supplier.** Negative = the supplier holds an advance of ours | One rule everywhere; shown in words on every screen |
| `suppliers.balance` / `opening_balance` | Cached; written **only** by `SupplierAccountService` (`balance`) and `AdjustSupplierBalance` (`opening_balance`). Not mass-assignable | One write path |
| `purchases.purchase_no` | Nullable and unique; assigned **when received** from the `purchase` sequence inside the same transaction, so a rolled-back receipt gives the number back. A `CHECK` forbids a received or cancelled purchase without a number | Gap-free numbers; drafts have none |
| Supplier invoice uniqueness | Generated column `active_invoice_key = CASE WHEN status='voided' THEN NULL ELSE supplier_invoice_no END` with `UNIQUE (supplier_id, active_invoice_key)`. The database itself refuses a duplicate even if two terminals race; a cancelled purchase frees its number | SRS PUR-02 and the owner-default decision in DESIGN_REVIEW §7 |
| `purchases` money columns | `subtotal, discount_amount, tax_amount, additional_cost, grand_total` (the document) and the caches `paid_amount, returned_amount, due_amount, payment_status`, all `DECIMAL(15,2)`, `CHECK >= 0`. `due = grand_total - returned - paid` (never below 0) | Caches are recomputed from `purchase_payments` and checked nightly |
| `purchase_items` | `quantity DECIMAL(12,3) > 0`, `unit_cost DECIMAL(15,4)`, `discount_amount`/`tax_amount` (totals for the line), `line_total`, `landed_unit_cost` (set when received), `returned_quantity <= quantity` (filled by Phase 8) | |
| `supplier_payments` | `type` is `payment` or `refund`, `status` is `posted` or `voided`, `amount > 0`. Phase 4 only creates `payment` rows; `refund` (money back from a supplier) arrives with purchase returns in Phase 8 | A refund needs the return it belongs to |
| `purchase_payments` | Which bill(s) a payment settles; `UNIQUE (supplier_payment_id, purchase_id)`, `amount > 0`. The part of a payment that is not allocated (or whose bill was cancelled) is an **advance** | SRS PUR-06 |

### Posting rules as implemented
| Operation | Stock | Supplier ledger | Other |
|---|---|---|---|
| Receive purchase (`ReceivePurchase`) | `purchase` +qty per line at the **landed** unit cost, so the average cost moves | Cr `grand_total` (`purchase`); none for a zero-value bill | Optional payment made at the same moment: a normal supplier payment settling this bill. All in one transaction |
| Cancel a received purchase (`VoidPurchase`) | `purchase_void` −qty at the landed cost, unwinding the average cost. **Refused** if the stock is no longer there, or goods were returned | Dr `grand_total` (`purchase_void`) | Payments already made **stay recorded**; they simply become an advance. (The original design cancelled them too; see DESIGN_REVIEW P4-04) |
| Supplier payment (`PostSupplierPayment`) | – | Dr amount (`payment`) | Allocation `auto` (oldest bills first), `manual` (chosen bills) or `advance` (none) |
| Cancel a payment (`VoidSupplierPayment`) | – | Cr amount (`payment_void`) | The bills it settled are recomputed and open again |
| Apply an advance (`AllocateSupplierAdvance`) | – | **None** (no money moves) | Only the bills' paid / due change |
| Opening balance / correction (`AdjustSupplierBalance`) | – | `opening_balance` (once per supplier) or `balance_adjustment`: Cr if "we owe", Dr if "they owe" | Administrator only, password re-entered, reason required |

**Landed cost.** The invoice discount, invoice tax (when `tax.purchase_tax_in_cost` is on) and the extra costs (freight, loading) form one pool that is shared over the lines in proportion to each line's value (by quantity if the whole bill has no value); the last line takes the remainder so nothing is lost. Each line's landed unit cost (4 decimals) is what the stock ledger and the average cost use. Unit costs are stored to 4 decimals, so the stock value of a bill can differ from the bill by at most half of 0.0001 per unit bought.

**Lock order** (every operation, so two terminals can never deadlock): supplier row → purchases (by id) → products (by id) → number sequences. Every write that changes a supplier's account, a bill's payments or a bill's status first locks that supplier, which serialises them.

### Integrity checks added (`pos:integrity:check`, twice a day)
3. `suppliers.balance` equals `SUM(credit) - SUM(debit)` of the supplier's ledger.
4. For every received or cancelled purchase: `paid_amount`, `due_amount` and `payment_status` equal what its posted payments imply (a cancelled purchase must show 0 and 0).
5. A posted payment is never allocated to bills for more than its amount (allocations to cancelled bills do not count).
6. For every received purchase the quantity added to stock by its `purchase` movements equals the quantity on its lines.

As before, nothing is "fixed" automatically: a mismatch is audited, shown on the dashboard and makes the command exit with code 1.

---

## 11. Phase 5 implementation notes (POS, sales & invoices)

Tables added in Phase 5 (migrations `2026_10_07_4000xx_*`): `customers`, `sales`, `sale_items`, `customer_payments`, `sale_payments`, `held_sales`.

| Item | As built | Why |
|---|---|---|
| `customers` | Created now with its full shape (§2.4), because every sale points at a customer. The migration inserts the **one walk-in customer** (`WALK-IN`). A generated column + `UNIQUE` index makes a second walk-in impossible, and `CHECK (is_walk_in = 0 OR (allow_credit = 0 AND balance = 0 AND opening_balance = 0))` enforces BR-11 in the database. Phase 6 added the customer ledger and the `sale_id` link on receipts (§12) and did not change this table | Walk-in sales need a customer row; the rule that the walk-in customer can never owe anything is too important to leave to application code |
| `sales` | As §2.6, plus `tax_inclusive` (snapshot of whether the entered prices contained tax) and constraints: status, payment status, tier, non-negative amounts, and "voided exactly when `voided_at` is set". `invoice_no` and `idempotency_key` are unique | The snapshot keeps an old invoice readable when the tax setting changes later |
| `sale_items` | As §2.6; names, SKU, list price, charged price, the line's share of the invoice discount, tax, net, total, **unit cost at the moment of sale**, serial number, warranty date, `returned_quantity <= quantity` (all `CHECK`ed) | An invoice never changes when a product is renamed or re-priced; profit uses the cost snapshot (BR-07) |
| Payments | Every payment taken at the till is one `customer_payments` row (`receipt`, numbered `RCP-`) allocated by one `sale_payments` row to exactly one sale. **Change is not a payment**: only what the sale needed is recorded; `sales.amount_tendered` and `change_amount` keep the handover for the receipt | Phase 6 customer payments, voids and statements then work on the same two tables |
| `held_sales` | `user_id`, optional `customer_id`, `label`, the cart as JSON. Not a document: no number, no stock reserved, deleted when resumed or discarded; a cashier sees only their own (at most 30) | POS-15 |

### Posting rules as implemented
| Operation | Stock | Payments | Customer ledger | Other |
|---|---|---|---|---|
| Complete sale (`CompleteSale`) | `sale` −qty per stock line at the **current average cost** (snapshotted on the line); services have no movement | one receipt + allocation per payment line | none: the walk-in customer pays in full and holds no balance | invoice number from the `sale` sequence, receipt numbers from `customer_payment`; audited; everything in ONE transaction |
| Cancel a sale (`VoidSale`) | `sale_void` +qty at the cost the goods left at (the original `unit_cost`) | the sale's receipts are cancelled (money given back); paid / due on the invoice become 0 | none | refused after the void window (an administrator may go beyond it), when goods were returned, or twice |
| Hold / resume / discard a cart | none | none | none | no document, not audited |

**Retries and double clicks.** Every checkout carries a key (a UUID made by the browser). The same key never makes a second sale: a named database lock serialises arrivals, and the `UNIQUE` index on `sales.idempotency_key` is a second, independent guard (the sale row is inserted before any stock is touched, so a duplicate is detected before it can be told "not enough stock"). A key belongs to the cashier who used it.

**Lock order** (every operation, so two tills can never wait on each other in a circle): the checkout-key lock → products (by id) → number sequences (invoice, then receipts) → the audit trail. A void takes the sale first, then its products.

**Everything is recalculated on the server.** The screen sends only products, quantities, optional typed prices and discounts, payment lines, and the total it displayed. The server prices every line from the product (retail, or the wholesale list when chosen), applies the discounts and tax with `SaleCalculator`, and **refuses the sale if its total differs from the screen's** (a price or setting changed meanwhile). The browser's own arithmetic (`resources/js/pos-calc.js`, exact integers) is proven identical to the PHP calculator on thousands of random sales.

### Money rules (SaleCalculator)
| Step | Rule |
|---|---|
| Line | `gross = qty × price` rounded to the paisa; line discount as an amount or a percentage of the gross (a percentage wins when both are typed); never more than the gross |
| Invoice discount | an amount, or a percentage of the lines after their own discounts; shared over the lines in proportion to their value by the **largest remainder method**, so the shares add up to the discount to the paisa and no line gets more than it is worth |
| Tax | exclusive: `tax = net × rate`; inclusive: `net = paid ÷ (1 + rate)`, `tax = paid − net`. Rate = the product's own rate, else the default; zero when tax is switched off (the default) |
| Round-off | optional, to the nearest Rs. 1 / 5 / 10 after tax, half up; the difference is stored as `round_off` |

### What needs a manager (approval at the till)
| Situation | Needs | Approver must hold |
|---|---|---|
| a typed price differs from the price list; the wholesale list used by someone without `sales.change_price` | approval | `sales.change_price` |
| any discount by someone without `sales.discount`; a discount above `sales.max_discount_percent` (default 5 %) by someone without `sales.discount_unlimited` | approval | `sales.discount_unlimited` |
| a line sold below the product's lowest allowed price (after all discounts) | approval | `sales.below_min_price` (Administrator only by default) |

A manager types their own username and password on the cashier's screen. The answer is a token tied to that cashier, valid for two minutes, used once, and only after the sale is safely saved; five wrong attempts lock the dialog for five minutes; every attempt is audited and passwords are never stored or logged. The approver must hold the permission for **every** reason, so a manager cannot approve a below-minimum price.

### Printing
- The original receipt / invoice opens through a **signed link that works for ten minutes** (the POS opens it right after the sale). Any later print is a **reprint** (`sales.reprint`): marked `DUPLICATE`, audited, and a cashier may reprint only their own invoices. A cancelled invoice prints `VOID`. The A4 invoice can also be downloaded as a **PDF** (built on the server with dompdf, nothing fetched from the internet, always marked DUPLICATE, audited).
- Thermal layout follows Settings → Printing (paper width 58/72/80 mm, margins, font size, copies, auto-print) and carries the invoice number as a Code 128 barcode.

### Integrity checks added (`pos:integrity:check`, twice a day)
7. Every invoice: lines add up to the header (items, discounts, tax, round-off, grand total).
8. Every invoice: `paid_amount`, `due_amount` and `payment_status` equal what its posted receipts imply (a cancelled invoice shows 0 and 0).
9. A posted receipt is never allocated for more than its amount.
10. Units on each invoice (stock items) equal what the stock ledger took out; a cancelled invoice's goods all went back; the walk-in customer's balance is 0.

## 12. Phase 6 implementation notes (customers & receivables)

Migrations `2026_10_08_500000_create_customer_ledger_table` and `2026_10_08_500100_add_sale_id_to_customer_payments`. Both were applied to the real `mrpos` database after a backup.

| Item | As built | Why |
|---|---|---|
| `customer_ledger_entries` | Append-only, like the supplier ledger: `customer_id`, `entry_date`, `entry_type`, `debit`, `credit`, `source_type` / `source_id`, `description`, `created_by`, `created_at(6)`. `CHECK` that exactly one side is greater than zero and that the type is one of `opening_balance, sale, sale_void, service_invoice, service_void, payment, payment_void, refund, refund_void, sale_return, balance_adjustment`. Triggers `customer_ledger_no_update` / `customer_ledger_no_delete` refuse any edit or delete, even by hand in the database | The customer's account must be provable: every figure on a statement is a ledger line that can never be changed, only reversed by another line |
| `customers.balance` | A cache of `Σ debit − Σ credit`: **positive = the customer owes the shop, negative = the shop holds the customer's money**. Written only by `CustomerAccountService::postEntry()` in the same transaction as the ledger line; `pos:integrity:check` recomputes it | Fast lists and the till; the ledger stays the truth |
| `customer_payments.sale_id` | Nullable foreign key to `sales`. A receipt taken **at the till** belongs to its sale (`sale_id` set); a payment **collected later** from a customer has `sale_id = NULL` | A cancelled sale must cancel only its own till receipts, never money the customer paid on account |
| Receipt numbers | Till receipts and collected payments share the `RCP-` sequence, so a customer's collected payments are not consecutive on their own | One gap-free sequence for every receipt the shop ever issues (BR-09) |

### Sign conventions and "advance"
- Customer balance = debits − credits. A sale is a debit; a payment is a credit; cancelling either writes the opposite line.
- **Advance (credit the customer holds)** = the unallocated part of their posted receipts. Allocations to cancelled invoices do not count, so cancelling an invoice that was paid from the account gives the money back to the customer as credit again.
- **"Customer credit" as a payment method at the till** spends that advance: it allocates the customer's existing receipts to the new invoice, oldest first, and writes **no ledger line**, because the customer's money is already on their account. It is refused for the walk-in customer and for more than the advance.

### Posting rules as implemented
| Operation | Customer ledger | Receipts / allocations | Other |
|---|---|---|---|
| Sale to a named customer (`CompleteSale`) | `sale` debit for the whole invoice; `payment` credit for each payment taken now | one receipt per payment line, `sale_id` set; credit used is allocated from earlier receipts | `paid`, `due` and status of the invoice follow its allocations; audited with `customer`, `on_account`, `customer_credit_used` |
| Collect a payment (`PostCustomerPayment`) | `payment` credit for the whole amount | one receipt (`sale_id` NULL) allocated to open invoices: oldest first, or the invoices chosen, or none (kept as advance); the excess is advance | oldest-first uses the invoice **date**, not the order typed |
| Cancel a collected payment (`VoidCustomerPayment`) | `payment_void` debit | receipt marked cancelled; its invoices are open again | reason required; password; audited |
| Cancel a sale (`VoidSale`) | `sale_void` credit for the invoice; `payment_void` debit for the till receipts of that sale | only receipts with `sale_id` = this sale are cancelled; money collected later stays as the customer's credit | locks the customer first |
| Opening balance / correction (`AdjustCustomerBalance`) | `opening_balance` once per customer; later `balance_adjustment` (either direction) | none | Administrator only (`customers.adjust_balance`), password, reason |
| Apply an advance (`AllocateCustomerAdvance`) | none (no money moves) | allocates free receipt money to open invoices | |

### Lock order and the "first read" rule
Customer flows lock in this order: **idempotency key → customer → sale(s) / payment → products → number sequences → audit (last)**. Supplier flows are unchanged (supplier → purchases → products → sequences → audit).

MariaDB's default isolation (REPEATABLE READ) fixes a transaction's *snapshot* at its first ordinary read. A locking read (`SELECT … FOR UPDATE`) sees the latest data, but an ordinary read afterwards sees the snapshot. If any ordinary read happens **before** the customer lock, the transaction wakes up from waiting for the lock with a stale picture of the customer's invoices and credit. Rule: **the customer lock must be the first read of the transaction.** `CompleteSale::customer()` and `VoidSale` follow it (a concurrency test once caught the credit being spent twice because the walk-in lookup came first; see DESIGN_REVIEW §9).

### Statements and the aging report
- **Statement** (`CustomerAccountService::statement`): optional date range; "brought forward" = the balance before the first day; each ledger line with a running balance; closing balance. Printable in the browser and as a PDF (dompdf, server-built, audited). It needs `customers.statement` **and** `customer_payments.view`.
- **Aging** (`ReceivablesService`): unpaid invoices of named customers by invoice date, in bands Today / 1–30 / 31–60 / 61–90 / over 90 days. What a customer owes that is not on an invoice (an opening balance or correction), minus credit they hold, is shown as **Other**, so every row adds up to the customer's balance.

### Who may see money
`customers.view` shows names, phones and codes. Balances, credit limits, advances, payments and statements need `customer_payments.view` (and `customers.statement`, `reports.customers` for those screens). The till's customer search returns balances only to people who may see them; a Technician sees customers without any money.

### Integrity checks added (`pos:integrity:check`, twice a day)
11. Every customer's `balance` equals the sum of their ledger lines.
12. Every invoice of a named customer is on the account exactly once (and reversed exactly once if cancelled; never reversed while completed).
13. Every receipt from a named customer is on the account exactly once (and reversed exactly once if cancelled).

## 13. Phase 7 implementation notes (service, repair and refill jobs)

Migration `2026_10_09_600000_create_service_tables` (additive: no existing table lost data; applied to the real `mrpos` database after a backup).

| Item | As built | Why |
|---|---|---|
| `service_types` | name (unique), optional `default_charge`, active flag, order. The 11 types of SRV-01 are created by the migration and by `ServiceTypeSeeder` (safe to run again) | A type that has been used can be switched off, not deleted |
| `service_jobs` | As §2.7, plus `contact_name` (the walk-in customer has no name of its own), `approved_amount` and `approved_by`, `delivered_by`, `cancelled_by`, `tax_inclusive` (snapshot of the setting when the job was received). `CHECK`s: status list, priority / device / payment-status / approval-method lists, non-negative amounts, and **"delivered exactly when it has an invoice number, delivery time and invoice time; cancelled exactly when it has a cancel time"** (no half-finished states). `job_no` and `invoice_no` are unique | A delivered or cancelled job is locked by the database too, not only by the program |
| `service_job_items` | `part` lines carry a product, `charge` lines do not (`CHECK`); quantity, billed price, **unit cost snapshot** (the average cost when the part was taken), line discount, tax rate, `line_total` (quantity × price − discount); status `active` / `removed` with who, when, disposition (`returned_to_stock` / `written_off`) and reason. Lines are never deleted | The cost stays on the line for the profit figures; a removed line is history |
| `service_status_history` | Append-only (triggers `service_history_no_update` / `_no_delete`): from, to, note, who, when (microseconds) | SRV-03: every move is traceable and cannot be rewritten |
| `service_job_payments` | Allocation of a receipt to a job (`UNIQUE(customer_payment_id, service_job_id)`, amount > 0): advances while the job is open and the payment at delivery | A job is paid through the same numbered receipts as an invoice |
| `customer_payments.refund_of_id` | A refund (money handed back) says which receipt it returns | The customer's credit stays exact: free credit = receipt − what invoices and jobs use − what was refunded |

### Posting rules as implemented
| Operation (action) | Stock | Customer ledger | Receipts / allocations | Other |
|---|---|---|---|---|
| Receive a device (`ReceiveServiceJob`) | none | none | none (an advance is a separate step) | job number `JOB-`, history line "Received", the type's suggested charge as the first line |
| Add a part (`AddServicePart`) | `service_consumption` −qty at the **current average cost** (cost written on the line) | none | none | job totals recomputed; only from "diagnosing" to "completed" |
| Take a part off (`RemoveServiceLine`) | `service_return` +qty at the cost it left at, or **no movement** when written off | none | none | write-off needs a reason; the cost stays on the line |
| Charge, estimate, discount (`AddServiceCharge`, `UpdateServicePricing`) | none | none | none | totals recomputed with `SaleCalculator` (tax per settings, no round-off) |
| Advance (`TakeServiceAdvance`) | none | `payment` credit (named customers) | one receipt (`RCP-`) + allocation to the job | never more than the job is worth; the walk-in customer has no ledger |
| Deliver and invoice (`DeliverServiceJob`) | none | `service_invoice` debit for the job total, then `payment` credits for what is paid now | one receipt + allocation per payment line; "Customer credit" allocates earlier credit (no ledger line) | invoice number `SRV-`; unpaid stays on the account (named customers only, within the limit); extra advance is returned (walk-in) or stays as credit (named); the job is locked |
| Cancel (`CancelServiceJob`) | `service_return` for each part (or none if written off) | none; if the advance is handed back: `refund` debit | allocations stop counting, so the advance becomes the customer's credit; or a `refund` payment is written | reason required; only from the states the state machine allows (a completed job is reopened first) |
| Collect a payment / apply an advance / cancel a payment (Phase 6 actions) | none | as in §12 | now settle **invoices and delivered jobs together**, oldest first; keys `12` (invoice) and `j7` (job) | the aging report, statement and customer page list both |

### Lock order and the "first read" rule
Job flows: **idempotency / customer (named) → job → job lines → products (by id) → number sequences → audit last.** Delivery, advance and cancel read who the customer is *before* the transaction starts, so the customer lock is the first read of the transaction (DATABASE §12). Parts and status changes touch no account, so they lock only the job and the products. Every action re-reads the job `FOR UPDATE` and judges the **stored** status, not what the screen showed.

### The state machine
`received → inspection_pending → diagnosing → waiting_for_approval → approved → in_progress ⇄ waiting_for_parts → completed → delivered`, and `cancelled` from any state before "completed". Enforced by `ServiceJob::TRANSITIONS`; a test tries **every pair of statuses** against it. `approved` needs how the customer approved (in person / phone / WhatsApp) and stores the approved estimate; reopening a completed job needs a reason.

### Who sees which job
`service_jobs.view_all` (cashier, manager, administrator) sees every job; `service_jobs.view_assigned` only (technician) sees and may work on only the jobs assigned to them (`ServiceJobAccess`). Cost (`unit_cost`, parts cost) is shown only with `products.view_cost`; paid / due and the payments only with `customer_payments.view`.

### Integrity checks added (`pos:integrity:check`, twice a day)
14. Every job's charges, parts, parts cost, tax and total equal what its lines (that stand) come to.
15. Every job's paid / due / status equal what its posted receipts imply (a cancelled job shows 0 and 0).
16. The status a job shows is the last line of its history.
17. For every job and product, what left the stock (consumption minus returns) equals the part lines that stand plus those written off.
18. A delivered job of a named customer is on the account exactly once (and nothing else is).
19. A receipt is never used for more than its amount, counting invoices that stand, jobs that stand and refunds (replaces the check of §11 item 9).
20. Every refund handed to a named customer is on the account exactly once.