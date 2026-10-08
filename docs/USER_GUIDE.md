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

## 2. Selling at the counter (POS)

Open **Selling → Point of sale**. The search box already has the cursor.

### Making a sale
1. **Add products.** Scan the barcode (the scanner presses Enter for you), or type a name, SKU or model and press **Enter** when you see the right one, or click it. The same product again raises its quantity by one. The grid below the search box shows your fast-moving products, and the coloured buttons filter by category. Services (such as a toner refill) can be sold like any product and do not use stock.
2. **Check the cart.** Change a quantity with − / + (or the keys + and −), type a different price or a discount (rupees or %) on a line if you are allowed, type an invoice discount, and add a serial number where the product has a warranty. A red line means there is not enough stock. The big **Total** is what the customer pays.
3. **Pay.** Press **Pay (F9)**. Cash is filled in with the exact total: type the amount the customer hands over and the screen shows the **change to give**. To split a payment, **+ Add another payment method** (for example part cash, part card); bank, wallet and cheque payments ask for a reference number. Only cash can be handed over in excess. The walk-in customer must pay in full.
4. **Complete (F10).** The sale is saved in one step: the stock is taken out, the payments are recorded and the invoice gets its number (INV-000001 …). The receipt prints by itself (Settings → Printing), the screen shows the change to give, and the till is ready for **Next customer**. If the screen shows an error, nothing was charged: fix it and press Complete again. Pressing Complete twice, or the connection dropping, never makes two sales.

### When a manager must approve
A discount above your limit, a changed price, the wholesale list, or a price below the lowest allowed asks for a manager. The manager **types their own username and password** into the box that appears on your screen, and the same sale goes through (the approval works once, for two minutes). A manager cannot approve a price below the lowest allowed: that is for the administrator.

### Hold, resume, clear
**Hold (F8)** puts the customer's items aside under a name so you can serve someone else; **Held carts** lists yours and **Resume** brings one back (the screen warns if a price changed while it was waiting). A held cart reserves no stock. **Clear** empties the cart. If the page is closed or refreshed by accident, the cart comes back.

### Keys
F2 search · **F4 choose the customer** · F8 hold · F9 pay · F10 complete · Esc closes a box · + and − change the quantity of the line you are in.

