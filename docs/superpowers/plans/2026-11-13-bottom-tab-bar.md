# Bottom Tab Bar and Account Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Phone tab bar for users with 2–4 pages (yard checker, driver) and an Account page with password change for everyone.

**Architecture:** `Nav` decides which users get tabs; `Layout::page` renders the bar; a small `Domain\Account` owns the password rule; `AccountScreen` renders the page and posts to a new REST route.

**Tech Stack:** WordPress plugin, PHP 8.1, vanilla JS (`assets/portal.js`), self-test via `wp canpas selftest`.

**Spec:** `docs/superpowers/specs/2026-11-13-bottom-tab-bar-design.md`

## Global Constraints
- Tab bar appears only at max-width 820px and only when `Nav::tabs()` is non-empty.
- Tap targets ≥44px; labels 12px; current tab colour `var(--accent)`.
- Refusals throw `DomainError` with reasons `wrong_password`, `password_mismatch`, `weak_password`; minimum length 8.
- Audit action `password_changed`, written inside a `Db::transaction()`, no password in the details.
- Commits as Jose Ramon Catanjal, no co-author lines.

## Review Focus
- Driver/yard user rotating the phone or using a tablet ≤820px: bar still shows and content is not hidden behind it (main bottom padding).
- Toast after saving appears above the bar, not under it.
- Password change keeps the user signed in (next REST call still authorised with a fresh nonce returned by the route).
- Admin, dispatcher and customer still see ☰ and no bar (self-test asserts `Nav::tabs()` is empty for them).
- Pending users never see the bar (they get the waiting message before any page renders).

---

### Task 1: Navigation rules and password change (domain + REST + self-test)

**Files:**
- Modify: `src/Portal/Nav.php` (add `user` icon, `account` page, `tabs()`)
- Create: `src/Domain/Account.php`
- Modify: `src/Http/Rest.php` (route `/account/password`)
- Modify: `src/Cli/SelfTest.php` (driver menu check, new checks in `milestone1`)

**Interfaces:**
- Produces: `Nav::tabs(Actor $a): list<array{slug:string,label:string,icon:string}>` — openable pages in `PAGES` order with `account` moved last, or `[]` when count is not 2–4.
- Produces: `Account::changePassword(Actor $a, string $current, string $new, string $confirm): void`.
- Produces: `POST canpas/v1/account/password` body `{current,new,confirm}` → `{ok, message:'Password changed.', nonce:<fresh wp_rest nonce>}`.

- [ ] **Step 1: Add self-test checks**
  - Update the existing check to `$menuSlugs === ['today', 'expenses', 'account']`.
  - `Driver gets bottom tabs Today, Expenses, Account`: `array_column(Nav::tabs($driver),'slug') === ['today','expenses','account']`.
  - `Yard checker gets bottom tabs Loadings, Returns, Account` (make a yard actor with `$mk`).
  - `Admin, dispatcher and customers keep the side menu`: `Nav::tabs()` is `[]` for `$admin`, `$dispatcher`, `$custA`, `$userB`.
  - `Every role can open Account`: `Nav::canOpen` true for all of the above.
  - `expect('Wrong current password is refused','wrong_password', ...)`, same for `password_mismatch` (new≠confirm) and `weak_password` (`'short7!'`).
  - `A valid change works and is audited`: after `changePassword($driver, <set pw>, 'NewPass-123', 'NewPass-123')`, `wp_check_password('NewPass-123', hash)` is true and one `password_changed` audit row exists for the user. (Set the starting password with `wp_set_password` first; test users are deleted in teardown.)
- [ ] **Step 2: Deploy and run `wp canpas selftest`** — expect the new checks to fail (`Class Account not found` / menu mismatch).
- [ ] **Step 3: Implement Nav and Account.** Icon `user`: `M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0`. `changePassword` checks with `wp_check_password` against the user's stored hash, sets with `wp_set_password`, then, only when `$a->userId === get_current_user_id()`, calls `wp_set_auth_cookie($a->userId, false)` and `wp_set_current_user($a->userId)`.
- [ ] **Step 4: Add the REST route** with `self::post`, reading `self::body($r)`, returning the fresh nonce from `wp_create_nonce('wp_rest')`.
- [ ] **Step 5: Deploy, run selftest** — all checks pass.
- [ ] **Step 6: Commit** "Account page rules: password change and bottom tabs for small roles"

### Task 2: Tab bar and Account screen

**Files:**
- Create: `src/Portal/Screens/AccountScreen.php`
- Modify: `src/Portal/Pages.php` (`BUILT['account']`)
- Modify: `src/Portal/Layout.php` (markup + CSS)
- Modify: `assets/portal.js` (store new nonce from a response that carries `nonce`)
- Modify: `CHANGELOG.md`, plugin version → 0.6.4

**Interfaces:**
- Consumes: `Nav::tabs()`, `POST canpas/v1/account/password`.

- [ ] **Step 1: AccountScreen** — card "Your details" (name, role, email, company for customers); card "Change password" with three `password` inputs (`autocomplete` current-password / new-password), hint "At least 8 characters", posts via the existing `data-rest` form pattern; red-outline **Log out** button linking `wp_logout_url(Router::url())`.
- [ ] **Step 2: Layout** — when `Nav::tabs($actor)` is non-empty: add class `has-tabs` to `.shell`, omit the ☰ button, scrim and mbar logout icon, and append after `.body`: `<nav class="tabbar" aria-label="Main">` with one `<a>` per tab (icon 22px + `<span>` label, `aria-current="page"` on the active slug).
- [ ] **Step 3: CSS** — `.tabbar{display:none}`; inside ≤820px: `.tabbar{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:57;background:#fff;border-top:1px solid var(--line);padding-bottom:env(safe-area-inset-bottom)}`, tabs `flex:1;min-height:56px;flex-direction:column;align-items:center;justify-content:center;gap:2px;font-size:12px;color:var(--muted)`, current `color:var(--accent);font-weight:600`; `.has-tabs main{padding-bottom:calc(88px + env(safe-area-inset-bottom))}`; `.has-tabs ~ .toast, .has-tabs .toast` → `bottom:calc(72px + env(safe-area-inset-bottom))` (toast is appended to `body`, so use `body:has(.has-tabs) .toast`).
- [ ] **Step 4: portal.js** — after a successful submit, `if (res.nonce) CF.nonce = res.nonce`.
- [ ] **Step 5: Verify** — deploy, run selftest; render `/fleet/loadings`, `/fleet/today`, `/fleet/account` as demo yard and driver users at 390px and 768px, and `/fleet/orders` as admin at 390px; screenshots show the bar only for yard/driver, nothing hidden behind it, no PHP warnings.
- [ ] **Step 6: Commit** "Bottom tab bar for yard checkers and drivers; Account page"
