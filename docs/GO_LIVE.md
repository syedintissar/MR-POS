# Go-live checklist and data import

## Before the first real sale
1. **PC:** MariaDB installed as a Windows service (`deploy\windows\install-mariadb-service.ps1`, as Administrator), the backup scheduled task (`install-scheduler.ps1`), the firewall rule (`setup-firewall.ps1`), a fixed address for the PC. `php artisan pos:doctor` shows all checks OK.
2. **`.env`:** `APP_ENV=production`, `APP_DEBUG=false`, the real `APP_URL`. Run `composer install --no-dev --optimize-autoloader`, `npm run build`, `php artisan migrate --force`, `php artisan optimize`.
3. **Settings:** administrator account created (`php artisan pos:install`); shop name, address, phone, logo and receipt text; the printer tested (a test sale and a return receipt); tax and rounding confirmed; return period (7 days) and restocking fee (off) confirmed; payment methods reviewed.
4. **Roles:** check what Cashier and Technician can do (Roles & permissions). Cashiers have no cost, profit or expenses.
5. **Backups:** make one (*Backup Now*), set up a second copy (USB drive or other PC), run *Test restore*, print the **Recovery Kit** and keep it away from this PC.

## Bringing in your data (in this order, then run the integrity check)
1. **Products:** Products → Import (Excel or CSV; a template is offered). Include the opening stock quantity and cost: it creates the opening-stock entry. Check ten products by hand.
2. **Suppliers:** add them, then post each supplier's opening balance once (Administrator, with a reason).
3. **Customers:** import or add them; post each customer's opening balance once (Administrator).
4. **Open service jobs:** receive each device again as a new job so it has a slip and a history.
5. `php artisan pos:integrity:check` must say it passed. Make a backup.

## First week
Count the cash against the **Closing summary** every evening; check Reports → Profit weekly; look at the Backups page every morning (a red dot means a problem); never edit in the program folder while the shop is open.

## If something goes wrong
Do not delete anything, make a backup, and use the Recovery Kit.
