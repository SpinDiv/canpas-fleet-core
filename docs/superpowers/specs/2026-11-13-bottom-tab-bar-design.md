# Bottom tab bar for small roles, and an Account page — design

Approved by Ramon, 2026-11-13.

## Why
On phones the portal should feel like an app. Yard checkers and drivers only ever use two
pages, so a slide-out menu is overkill for them; a tab bar fixed to the bottom of the screen
(Loadings · Returns · Account, or Today · Expenses · Account) is faster and familiar.
Every user also needs a place to change their own password.

## Goals
- Bottom tab bar on screens 820px wide or narrower for users whose pages fit in 2–4 tabs
  (today: yard checker and driver). Decided by page count, not role name.
- New **Account** page for every role: name, role, email, change password, log out.
- For bar users the phone top bar shows logo and initials only (no ☰, no log-out icon; log out lives on Account).

## Non-goals
- Admin, dispatcher and customer menus are unchanged (☰ slide menu).
- Desktop is unchanged (sidebar for everyone).

## Approaches considered
1. **Bar plus "More" tab for every role** — one navigation pattern, but large roles get an arbitrary 4-tab shortlist. Not picked.
2. **Bar and ☰ for every role** — two menus on a small screen. Not picked.
3. **Bar only for roles whose pages fit (picked, Ramon's suggestion)** — perfect fit for yard and driver, no change for the rest.

## Design
- `Nav::PAGES` gains `'account' => ['Account', 'user', 'Account', 'cf_access_portal', 'M1']`; a `user` icon is added.
- `Nav::tabs(Actor)` returns the actor's openable pages (with Account last) when there are 2–4, else `[]`.
- `Layout::page` adds `has-tabs` to the shell and renders `<nav class="tabbar" aria-label="Main">` when tabs exist; ☰ button, scrim and logout icon are omitted for those users.
- CSS (≤820px only): bar fixed to bottom, navy text on white, 1px top line, each tab ≥56px tall with icon + 12px label, current tab orange (`--accent`), `padding-bottom: env(safe-area-inset-bottom)`; `main` gets bottom padding so nothing hides behind it; `.toast` sits above the bar.
- Account screen (`AccountScreen`): details card plus a change-password form (current, new, confirm) posting to `POST canpas/v1/account/password`; a Log out button.
- `Account::changePassword(Actor, current, new, confirm)`: refuses with `DomainError` reasons `wrong_password`, `password_mismatch`, `weak_password` (fewer than 8 characters); otherwise `wp_set_password`, re-issues the login cookie so the user stays signed in, and writes `Audit::log('password_changed')` (no password in the log).

## Left to the builder
Exact icon path, card wording.
