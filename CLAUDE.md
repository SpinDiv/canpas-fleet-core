# Canpas Fleet Core — working rules

Truck and equipment rental system for Canpas Philippines, built by Spindiv Kinetics on the SweetSen Core structure.

## Architecture
- One plugin. Namespace `Canpas\Fleet`, autoloaded from `src/`.
- Portal at `/fleet/<slug>`, rendered by `Portal\Layout` outside the theme. Every page is declared once in `Portal\Nav::PAGES` (label, icon, group, capability, milestone).
- REST namespace `canpas/v1`. Routes call `Http\Rest::handle()`, which resolves the `Actor` first.
- Tables are `{$wpdb->prefix}cf_*`, created only by numbered files in `migrations/`. Ids are UUIDv7 `BINARY(16)` (hex in PHP, `UNHEX()` in SQL); money `DECIMAL(12,2)`; times UTC `DATETIME(6)`; foreign keys RESTRICT.
- Use `Support\Db` (throws on every failed query) and `Db::transaction()` for multi-row changes. Write `Audit::log()` inside the same transaction.
- Business-rule refusals throw `Support\DomainError` with a stable reason code (e.g. `hold_expired`, `forbidden`).
- The company always comes from the user's membership, never from request input. Staff have no company.
- WordPress administrators have no portal data access; the Canpas owner uses the `cf_admin` role.
- Catalog and rates (truck types, trucks, equipment items) are JetEngine post types edited in wp-admin; everything that grows daily is a custom table.

## Rules that must not break
- An order cannot be confirmed until ID, licence (with expiry after the rental end), deposit and signed agreement are all in.
- No truck, unit or stock quantity can be double-booked.
- Rates are copied onto the order at booking; later rate changes never alter existing orders.
- Every price change after booking records who, when and why.
- Private files live in `CANPAS_FLEET_PRIVATE_DIR`, outside the web root, and are served only through the portal with a permission check and a view log.

## Workflow
1. Add self-test checks in `src/Cli/SelfTest.php` first.
2. Write the domain code, then the REST route, then the screen.
3. Deploy to staging and run `wp canpas selftest`; all checks must pass.
4. Commit as Jose Ramon Catanjal; no co-author lines.

## UI standard
Work Sans; body 14px, tables 13px, labels 12px, page title 22px; 4px spacing grid; navy sidebar `#141D30`; phone screens use 16px inputs and 44px tap targets.