### Sales history, reprints and cancelling
**Selling → Sales** lists invoices (cashiers see their own; managers see everyone's) with filters for date, cashier and payment method. Open an invoice to see its lines and payments (managers also see the cost and profit). **Print receipt (copy)**, **Print A4 invoice (copy)** and **Download PDF** are marked DUPLICATE and recorded. A manager can **cancel** an invoice made by mistake (password and reason): the goods go back into stock and the payments are cancelled, nothing is deleted. Managers can do this within 24 hours; an administrator at any time; never after goods were returned.
- Scanning or searching products, quantities, discounts and manager approval
- Choosing a customer, taking payment (cash, card, bank, Easypaisa/JazzCash, split), credit and partial payment
- Holding and resuming a sale, printing the receipt or A4 invoice, reprinting
- Voiding a sale (managers), keyboard shortcuts

## 3. Customers & collecting payments

### Customers
Open **Customers → Customers**. Search by name, phone, code or company. **Add customer** asks for a name (everything else is optional): phone, WhatsApp, address, notes. If the phone number already belongs to someone, the screen tells you who and asks you to tick "save anyway" (two people can share a shop number). A customer you stop dealing with is **deactivated**, not deleted: they keep their history but cannot buy. Only a customer with no invoices or payments can be deleted.

A **manager** decides how a customer may buy: **Retail or Wholesale prices**, whether they may buy **on credit**, and an optional **credit limit** (leave it empty for no limit). A cashier can add a customer but not give credit.

The customer's page shows what they owe, the unpaid invoices (oldest first), recent sales and payments, and the account history. **Sell to this customer** opens the till with them already chosen. Only the **Administrator** can enter an **opening balance** (money a customer already owed when you started using the system): once, with a reason and the password. A later correction is a separate "balance adjustment", always with a reason.

### Selling to a customer at the till
Press **F4** (or **Choose customer**) and type a name, phone number or code; click the customer. **Add a new customer** is in the same box. The customer's price list is used automatically. If you do not choose a customer, the sale is to the **walk-in customer**, who pays in full.

- **Credit.** On the payment screen type only what the customer pays now (or press **Put it all on account**): the rest is **left on account** and shown on the receipt screen. It only works for a customer marked "allow credit". If it would take them over their credit limit the till says so and a manager must approve it, like a discount.
- **Customer credit.** If a customer has paid more than they owed, the till shows "holds credit" and **Use their credit** pays the sale from it; nothing goes into the cash drawer.

### Collecting a payment
**Customers → Customer payments → Collect a payment** (or **Collect a payment** on a customer's page, **Collect payment** on an invoice). Choose the customer, type the amount and how they paid. By default the money settles their **oldest invoices first**; you can instead choose which invoices it pays, or keep it as an **advance**. If they pay more than they owe, the extra stays as their credit. After **Record payment** a receipt (RCP-…) opens to print.

If a payment was entered by mistake, a manager opens it and presses **Cancel this payment** (reason and password): the invoices become unpaid again and the account is corrected; nothing is deleted. Then enter the correct payment.

### Statements and who owes you
On a customer's page, **Print statement** (or **PDF**) shows every sale and payment in date order with a running balance, for any date range, and can be printed or saved as a PDF to give or send to the customer. **Customers → Who owes us** lists every customer with unpaid invoices by age (today, 1–30, 31–60, 61–90 and over 90 days) so you know whom to call first; tick "list every invoice" for the detail. The dashboard shows the total owed to you.

### Who can see what
Cashiers see balances and can collect money; only managers cancel a payment. Technicians can find a customer but never see any money.
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
*Suppliers.* Add each supplier with name, company, phone, WhatsApp, email, address and NTN. A supplier gets a code (SUP-000001) automatically. You can deactivate a supplier; one that has products, bills or payments cannot be deleted (administrators only). Click a supplier's name to open the **supplier account**: what you owe them (or the advance they hold of yours), the bills still to pay, recent purchases and payments, and the account statement.

### Entering supplier bills and receiving stock
1. **Purchases → + New purchase.** Choose the supplier, type the supplier's invoice number (optional) and the date.
2. **Add products:** type a name or SKU, or scan the barcode and press Enter. While you type, each result shows what you paid last (to this supplier, or in general), and the cost box is filled in for you. Enter the quantity and the cost of each product; a discount or tax for that line is optional.
3. Under **Extras on the invoice** enter the invoice discount, invoice tax and freight / loading, if any. **Landed each** shows what one unit really cost after its share of those extras; a red ▲ or green ▼ tells you how much dearer or cheaper it is than last time.
4. **Paid now?** is optional. Enter an amount and how you paid; whatever is not paid now stays on the supplier's account.
5. **Save draft** keeps the bill without any effect (no stock, no debt) so you can finish it later. **Save & receive stock** does it all at once: the stock goes up, the average cost is updated, the bill gets its number (PUR-000001 …) and the supplier is owed the total. It is all-or-nothing: if anything is wrong, nothing is saved and the draft stays a draft.

A received purchase cannot be edited. If it was entered wrongly, open it and use **Cancel this purchase** (Manager or Administrator; it asks for your password and a reason). The goods leave stock again and the supplier's account is corrected; nothing is deleted. It is refused if some of those goods have already been sold, because the stock is no longer there. **Money already paid on a cancelled bill is not lost**: it stays recorded as an advance with that supplier, which you can apply to the next bill.

The same supplier invoice number cannot be entered twice for one supplier (a cancelled purchase frees its number).

### Paying suppliers
**Supplier payments → + Pay a supplier** (or **Pay** on a bill or on the supplier's page). Choose the supplier, the amount, how you paid and, for bank transfers, wallets and cheques, the reference number. Then choose how the payment is applied:
- **Oldest bills first** (recommended): the screen shows which bills the money settles.
- **I will choose the bills:** type the amount for each bill, or press *full*.
- **Keep it as an advance:** the money is recorded but not applied to a bill yet.

Money paid beyond what is owed is kept as an advance. An advance can be applied later: open the payment and use **Apply the advance to open bills**. If a payment was recorded by mistake, open it and **Cancel this payment** (asks for your password and a reason): the bills it settled are open again.

### The supplier account and statement
Open a supplier to see the **balance** (amber = you owe them, blue = they hold an advance of yours), the bills still to pay, and the **account statement**: every purchase, payment, cancellation and adjustment with a running balance. Pick *From* and *To* dates to see a period (the figure carried forward from before is shown), and press **Print** for a statement you can hand to the supplier. The administrator can enter an **opening balance** (what you already owed when you started using the system, once per supplier) or a **correction**, always with a written reason; these cannot be edited afterwards, only offset by another entry.

### Payment methods
**Settings → Payment methods** lists Cash, Bank Transfer, Card, Easypaisa, JazzCash, Cheque and Customer Credit. The administrator can add methods (for example a particular bank), rename them, tick whether a reference number is required, change their order and switch them off. Cash and Customer Credit are needed by the system; a method that has been used can be switched off but not deleted.

### Returning goods to a supplier [Phase 8]
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
- **Shop**: name, address, phone, WhatsApp, email, NTN and your logo (shown in the menu, on the sign-in page and on receipts). Until you upload one, the program shows a built-in "MR" mark drawn to look like the shop logo; **upload your own logo file here and it replaces it everywhere**.
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
