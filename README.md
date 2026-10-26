# Trust Procurement System

A web-based platform for electronics recommendation, deposit-based procurement,
and delivery tracking — built with PHP, MySQL, and vanilla JS/CSS, matching the
project proposal's scope and file structure.

Tested end-to-end against PHP 8.5.12 and MariaDB 10.11: registration, product
requests, admin approval, deposit payment, admin verification, and order
status progression all confirmed working with no PHP errors or warnings.

## Requirements

- PHP 8.5.12 or later, with the `pdo_mysql` extension
- MySQL 5.7+ or MariaDB 10.4+
- A local server stack such as XAMPP, or PHP's built-in server for quick testing

## Setup

1. **Place the project** in your server's web root.
   - XAMPP: copy the `trust-procurement-system` folder into `htdocs/`.
   - The app detects its own base URL automatically from its location on
     disk, so it works whether it's installed at the domain root or in a
     subfolder — no config changes needed.

2. **Create the database.** Import the schema, which also creates the
   database itself and seeds sample categories and products:

   ```
   mysql -u root -p < config/schema.sql
   ```

   If you already have an earlier install with real data, run
   `config/migrations.sql` instead — it adds the newer columns/tables without
   touching your existing rows.

3. **Set database credentials** (only needed if they differ from XAMPP's
   defaults of user `root` with no password). Edit `config/database.php`,
   or set environment variables `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`.
   Set `APP_URL` to the trusted HTTPS origin used in password-reset emails,
   for example `https://procurement.example.com/tps`.

4. **Set folder permissions** so uploads can be written:

   ```
   chmod -R 775 uploads/
   ```

5. **Visit the site** at `http://localhost/trust-procurement-system/` (or
   your equivalent path).

## Google sign-in

The login page shows **Continue with Google** when Google OAuth is configured.
Create a Web OAuth client in Google Cloud and add the exact callback URL below
to its authorized redirect URIs:

```text
GOOGLE_CLIENT_ID=your-client-id.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REDIRECT_URI=http://localhost/tps/google_callback.php
```

PHP cURL must also be enabled. The callback URL must match the URL registered
with Google exactly. The flow verifies the returned email and protects the
OAuth state against request forgery.

## Bootstrap the first administrator

The schema does not contain a default administrator password. Create the first
administrator with a unique password after importing the database. Generate a
hash with PHP, then insert it into the `users` table:

```powershell
php -r "echo password_hash('REPLACE_WITH_A_UNIQUE_PASSWORD', PASSWORD_DEFAULT), PHP_EOL;"
```

Use the generated hash in an `INSERT` statement with the `super_admin` role.
Never commit the password or its hash to the repository.

## User roles

Five roles, least to most privileged:

- **Customer** (`user`) — the public site plus their own requests, orders,
  and payments.
- **Supplier** (`supplier`) — a supplier portal: dashboard, company profile,
  the products they supply, notifications, and the purchase orders placed with
  them — view each order, propose a delivery date, acknowledge or decline it
  (and change or withdraw that response), upload quotes/invoices/delivery
  notes, and print/save the order as a PDF.
- **Staff** — the day-to-day operational admin pages: products, categories,
  purchase orders, payments, messages, product reviews, and the point-of-sale
  till.
- **Administrator** — everything Staff can do, plus users, reports, audit
  logs, and promotional banners. Can assign Staff/Supplier/Customer roles, but not
  Administrator or Super Administrator.
- **Super Administrator** — everything, including managing other
  Administrator/Super Administrator accounts. There is always exactly one
  protected account of this kind seeded by `schema.sql`; it cannot be
  deleted, and only another Super Administrator can edit or reassign it.

Create Staff/Administrator accounts directly from **Admin → Users → Add
user**; public registration always creates a Customer account.

## Deleting users

Deletion from **Admin → Users** is a **soft delete**: the account is marked
suspended and hidden from login, but the row (and all their historical
orders, payments, and reviews) is preserved intact via foreign keys rather
than being destroyed. Use the **Deleted** filter tab on the Users list to
find and restore an account. Super Administrator accounts cannot be
deleted at all, and nobody can delete their own account.

## Product comparison

Visitors can add up to 4 products to a comparison list (stored in their
session — no account needed) from the **Compare** button on any product
card or the product detail page. The comparison page
(`compare.php`) shows products side by side — image, name, brand, category,
price, availability, rating, description, and every specification found
across the compared products — and highlights rows where values differ.

## Product reviews

Logged-in customers can rate and review a product from its detail page.
Reviews are moderated: they start **pending** and only appear publicly once
an admin approves them from **Admin → Product Reviews**. A customer can only
leave one review per product; submitting again updates their existing
review rather than creating a duplicate.

