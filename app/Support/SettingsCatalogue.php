<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Every setting the application knows about: group, type, default, label and validation.
 * The settings UI and SettingsService are driven from this list, so adding a setting needs no new screen code.
 *
 * Types: string, text, int, decimal, bool, select, time, encrypted
 */
final class SettingsCatalogue
{
    /** @return array<string, array<string, mixed>> */
    public static function all(): array
    {
        return [
            // ---- Shop profile -------------------------------------------------
            'shop.name' => ['group' => 'shop', 'type' => 'string', 'default' => 'Mr Printer & Tuner', 'label' => 'Shop name', 'rules' => 'required|string|max:150'],
            'shop.address' => ['group' => 'shop', 'type' => 'text', 'default' => '', 'label' => 'Address', 'rules' => 'nullable|string|max:500'],
            'shop.phone' => ['group' => 'shop', 'type' => 'string', 'default' => '', 'label' => 'Phone', 'rules' => 'nullable|string|max:60'],
            'shop.whatsapp' => ['group' => 'shop', 'type' => 'string', 'default' => '', 'label' => 'WhatsApp', 'rules' => 'nullable|string|max:60'],
            'shop.email' => ['group' => 'shop', 'type' => 'string', 'default' => '', 'label' => 'Email', 'rules' => 'nullable|email|max:150'],
            'shop.logo_path' => ['group' => 'shop', 'type' => 'string', 'default' => '', 'label' => 'Logo file', 'rules' => 'nullable|string|max:255', 'hidden' => true],
            'shop.ntn' => ['group' => 'shop', 'type' => 'string', 'default' => '', 'label' => 'NTN / STRN', 'rules' => 'nullable|string|max:40', 'help' => 'Printed on invoices when filled in.'],

            // ---- Currency -----------------------------------------------------
            'currency.code' => ['group' => 'currency', 'type' => 'string', 'default' => 'PKR', 'label' => 'Currency code', 'rules' => 'required|alpha|size:3'],
            'currency.symbol' => ['group' => 'currency', 'type' => 'string', 'default' => 'Rs.', 'label' => 'Currency symbol', 'rules' => 'required|string|max:8'],
            'currency.decimals' => ['group' => 'currency', 'type' => 'select', 'default' => '2', 'label' => 'Decimals shown', 'options' => ['0' => '0', '2' => '2'], 'rules' => 'required|in:0,2'],

            // ---- Tax (OFF by default; fully configurable later without code changes) ----
            'tax.enabled' => ['group' => 'tax', 'type' => 'bool', 'default' => false, 'label' => 'Charge sales tax', 'help' => 'The shop is currently not registered for sales tax. Turn this on only when it is.', 'rules' => 'boolean'],
            'tax.label' => ['group' => 'tax', 'type' => 'string', 'default' => 'GST', 'label' => 'Tax label on invoices', 'rules' => 'required|string|max:20'],
            'tax.default_rate' => ['group' => 'tax', 'type' => 'decimal', 'default' => '0.00', 'label' => 'Default tax rate (%)', 'help' => 'Used when a product has no rate of its own.', 'rules' => 'required|numeric|min:0|max:100'],
            'tax.inclusive' => ['group' => 'tax', 'type' => 'bool', 'default' => false, 'label' => 'Prices already include tax', 'rules' => 'boolean'],
            'tax.purchase_tax_in_cost' => ['group' => 'tax', 'type' => 'bool', 'default' => true, 'label' => 'Add purchase tax to product cost', 'help' => 'Leave on unless you claim input tax.', 'rules' => 'boolean'],

            // ---- Sales --------------------------------------------------------
            'sales.max_discount_percent' => ['group' => 'sales', 'type' => 'decimal', 'default' => '5.00', 'label' => 'Maximum cashier discount (%)', 'help' => 'Higher discounts need manager approval.', 'rules' => 'required|numeric|min:0|max:100'],
            'sales.void_window_hours' => ['group' => 'sales', 'type' => 'int', 'default' => 24, 'label' => 'Sale void window (hours)', 'help' => 'After this, use a sales return instead.', 'rules' => 'required|integer|min:0|max:8760'],
            'sales.store_credit_enabled' => ['group' => 'sales', 'type' => 'bool', 'default' => true, 'label' => 'A sales return may leave store credit', 'help' => 'The customer keeps the value of returned goods as credit and can spend it on their next sale (as "Customer credit"). Walk-in customers always get the money back.', 'rules' => 'boolean'],
            'sales.round_to' => ['group' => 'sales', 'type' => 'select', 'default' => '0', 'label' => 'Round invoice total to', 'options' => ['0' => 'No rounding', '1' => 'Nearest Rs. 1', '5' => 'Nearest Rs. 5', '10' => 'Nearest Rs. 10'], 'rules' => 'required|in:0,1,5,10'],

            // ---- Inventory ----------------------------------------------------
            'inventory.low_stock_threshold' => ['group' => 'inventory', 'type' => 'decimal', 'default' => '5', 'label' => 'Default low-stock level', 'help' => 'Used for products without their own minimum.', 'rules' => 'required|numeric|min:0'],
            'inventory.allow_negative_stock' => ['group' => 'inventory', 'type' => 'bool', 'default' => false, 'label' => 'Allow selling below zero stock', 'help' => 'Off by default. When on, sales and service parts may drive stock negative.', 'rules' => 'boolean'],

            // ---- Printing (generic; works with any driver-installed printer) --------
            'printing.default_format' => ['group' => 'printing', 'type' => 'select', 'default' => 'thermal', 'label' => 'Default receipt format', 'options' => ['thermal' => 'Thermal receipt', 'a4' => 'A4 invoice'], 'rules' => 'required|in:thermal,a4'],
            'printing.printer_name' => ['group' => 'printing', 'type' => 'string', 'default' => '', 'label' => 'Printer name', 'help' => 'For your reference. The browser prints to the Windows default printer on each counter PC.', 'rules' => 'nullable|string|max:100'],
            'printing.printer_model' => ['group' => 'printing', 'type' => 'string', 'default' => '', 'label' => 'Printer model', 'rules' => 'nullable|string|max:100'],
            'printing.paper_width_mm' => ['group' => 'printing', 'type' => 'select', 'default' => '80', 'label' => 'Thermal paper width', 'options' => ['58' => '58 mm', '72' => '72 mm', '80' => '80 mm'], 'rules' => 'required|in:58,72,80'],
            'printing.margin_top_mm' => ['group' => 'printing', 'type' => 'decimal', 'default' => '2', 'label' => 'Margin top (mm)', 'rules' => 'required|numeric|min:0|max:20'],
            'printing.margin_right_mm' => ['group' => 'printing', 'type' => 'decimal', 'default' => '2', 'label' => 'Margin right (mm)', 'rules' => 'required|numeric|min:0|max:20'],
            'printing.margin_bottom_mm' => ['group' => 'printing', 'type' => 'decimal', 'default' => '4', 'label' => 'Margin bottom (mm)', 'rules' => 'required|numeric|min:0|max:30'],
            'printing.margin_left_mm' => ['group' => 'printing', 'type' => 'decimal', 'default' => '2', 'label' => 'Margin left (mm)', 'rules' => 'required|numeric|min:0|max:20'],
            'printing.font_size_pt' => ['group' => 'printing', 'type' => 'decimal', 'default' => '9', 'label' => 'Receipt font size (pt)', 'rules' => 'required|numeric|min:6|max:16'],
            'printing.copies' => ['group' => 'printing', 'type' => 'int', 'default' => 1, 'label' => 'Copies per receipt', 'rules' => 'required|integer|min:1|max:5'],
            'printing.auto_print_sale' => ['group' => 'printing', 'type' => 'bool', 'default' => true, 'label' => 'Print automatically after a sale', 'rules' => 'boolean'],
            'printing.auto_print_job_slip' => ['group' => 'printing', 'type' => 'bool', 'default' => true, 'label' => 'Print automatically when a service job is received', 'rules' => 'boolean'],
            'printing.auto_print_payment' => ['group' => 'printing', 'type' => 'bool', 'default' => false, 'label' => 'Print automatically after a payment is collected', 'rules' => 'boolean'],
            'printing.open_cash_drawer' => ['group' => 'printing', 'type' => 'bool', 'default' => false, 'label' => 'Printer opens the cash drawer', 'help' => 'Enable "open drawer" in the printer driver too. This is a reminder flag for the receipt layout.', 'rules' => 'boolean'],

            // ---- Receipt / invoice text ------------------------------------------
            'receipt.header' => ['group' => 'receipt', 'type' => 'text', 'default' => '', 'label' => 'Extra header text', 'rules' => 'nullable|string|max:300'],
            'receipt.footer' => ['group' => 'receipt', 'type' => 'text', 'default' => 'Thank you for shopping at Mr Printer & Tuner!', 'label' => 'Footer message', 'rules' => 'nullable|string|max:300'],
            'receipt.show_logo' => ['group' => 'receipt', 'type' => 'bool', 'default' => true, 'label' => 'Show logo', 'rules' => 'boolean'],
            'receipt.show_cashier' => ['group' => 'receipt', 'type' => 'bool', 'default' => true, 'label' => 'Show cashier name', 'rules' => 'boolean'],
            'receipt.service_terms' => ['group' => 'receipt', 'type' => 'text', 'default' => 'Devices not collected within 30 days of notification are not our responsibility. Please bring this slip when collecting.', 'label' => 'Service job terms', 'rules' => 'nullable|string|max:1000'],

            // ---- Security -----------------------------------------------------
            'security.password_min_length' => ['group' => 'security', 'type' => 'int', 'default' => 8, 'label' => 'Minimum password length', 'rules' => 'required|integer|min:6|max:64'],
            'security.session_idle_minutes' => ['group' => 'security', 'type' => 'int', 'default' => 480, 'label' => 'Log out after idle minutes', 'help' => 'Applies from the next login.', 'rules' => 'required|integer|min:5|max:1440'],
            'security.ip_allowlist' => ['group' => 'security', 'type' => 'text', 'default' => '', 'label' => 'Allowed IP addresses / ranges', 'help' => 'One per line or comma separated, e.g. 192.168.1.0/24. Leave empty to allow the whole network. Your own address must be included.', 'rules' => 'nullable|string|max:1000'],

            // ---- Backup (edited on the Backups page) ----------------------------------
            'backup.enabled' => ['group' => 'backup', 'type' => 'bool', 'default' => true, 'label' => 'Automatic backups', 'rules' => 'boolean'],
            'backup.daily_time' => ['group' => 'backup', 'type' => 'time', 'default' => '21:00', 'label' => 'Daily backup time', 'rules' => 'required|date_format:H:i'],
            'backup.weekly_day' => ['group' => 'backup', 'type' => 'select', 'default' => '0', 'label' => 'Weekly backup day', 'options' => ['0' => 'Sunday', '1' => 'Monday', '2' => 'Tuesday', '3' => 'Wednesday', '4' => 'Thursday', '5' => 'Friday', '6' => 'Saturday'], 'rules' => 'required|in:0,1,2,3,4,5,6'],
            'backup.retention_daily' => ['group' => 'backup', 'type' => 'int', 'default' => 7, 'label' => 'Keep daily backups', 'rules' => 'required|integer|min:1|max:90'],
            'backup.retention_weekly' => ['group' => 'backup', 'type' => 'int', 'default' => 4, 'label' => 'Keep weekly backups', 'rules' => 'required|integer|min:1|max:52'],
            'backup.retention_monthly' => ['group' => 'backup', 'type' => 'int', 'default' => 6, 'label' => 'Keep monthly backups', 'rules' => 'required|integer|min:1|max:60'],
            'backup.retention_manual' => ['group' => 'backup', 'type' => 'int', 'default' => 20, 'label' => 'Keep manual backups', 'rules' => 'required|integer|min:1|max:500'],
            'backup.retention_safety' => ['group' => 'backup', 'type' => 'int', 'default' => 5, 'label' => 'Keep safety backups', 'rules' => 'required|integer|min:1|max:50'],
            'backup.include_files' => ['group' => 'backup', 'type' => 'bool', 'default' => true, 'label' => 'Include uploaded files (images, receipts)', 'rules' => 'boolean'],
            'backup.alert_after_hours' => ['group' => 'backup', 'type' => 'int', 'default' => 26, 'label' => 'Warn if no good backup for (hours)', 'rules' => 'required|integer|min:1|max:720'],
            'backup.encryption_enabled' => ['group' => 'backup', 'type' => 'bool', 'default' => false, 'label' => 'Encrypt backups (AES-256)', 'rules' => 'boolean'],
            'backup.encryption_password' => ['group' => 'backup', 'type' => 'encrypted', 'default' => '', 'label' => 'Backup password', 'rules' => 'nullable|string|min:8|max:100'],
        ];
    }

    /** @return list<string> */
    public static function groups(): array
    {
        return array_values(array_unique(array_column(self::all(), 'group')));
    }

    /** @return array<string, array<string, mixed>> */
    public static function forGroup(string $group): array
    {
        return array_filter(self::all(), fn (array $s) => $s['group'] === $group);
    }

    public static function exists(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** @return array<string, mixed> */
    public static function definition(string $key): array
    {
        return self::all()[$key] ?? throw new \InvalidArgumentException("Unknown setting [{$key}]");
    }
}
