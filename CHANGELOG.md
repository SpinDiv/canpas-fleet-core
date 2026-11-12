# Changelog

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
