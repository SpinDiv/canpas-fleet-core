# Changelog

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
