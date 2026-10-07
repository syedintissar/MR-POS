# User Guide: Mr Printer & Tuner POS

> **Status: growing with each phase.** Sections 1 and 10 are written (Phase 2). The others are filled in when their module is built; the phase is shown in brackets. The guide is written for shop staff in plain language.

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
## 5. Products & stock [Phase 3]
## 6. Purchases & suppliers [Phase 4]
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