## Promotional banners & homepage sections

**Admin → Promotional Banners** manages the rotating hero banner shown on
the homepage (only banners that are active and within their start/end date
window are shown; with none active, the homepage falls back to its default
hero). A second banner or more also appear as a "Promotional Offers" strip
further down the homepage.

The homepage follows this flow: hero/banners → categories → New Arrivals →
Featured Products → Popular Products (ranked by request volume) →
comparison call-to-action → promotional offers → customer reviews → footer.
Featured and New Arrival products are flagged per-product from **Admin →
Products → Edit** (or in bulk from **Admin → New Arrivals**); Popular
Products needs no manual flagging — it's ranked automatically from order
history.

## How the deposit model works

Each product can be marked "high-end," with a configurable deposit percentage
(default 70%). When a customer's procurement request is approved:

- An **order** is created with `total_amount` split into `deposit_amount` and
  `balance_amount` based on the product's deposit percentage (standard
  products default to 100% due up front — no partial deposit).
- The customer submits a **deposit payment** (method, reference note, and an
  optional proof-of-payment image) from their order page.
- An admin reviews and **verifies** the payment from Admin → Payments. Once a
  deposit is verified, the order automatically advances to "Deposit Paid."
- The admin manually advances the order through procuring → shipped →
  delivered → completed as fulfillment progresses, and records the balance
  payment the same way once it's collected.

## Email notifications

