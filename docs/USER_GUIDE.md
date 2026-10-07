# User Guide: Mr Printer & Tuner POS

> **Status: growing with each phase.** Sections 1, 5, 6 (suppliers) and 10 are written (Phases 2 and 3). The others are filled in when their module is built; the phase is shown in brackets. The guide is written for shop staff in plain language.

## 1. Getting started

**Opening the system.** On any shop computer or tablet, open Chrome or Edge and go to the address your administrator gave you (for example `http://192.168.1.10`). You do not need the internet.

**Logging in.** Type your username and password. If you type the password wrong five times, the system makes you wait a minute before trying again. This protects your account.

**First login.** When an administrator creates your account or resets your password, you are asked to choose a new password straight away. Pick one only you know (at least 8 characters). You cannot use the system until you have done this.

**Changing your password later.** Click your name at the top right, then *Change my password*. Other computers where you were logged in are signed out.

**Logging out.** Click your name, then *Log out*. Always log out when you leave the counter.

**What you can see.** The menu on the left only shows what your role allows. Cashiers and technicians never see product cost or profit. If you need something you cannot see, ask an administrator or manager.

**Sensitive actions ask for your password again.** Downloading a backup, changing roles and resetting someone's password all ask you to type your own password once more. After you do, you are not asked again for 10 minutes.

## 2. Selling at the counter (POS) [Phase 5]
- Scanning or searching products, quantities, discounts and manager approval
- Choosing a customer, taking payment (cash, card, bank, Easypaisa/JazzCash, split), credit and partial payment
- Holding and resuming a sale, printing the receipt or A4 invoice, reprinting
- Voiding a sale (managers), keyboard shortcuts

## 3. Customers & collecting payments [Phase 6]
## 4. Repair, service & refill jobs [Phase 7]
## 5. Products & stock

### Finding a product
*Products.* Type in the search box: it looks in the name, SKU, barcode and model at once. Typing or scanning an exact SKU or barcode puts that product first. Use the filters for category, brand, stock level (in stock / low / out) and active or inactive.

### Adding a product
*Products → New product.* Choose **Stock item** for things you count (a toner) or **Service** for things you do not (repair labour). Fill in the name, category, unit and sale price. Everything else is optional.
- **SKU:** leave it empty and the system gives the next number (P-000001). If you type one, use letters, digits and ordinary symbols only (no accented letters), because it can be printed as a barcode.
- **Barcode:** click the box and scan the product, or type it. It must be different from every other product's barcode and SKU, otherwise the scanner could pick the wrong one.
- **Stock you have now / Cost of each:** if you already hold the product, enter how many you have counted and what each cost you. This is recorded as *Opening stock*. Stock cannot be typed in again later: it changes through purchases, sales and adjustments, so there is always a record of why.
- **Unit:** pieces, boxes, grams, millilitres and so on. Only units such as grams and ml accept decimals (for example 2.5 g of toner powder).
- **Lowest allowed price** stops a cashier selling below it. **Warn me when stock is at or below** overrides the shop-wide low-stock level for this product.

Managers see the **average cost** and **margin** on the product page. Cashiers and technicians never see cost or profit anywhere.

### Changing prices
Only people with the *change prices* permission can change a price. Everyone else sees the prices but cannot edit them. Every price change is recorded in the audit log with the old and the new price.

### Deactivate or delete?
- **Deactivate** hides the product from sales and pickers but keeps all its history in reports. Use this for products you no longer sell.
- **Delete** is only possible for a product that never had any stock activity (for example one entered by mistake), and asks for your password.

### Pictures
Upload a PNG, JPG or WebP up to 2 MB on the product form. The system re-saves it as a smaller JPEG and keeps it safe on the server; it is included in every backup.

### Categories, brands and units
*Categories & brands.* Categories go two levels deep (for example *Printer Parts › Drums*). Anything in use cannot be deleted; untick *Active* to hide it. The unit **Allows decimals** setting cannot be turned off once products in that unit have stock history.

### Importing your product list from Excel
*Products → Import.*
1. **Download the template** and fill in one product per row (only *name* is required). Common headers such as *price*, *cost* and *qty* are understood.
2. Save as **.xlsx** or **.csv** and upload it. You get a **checked preview**: how many rows are ready, which new categories and brands will be created, and every problem with its row number.
3. If there is any problem, **nothing is imported**. Fix the file and upload it again. When all rows are fine, press **Import**.
4. Stock quantities in the file are recorded as one *Opening stock* document at the cost you gave. The import only **adds** products: a SKU or barcode that already exists is reported as an error.

### Stock levels
*Inventory → Stock* shows what is on the shelf, with counts of low and out-of-stock items (click a count to see them) and, for managers, the stock value. The dashboard also shows the products that need re-ordering.

### The stock card
*Inventory → Stock card* (or **Stock card** on a product) lists every movement: what happened, how much, the stock before and after, who did it and which document it came from. Entries can never be edited or deleted. If a total ever disagrees with its history, the system checks this twice a day and shows a red warning on the dashboard.

### Adjusting stock
*Inventory → Adjust stock.* Use it for your opening stock, a stock count, or damaged, lost or found items.
1. Choose the **reason** and the date.
2. Search for each product (name, SKU, or scan the barcode) and choose **Add** or **Remove** and the quantity. The screen shows what the stock will become and warns you if you try to remove more than you have.
3. Press **Post adjustment**. All lines are saved together or not at all.

A mistaken adjustment is cancelled, not deleted: open it and use **Cancel this adjustment** (administrators, with a reason and your password). The opposite movements are written, so the history shows both.

