# Changelog

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