The app sends email at these points: request approved, request rejected
(with the admin's reason), payment verified, payment rejected, customer
receipt confirmation, and order status changes. Install the mail dependency
with `composer install`, then set these environment variables before starting
PHP:

```text
SMTP_HOST=smtp.example.com
SMTP_PORT=587
SMTP_USERNAME=your-smtp-user
SMTP_PASSWORD=your-smtp-password
SMTP_ENCRYPTION=tls
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME=Trust Procurement System
```

`includes/mailer.php` uses PHPMailer over SMTP when `SMTP_HOST` and the
Composer dependency are available. It falls back to PHP `mail()` locally and
logs the transport and result to the audit log. Never commit SMTP credentials
to the repository.

## Password reset

Users can request a reset link from the login page ("Forgot your
password?"). The link contains a random token (only its SHA-256 hash is
stored) that expires after 1 hour and can only be used once. The response is
identical whether or not the email is registered, so the form can't be used
to check which emails have accounts.

Logged-in users of either role can also change their password directly from
**My account** (linked in both the admin and customer top bars), which also
lets them update their name and phone number.

## Cancellations and rejections

- **Customers** can cancel a request while it's still pending, or cancel an
  order before any deposit payment has been submitted, from their
  Requests/Orders list or detail page.
- **Admins** reject a request through a short form that collects a reason
  (shown to the customer, both on their request page and by email) rather
  than a single click.
- **Admins** can also cancel an order in progress from Admin → Orders →
  Manage, with a reason that's emailed to the customer.

## Contact messages

Messages submitted through the public Contact page land in **Admin →
Messages**, with an unread-count badge in the sidebar. Opening a message
marks it read; each message links to a `mailto:` reply.

## Supplier management

**Admin → Suppliers** tracks who electronics can be sourced from and what
was actually purchased from them:

- **Suppliers** — contact details and an active/inactive status. A supplier
  can only be permanently deleted if it has no linked products and no order
  history; otherwise, deactivate it instead to keep past records intact.
- **Sourcing options** (`supplier_products`) — which products a given
  supplier can provide, their price, whether they cover warranty, and
  typical lead time. This is reference data for choosing where to buy from,
  not a live stock ledger — inventory management remains out of scope.
- **Order fulfillment** — on an order's management page, staff can record
  _which_ supplier actually fulfilled it (visible to everyone with admin
  access). **Supplier cost and margin are admin/super_admin only** — the
  cost field and every cost/margin figure are hidden from Staff both in the
  UI and, independently, on the server (a forged submission from a Staff
  session is silently ignored rather than trusted).
- **Admin → Reports → Supplier report** — spend, margin, and active sourcing
  options per supplier, exportable as CSV (admin-only, since the whole
  Reports section already requires Administrator access).

## Purchase orders (buying from a supplier)

A note on naming: **"Purchase Order"** in the sidebar under **Administration**
refers to a real order placed with a supplier — a separate thing from the
**Customer Orders** module under Operations (a customer's own order, which
in an earlier round briefly also used the term "Purchase Order" for its
status vocabulary — that's now called Customer Orders to avoid confusion).

**Admin → Purchase Orders** (admin/super_admin only, same boundary as
supplier pricing) covers the actual buy-from-supplier workflow:

- **Draft** — create a PO against a supplier, add line items (product,
  quantity, unit cost). Each line can optionally be tied to a specific
  customer order (so you know _why_ you're buying it) or left as a general
  restock. A supplier's known price (from Sourcing Options) is suggested
  automatically when you pick a product, and the subtotal/grand total
  recalculate on every change.
- **Submit → Approve/Reject** — a draft is submitted for approval; an
  admin/super_admin approves or rejects it (with a reason). A rejected PO
  can be reverted to draft for revision and resubmitted.
- **Send** — mark an approved PO as sent to the supplier.
- **Receive** — record deliveries against a sent PO, per line item, in one
  or several batches. Entering more than the remaining quantity is clamped
  rather than rejected outright. The PO automatically becomes "Partially
  Received" or "Received" depending on whether every line is fully in.
- **Cancel** — allowed any time before goods have been sent, with a reason.
- From a customer order's page, **"Create purchase order"** jumps straight
  into a new PO prefilled with that order's product and quantity — the
  quickest path from "a customer wants this" to "we're buying it."

PO numbers are generated as `PO-2026-000001` (year-scoped, sequential).

## Product galleries and reports export

- Products can have a single **cover image** (shown on cards and listings)
  plus any number of **gallery images**, managed from Admin → Products →
  Edit. The public product page shows a clickable thumbnail strip under the
  main image; clicking a thumbnail swaps the main image with no page reload.
- Every report (Admin → Reports) has an **Export CSV** button — products,
  requests, orders, and payments can each be downloaded as a CSV file for
  use outside the app.

## Search

A search box lives in the site header on every public page, searching
product name and brand and landing on the filtered product catalog — the
same search also works directly from the Products page filter bar.

## Typography

Headings use **Lora** (a warm serif that keeps the "trusted advisor" feel
from the original design), paired with **Montserrat** for body copy and UI
chrome — a clean, modern geometric sans that reads well in both marketing
copy and dense admin tables. Both are loaded from Google Fonts in
`assets/css/style.css`.

### Supplier responses and documents

Once a purchase order is **sent**, the supplier can:

- **Acknowledge** it together with the delivery date they can commit to
  (validated as a real calendar date, never trusted from the form) and an
  optional note, or **decline** it with a reason.
- **Change or withdraw** that response at any point while the order is still
  awaiting delivery — a withdrawal resets it to pending and tells the buyer.
- **Upload documents** (quote, invoice, delivery note), up to 10MB each. Files
  are stored outside the web root in `tps_private/po_documents/` and streamed
  through `po-document.php`, which only serves them to the supplier that owns
  the order or to staff/administrators.

The buyer is notified of every response, withdrawal and upload, and can accept a
proposed delivery date in one click (Admin → Purchase Orders → the order), which
copies it into **Expected delivery** and notifies the supplier.

Installing over an earlier database requires `config/migrations.sql` for the
`purchase_orders.supplier_proposed_delivery_date` column and the
`purchase_order_documents` table.

## Point of sale (till)

Admin → Point of Sale is a counter terminal for staff. A counter sale is not a
separate kind of record: it is an ordinary order carrying `channel = 'pos'`,
plus `order_items`, a verified `payments` row and stock movements — so
reporting, fulfilment and notifications all treat it like any other sale.

- **Ring up a sale** (`admin/pos/index.php`) — a scan box and a product grid.
  Products carry an optional **SKU** and **barcode**, entered by staff on
  **Admin → Products → Add/Edit**, so a USB scanner simply types into the scan
  box and presses Enter; that box holds the focus when the page loads and keeps
  it after every addition, so items can be rung up back to back without touching
  the mouse. Codes are typed, never generated, and each must be unique — a code
  already used by another product, as an SKU *or* a barcode, is refused with an
  explanation rather than a database error. Quantities, discounts, the customer
  and the payment method are all set at the counter. The terminal works without
  JavaScript, and `assets/js/pos.js` then swaps the cart panel in place rather
  than reloading between items.
- **Paying at the counter** — Cash, Mobile Money, Card, Customer Wallet or Credit
  (pay later). Cash records what was tendered and the change given. Wallet and
  credit sales both need a registered customer, and a credit sale is checked
  against the customer's outstanding balance and credit limit first.
- **Receipts** (`admin/pos/receipt.php`) — open automatically when a sale is
  completed and printable straight away; the same URL reprints any past till
  sale.
- **Who was served** — the till captures a **name, phone and optional email**
  for every sale, and those details are printed on the receipt. Picking a
  registered customer pre-fills them from the account, but they stay editable, so
  an operator can correct a number or record a sale made on someone else's
  behalf. A walk-in is typed in by hand — before this there was nowhere at all
  to record one, so walk-in receipts were blank. The details are **snapshotted
  onto the sale** rather than read live from the account, so a customer changing
  their phone number later never rewrites a receipt already issued.
- **Sales register** (`admin/pos/sales.php`) — every till sale, filterable by
  payment method and searchable by receipt number or customer. It is read-only:
  corrections are made on the order itself, so the register stays an honest
  record of what the counter took.
- **Wallets** (`admin/pos/wallets.php`) — customer balances, the ledger behind
  them, and top-ups. A wallet is created the first time it is used, or from the
  till's "Top up wallet" link when a balance is short. Any staff member can read
  balances and history, but **only an administrator can add credit**: the till
  withholds the form for staff and the server refuses the POST regardless of
  what was submitted. Every top-up is recorded in the audit trail against the
  administrator who took the money, and the customer is notified.

Sales with money still owed show the balance on the receipt and in the register,
due on the date taken from that customer's credit terms. Walk-in customers are
booked against one shared placeholder account, and the name typed at the counter
is what appears on the receipt and in the register.

Installing over an earlier database requires `config/migrations.sql` for the POS
columns (`products.sku`, `products.barcode`, `orders.channel`, `orders.sold_by`,
`orders.balance_amount`, `orders.discount_amount`,
`orders.walk_in_customer_name`, `orders.credit_due_date`, `users.credit_limit`,
`users.credit_terms_days`, `orders.pos_contact_name`,
`orders.pos_contact_phone`, `orders.pos_contact_email`), for the
`payments.payment_type` value `'pos'`, and
for the `customer_wallets` and `wallet_transactions` tables.


## Project structure

Matches the structure agreed for the proposal:

```
trust-procurement-system/
├── admin/          Admin-only pages, role-gated (require_staff/require_admin)
│   └── pos/        Point-of-sale till: terminal, cart, checkout, receipt,
│                   sales register, customer wallets
├── supplier/       Supplier portal, role-gated (require_supplier)
│   ├── dashboard.php
│   ├── notifications/    In-portal notifications (sidebar badge)
│   ├── products/         View products they supply
│   ├── profile/          Manage company profile
│   └── purchase_orders/  Track POs from buyer (respond, upload documents, print)
├── assets/         CSS, JS, images
├── config/         Database connection + schema.sql + migrations.sql
├── includes/       Shared layout, auth guards, helper functions, product_card + hero partials
├── uploads/        Product images, gallery images, banner images, payment proofs
├── user/           Customer-only pages (require_user())
└── *.php           Public site (home, catalog, compare, new arrivals, auth, static pages)
```

## Notes on scope

Per the project proposal, this build intentionally excludes: online payment
gateways (payments are recorded manually and verified by an admin), SMS
notifications, and a native mobile app. These are flagged as candidates for a
future phase.

Supplier management and inventory management are in scope and shipped
(Admin → Suppliers, Admin → Purchase Orders, Admin → Inventory, plus the
supplier portal). A supplier account can see its dashboard, edit its own
company profile, browse the products it supplies, manage its notifications, and
work the purchase orders placed with it. The point-of-sale till described above
is likewise shipped (Admin → Point of Sale).

## Security

Every state-changing request — every add/edit/delete, approve/reject,
verify, restore, and cancel action across the whole app — requires a
per-session CSRF token (`includes/functions.php`: `csrf_token()`,
`csrf_field()`, `verify_csrf()`) and is submitted as a POST. There are no
GET-triggered destructive actions left anywhere in the app: a link can no
longer delete a product, approve an order, or verify a payment just by
being loaded. This was tested directly — forged submissions with no token,
a garbage token, or a bare GET request are all rejected while the real
form flow (with its real token) still works.

## Known limitations

- Uploaded files are validated by extension, not by inspecting file content.
- No rate limiting on login attempts.
- Notification emails use PHP's `mail()`, which needs a configured MTA to
  actually deliver (see "Email notifications" above).

## A bug worth knowing about (fixed)

`includes/admin_layout_start.php` and `includes/user_layout_start.php` are
brought in with `include`, which shares the including page's variable
scope. Both used to loop using `$items`/`$item` to render the sidebar nav —
generic enough names that `admin/purchase_orders/view.php`, which builds
its own `$items` (the PO's line items) _before_ including the layout, had
that array silently overwritten by the nav's own loop partway through
rendering. The items table looked populated but was actually showing
nav-link data with the real columns blank.

Fixed by renaming the layout's internal loop variables to
`$navSectionLabel`/`$navSectionLinks`/`$navLink`, which can't collide with
any page's own data. Worth remembering if you add new admin/user pages:
avoid `$items`, `$item`, and `$section` as top-level variable names, since
they're common enough that a future collision is exactly how this one
happened.