### Barcode labels
*Barcode labels.* Search for products, enter how many labels each needs (or **Fill with stock on hand**), choose the label size and press **Print labels**. The label uses the product's barcode, or its SKU if it has none. Set the printer's paper to the size shown on the page and margins to "None".
## 6. Purchases & suppliers

### Suppliers
*Suppliers.* Add each supplier with name, company, phone, WhatsApp, email, address and NTN. A supplier gets a code (SUP-000001) automatically. You can deactivate a supplier; one that is used by a product cannot be deleted (administrators only). What you owe each supplier, supplier bills and payments arrive with *Purchases* in the next phase.

### Entering supplier bills and receiving stock [Phase 4]
### Paying suppliers [Phase 4]
### Returning goods to a supplier [Phase 4]
## 7. Returns [Phase 8]
## 8. Expenses [Phase 8]
## 9. Reports & dashboard [Phase 8]

## 10. Administration

### Users
*Administration → Users.* Click **New user**, fill in the name, a username (letters, numbers, dot, dash or underscore; it cannot be changed later), choose the role and type a temporary password. Tell the person that password; they must replace it at first login.

- **Disable** a user instead of deleting them (untick *Account is active*). They are signed out at once and cannot log in. Their history stays.
- **Reset password** (on the user's page): type a new temporary password. They are signed out everywhere and must choose their own.
- You cannot disable yourself or remove your own Admin role, and the system always keeps at least one active administrator.
- Only an administrator can create or change another administrator.

### Roles & permissions
*Administration → Roles & permissions.* A role is a bundle of permissions. The four built-in roles are:

| Role | Meant for |
|---|---|
| Admin | Everything. Cannot be changed |
| Manager | Day-to-day running: stock, purchases, reports, services, **cost and profit** |
| Cashier | The POS, customers, payments, repair intake. **No cost or profit** |
| Technician | Their own repair jobs and the parts used. **No cost or profit** |

Click a role to tick or untick permissions. Permissions marked *cost / profit* show purchase prices and profit: give them only to people you trust with those numbers. You can also create your own roles (for example "Supervisor"). Changes take effect the next time that person opens a page. Every change is recorded in the audit log.

### Settings
*Administration → Settings.*
- **Shop**: name, address, phone, WhatsApp, email, NTN and your logo (shown on the login page and on receipts).
- **Currency**: PKR and "Rs." by default.
- **Sales tax**: *off* because the shop is not registered. When it is, tick *Charge sales tax*, enter the rate and label (for example GST 18%). No technician is needed.
- **Sales**: the largest discount a cashier can give without a manager, how long a sale can be voided, store credit, rounding.
- **Inventory**: the default low-stock level, and whether selling below zero stock is allowed (off).
- **Printing**: printer name and model (for your reference), paper width (58 / 72 / 80 mm), margins, font size, number of copies, and whether receipts print automatically. Receipts print through the browser to the Windows default printer of each counter PC.
- **Receipt text**: extra header, footer message, and the terms printed on repair slips.
- **Security**: minimum password length, automatic logout time, and an optional list of allowed computers (the system will not let you lock yourself out).
- **Document numbers**: the prefix and number of digits for invoices, job cards, receipts and so on. Numbers are never reused.

### Backups
*Administration → Backups.* This is the most important screen in the system.

- **Backup Now** makes a copy of all your data and uploaded files. Selling continues while it runs. Do it before anything risky.
- A green message means backups are healthy. Red or amber messages say exactly what to fix. A red dot next to *Backups* in the menu means something needs attention today.
- **Second copy**: add a USB drive or another PC. Without one, a broken disk or a stolen PC loses everything. If the drive is unplugged, the copy waits and is sent automatically when it is back. Rotate two USB drives and keep one at home.
- **Test restore** on a backup proves it can really be restored. The system also does this by itself every week.
- **Download** a backup (asks for your password). **Protect** a backup (for example before year-end) so it is never deleted automatically.
- **Schedule, retention and encryption**: the daily time (default 21:00), how many daily, weekly and monthly copies to keep (7, 4 and 6), and optional AES-256 encryption. If you turn encryption on, **write the backup password down and keep it away from the shop**: without it the backups cannot be opened.
- If the PC is switched off at the scheduled time, the backup runs as soon as it is next on.

### Restoring a backup (administrators only)
Restoring replaces **all** current data with the data from the backup. It is done on the server PC, not from the web page, so it cannot happen by accident. The computer person opens a terminal and runs `php artisan pos:backup:restore --id=<number>`. It asks for an administrator login and a typed confirmation, checks the backup first, saves a safety copy of today's data, and puts everything back automatically if anything goes wrong. Everyone is logged out afterwards. Afterwards, re-enter any sales made after the backup from your paper receipts.

### Audit log
*Administration → Audit log.* A permanent record of important actions: logins (and failed attempts), user and role changes, settings changes, backups and restores. Entries cannot be edited or deleted. *Verify integrity* checks that nothing was tampered with. Filter by date, person, area or text.

### The dashboard "System checks" card (administrators)
Shows whether the computer has everything it needs: PHP features, the database version, matching clocks, writable folders and the backup tools. If something says FAIL, tell your computer person; it names the exact fix.

## 11. Daily / weekly checklists
- **Every morning:** look at the dashboard. If there is a red or amber backup message, open *Backups* and read it.
- **Every week:** swap the USB backup drive (take the newer one home). Confirm the second copy shows as *success*.
- **After any change to the computer or network:** run *Backup Now* and then *Test restore*.
