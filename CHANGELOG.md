# Changelog

## 0.6.3 — 2026-11-13

- Phones and tablets: page content always starts right under the top bar (short pages such as the calendar were being pushed down).
- Status tabs (Orders, Documents, Payments, Statements, Reports and others) are one sliding row instead of wrapping: swipe on phones and tablets, arrows on desktop, the selected tab scrolled into view, and counts shown as small badges.
- Sign-in and request-account pages on tablets and phones show only the form card, centred on the screen (the dark brand panel is hidden below 1025px wide).

## 0.6.2 — 2026-11-13

- Phone layout for every portal user: a fixed top bar with the Canpas logo on the left and the profile, log out and a menu (hamburger) button on the right. The menu slides in from the left (70% of the screen on phones, half on tablets); the menu button turns into an X while it is open. It closes on a link, a tap anywhere outside it, Escape or the X.

## 0.6.1 — 2026-11-13

- The site's home page now opens the portal: visitors go to the Canpas sign-in, signed-in users to their dashboard. New setting "The site's home page opens" (Settings › Website) switches back to the theme's pages when a public website is wanted.

## 0.6.0 — 2026-11-13

Milestone 5: closing orders and the money.

- Final bill, worked out when an order is returned, always at the rates copied onto the order at booking: the booked price; a kilometre adjustment when the odometer differs from the quote by more than the allowed margin (Settings, default 5%); late return days (truck daily rate, or equipment re-priced for the longer period); fuel shortfall × fuel price (new setting); damaged pieces × damage fee and missing pieces × replacement cost; tolls and parking logged on the order at cost.
- Admins can add charges (waiting time, surcharge, cleaning, damage, other) or discounts and remove lines; every change needs a reason, shows on the bill and is audited. Issuing locks the bill, applies the deposit and emails the customer.
- Settlement: the customer pays the balance with proof (or the office records cash or a transfer), and the office records refunds of any deposit left over. When both are done the bill is settled and the order closed. Deposits on cancelled or declined orders are refunded with a reason.
- Bill PDF (billing statement, not an official receipt). Statements page for the office (to bill, awaiting settlement, deposits to refund, settled, and each company's statement) and My statements for customer admins, with a running balance.
- Maintenance: log services, repairs, tyres, registration, insurance and inspections with cost and receipt; the next service is set from the odometer and the interval (new setting); renewals move the expiry dates; a repair can put a truck or unit back in service. Alerts for expired or expiring registration, insurance and inspections, services due, and anything in repair, also on the dashboard.
- Reports with CSV export: revenue by month and by customer, trucks (days out, utilisation, km, fuel, km per litre, other costs, maintenance, revenue less costs), equipment revenue by item, fuel and expenses, overdue returns.
- Migration `0006_billing`. Self-test adds checks for every bill rule, locking, settlement, refunds, statements, maintenance and reports.

## 0.5.0 — 2026-11-13

Milestone 4: running the fleet.

- Assigning: the dispatcher gives each confirmed truck order a truck of the booked type and, if requested, a company driver; heavy equipment gets its units by serial; delivered equipment gets a delivery and a pickup run (truck and driver). No truck, unit or driver can be on two overlapping jobs, checked under row locks; drivers need a licence valid to the end of the job.
- Driver phone screens: My trips (today and the next 7 days), each with Google Maps and Waze buttons and step buttons — Leaving yard, Arrived on site, Delivered, Picked up, Back at yard. Odometer at leaving and return, photos at each key step, receiver's name and signature at delivery. The order status follows the trip, and the truck's odometer is updated.
- Fuel and expenses: amount, litres, truck, order and a receipt photo. Drivers see their own; the office sees all and per order.
- Yard phone screens: Loadings (count equipment out against the booking; customer pickups sign on the phone; self-drive trucks are released with odometer, photos and the customer driver's signature) and Returns (truck odometer, fuel shortfall, damage and photos, optionally sent to repair; equipment counted back as good / damaged / missing, with damaged and missing taken out of stock and damaged units sent to repair). The return records actual km for the final bill.
- Office: Dispatch board (to assign, going out, coming back, overdue), 14-day calendar of every truck and heavy unit, Drivers page (licence and expiry, add a driver login), Operations card on every order with the assignments and a timeline of every step with photos and signatures. Customers see their order's timeline, photos and delivery signature.
- All photos, signatures and receipts are private files, opened only through the portal and logged.
- Dispatchers can now release and check in at the yard (cf_count_stock). `wp canpas migrate` also refreshes role capabilities.
- Migration `0005_operations`. Self-test adds checks for assignment rules, step order, odometer, signatures, loading and return counts, stock and file access.

## 0.4.0 — 2026-11-13

Milestone 3: requirements.

- Documents: customers upload a government ID and, when needed, a driver's or operator's licence (front and back) into private storage. Each is reused on later orders until it expires. A licence must be valid until the rental ends. Opening any private file is permission-checked and logged (who, when, which side).
- A licence is required for self-drive trucks and for orders with heavy equipment.
- Deposit: customers send proof of a bank or GCash payment; Canpas marks it received or rejects it with a reason. Only received payments count towards the deposit.
- Rental agreement: versioned text with merge tags, edited on the new Rental agreement page (admin). Customers read it, type their name, draw their signature and tick to agree. A PDF with the signature, Philippine time, IP reference and version is saved privately, with its SHA-256 on the order. A starting draft with bracketed parts for Canpas's lawyer is included.
- Steps: the customer sends the order once all four are in; Canpas sends it back with a note, declines it with a reason, or confirms it. Confirming needs the ID and licence checked, the deposit received in full and the agreement signed. Customers and Canpas are emailed at each step.
- Retention: ID and licence files are deleted after the retention period (hourly job) unless an open order still uses them; the record stays with the last four digits.
- Screens: requirements checklist on every order, signing page with a signature pad, My documents (customers); Documents, Deposits & payments and Rental agreement (staff); dashboard alerts for orders, documents and payments to check.
- Migration `0004_requirements`. Self-test grows to cover the confirm gate, licence expiry, private files and view log, reuse, deposit amount, signing and PDF, send back, decline and retention.

## 0.3.0 — 2026-11-13

Milestone 2: ordering.

- Pricing rules: truck trip (starting fee + km × rate + Canpas driver flat fee for the first N km, then per km + daily rate; free km; round-trip or one-way driver basis) and equipment (cheapest mix of months, weeks and days, minimum rental), deposits. Spec examples verified: ₱7,080 / ₱2,124 deposit; 100 frames × 40 days = ₱21,900.
- Round-trip distance from the yard via Google Routes API (computeRouteMatrix) when `CANPAS_GOOGLE_SERVER_KEY` is set, cached 30 days; otherwise a clearly marked estimate (straight line × 1.3).
- Stock ledger for formwork, scaffolding and tools; staff add or remove stock with a reason.
- Booking: customers book a truck or rent equipment (delivery or pickup), see the price first, and the order is held for the hold time. Availability is checked per day with row locks, so nothing can be double-booked; expired holds release stock immediately and are marked hourly.
- Rates are copied onto each order line; later rate changes never alter existing orders. Prices sent by the browser are ignored.
- Screens: Book a truck, Rent equipment, My orders and order detail (customers); Orders list and detail (staff); stock levels on Formwork & scaffolding; delivery truck type and Google key status in Settings.
- Migration `0003_orders`. Self-test grows to cover pricing, distance, stock, availability, holds, access and cancelling.

## 0.2.0 — 2026-11-13

Milestone 1: foundation screens.

- New Canpas sign-in page at `/fleet/login` (posts to WordPress, so passwords and two-factor still work); every WordPress sign-in link points to it.
- Company sign-up at `/fleet/register` with business permit upload to private storage, spam honeypot and rate limit; emails to Canpas and the applicant.
- Approvals: approve or decline (with reason) pending companies; suspend and reactivate approved ones. Permit viewing is logged.
- Companies list and detail; company users page where a customer admin adds colleagues (set-password email) and removes access.
- Catalog in the portal: truck types with rates, trucks, heavy equipment with rates and units by serial, formwork / scaffolding / tools with day, week and month rates, minimum rental, deposit, replacement and damage fees. Every change is audited.
- Settings page: yard location, hold time, km tolerance, driver km basis, trips per rental, payment instructions, notification email, document retention.
- Staff dashboard with real figures and expiry alerts; customer home page.
- Migration `0002_catalog_signup`. Self-test grows to cover sign-up, approval, company users, catalog and settings rules.

## 0.1.0 — 2026-11-13

Week 0 foundation, built on the SweetSen Core structure.

- Bootstrap, autoloader and boot hook; WP-CLI `wp canpas` (migrate, seed, selftest, status).
- Migration runner and `0001_core`: companies, memberships, sequences, audit log.
- Roles: admin, dispatcher, yard checker, driver, customer admin, customer user, awaiting approval.
- Actor: company and permissions always come from the signed-in user's membership; suspended companies cannot act.
- Portal at `/fleet/` outside the theme: navy sidebar menu per role, top bar, Work Sans; placeholder pages showing what each page will do and in which milestone.
- Canpas-styled sign-in page; portal users are kept out of wp-admin.
- REST `canpas/v1/me`.
- Self-test with 18 checks.
