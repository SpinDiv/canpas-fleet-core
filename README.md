# Canpas Fleet Core

Truck and equipment rental, dispatch and fleet system for Canpas Philippines: trucks, heavy equipment, formwork and scaffolding, with customer ordering, requirements before release, dispatch, driver and yard phone screens, returns and billing. WordPress plugin by Spindiv Kinetics.

## Layout

- `canpas-fleet-core.php` — bootstrap: paths, autoloader (`Canpas\Fleet\` → `src/`), boot hook, WP-CLI command.
- `src/` — code by area: `Portal` (front-end at `/fleet/`), `Http` (REST `canpas/v1`), `Roles`, `Tenancy` (actor, companies, memberships), `Audit`, `Support`, `Migrations`, `Seed`, `Cli`.
- `migrations/` — numbered schema migrations, applied once each.
- `assets/` — logos.

## Install

- **Staging:** the repo is cloned into the Novamira sandbox (`wp-content/novamira-sandbox/canpas-fleet-core/`) and loaded by a one-line loader file next to it.
- **Live:** place the folder in `wp-content/plugins/` and load it from a small file in `wp-content/mu-plugins/` so it cannot be deactivated by accident.

Then:

```
wp canpas migrate     # create or update tables
wp canpas seed        # staging only: roles, demo company, one demo login per role
wp canpas selftest    # rule checks against the real database; cleans up after itself
wp canpas status      # row counts per table
```

## Data

- Records live in the site database, in tables prefixed `cf_` (e.g. `wp_cf_companies`).
- Private files (IDs, licences, signed PDFs, delivery photos) live outside the web root in `../canpas-private/` and are only served through the portal after a permission check. They are never committed.
