# Special-Rate Quotations and Branded Communications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Eligible companies can request a quotation; the Canpas admin prices it per line; the customer admin accepts, counters or declines; every client email and PDF is branded and recorded.

**Architecture:** A quotation is an order in earlier statuses (`quote_requested`, `quoted`, `quote_declined`, `quote_expired`); offers live in `cf_quote_rounds` and are written into each line's `rates_json` on accept. `Support\Mail` gains a branded template and an email log; `Support\Pdf` gains a letterhead used by the quotation, bill and agreement PDFs.

**Tech Stack:** WordPress plugin, PHP 8.1, MySQL 8, vanilla JS; tests = `wp canpas selftest` on staging.

**Spec:** `docs/superpowers/specs/2026-11-13-special-rate-quotations-design.md`

## Global Constraints
- Only `cf_admin` prices quotes and ticks "Can request special rates"; only `cf_customer_admin` of the order's company accepts / counters / declines.
- Special rates ≤ normal rates, ≥ 0; % shortcut = normal × (1 − %/100) rounded to 0.01.
- `quote_valid_days` setting 1–30, default 3; validity ends at the earlier of now + days and 23:59:59 Manila on the day before `start_date`.
- Truck editable fields: `starting_fee`, `rate_per_km`, `driver_flat_fee`, `driver_rate_per_km`, `daily_rate`. Equipment: `rate_day`, `rate_week`, `rate_month`.
- Placeholder settings: `company_legal_name` "Canpas Philippines Inc.", `company_address` "Company address, City, Province", `company_phone` "+63 900 000 0000", `company_email` "office@canpas.example", `company_tin` "000-000-000-000", `records_bcc` "".
- Refusal codes: `not_eligible`, `forbidden`, `wrong_status`, `quote_expired`, `rate_too_high`, `invalid_input`, `not_available`, `reason_required`.
- Every state change audited inside its transaction; emails sent after commit.
- Commits as Jose Ramon Catanjal, no co-author lines. Bump to 0.7.0 with CHANGELOG.

## Review Focus
- A quote sent for dates that another booking then fills: the hold must make that booking fail (`not_available`), not the accept.
- Customer *user* (not admin) pressing Accept via REST directly → `forbidden`.
- Admin types a rate with a comma ("1,200") or blank → `invalid_input` naming the field, not a silent 0.
- Quote whose start date is tomorrow: validity capped tonight; sending on the start day refused.
- Email with a missing/invalid recipient still logs a row with `sent_ok = 0` and never throws inside a committed transaction.

---

### Task 1: Migration, settings, branded email and email log

**Files:**
- Create: `migrations/0007_quotations.php`
- Modify: `src/Domain/Settings.php` (new fields + group "Company details" in `Portal/Screens/SettingsScreen.php`)
- Modify: `src/Support/Mail.php`
- Modify: `src/Cli/SelfTest.php` (new `milestoneQuotes()` called after `milestone5`, teardown of new tables)

**Interfaces:**
- Produces: migration columns `companies.special_rates`, `orders.quote_round`, `orders.quote_valid_until`, tables `quote_rounds`, `email_log` (spec "Data" + "Email record").
- Produces: `Mail::send(string $to, string $subject, array $paragraphs, ?array $button = null, array $opts = []): bool` — `$opts`: `title` (string), `summary` (list<array{0:string,1:string}>), `order_id` (?hex), `company_id` (?hex), `kind` (string, default 'general').

- [ ] **Step 1: Self-test checks:** `Migration 0007_quotations is applied`; `Company detail settings have placeholder defaults` (`Settings::get('company_legal_name') === 'Canpas Philippines Inc.'`, `quote_valid_days === 3`); `Every email is logged with its order` (send to `cftest.mail.{sfx}@canpas-demo.example` with `order_id` of a test order under the existing `pre_wp_mail` short-circuit → one `email_log` row for that order, kind matches); `An invalid recipient is logged as not sent` (`Mail::send('nope', …)` returns false and logs `sent_ok = 0`); `Branded email has the logo header and company footer` (capture body via `pre_wp_mail` filter: contains `logo-white.png`, `#141D30`, `Canpas Philippines Inc.`, and `Reply-To:` header with `company_email`).
- [ ] **Step 2: Deploy, run `wp canpas selftest`** — Expected: the new checks FAIL (missing migration / settings).
- [ ] **Step 3: Implement migration, settings, Mail template + log + BCC + Reply-To** (BCC header only when `records_bcc` is a valid email; logging never throws).
- [ ] **Step 4: Deploy, `wp canpas migrate`, `wp canpas selftest`** — Expected: all pass.
- [ ] **Step 5: Commit** "Quotations groundwork: migration, company settings, branded email with record"

### Task 2: PDF letterhead (bill and agreement adopt it)

**Files:**
- Create: `assets/logo-letterhead.jpg` (logo-color.png flattened on white, ~600px wide, made once locally with ImageMagick/PIL and committed)
- Modify: `src/Support/Pdf.php`, `src/Domain/Billing.php::pdf`, `src/Domain/Agreements.php` (its PDF builder)

**Interfaces:**
- Produces: `Pdf::letterhead(string $docTitle, string $number, string $date, array $client = []): void` — logo top-left (from `assets/logo-letterhead.jpg`), company settings top-right, title/number/date, optional client block. `Pdf::output()` writes "Page n of N" in each page footer.

- [ ] **Step 1: Self-test checks:** `The bill PDF carries the letterhead` (bytes contain `/Subtype /Image` and the text `FINAL BILL` and `Page 1 of 1`); `The signed agreement PDF carries the letterhead` (contains `RENTAL AGREEMENT`, `/Subtype /Image`). Existing PDF checks must still pass.
- [ ] **Step 2: Deploy, run selftest** — Expected: new checks FAIL.
- [ ] **Step 3: Implement letterhead and page numbers; switch Billing and Agreements to it.**
- [ ] **Step 4: Deploy, run selftest** — all pass. Render the bill PDF to PNG locally (pdftoppm) and look at it.
- [ ] **Step 5: Commit** "PDF letterhead with logo and company details; bill and agreement use it"

### Task 3: Quotation domain

**Files:**
- Create: `src/Domain/Quotes.php`
- Modify: `src/Domain/Orders.php` (`HOLDING`, labels, `book` → `insertOrder`), `src/Domain/Companies.php` (`setSpecialRates`), `src/Plugin.php` (hourly `Quotes::expire`)

**Interfaces:**
- Consumes: `Mail::send(..., $opts)` (Task 1).
- Produces:
  - `Orders::HOLDING` = `ACTIVE` + `'quoted'`; `peakBooked` uses it and treats `quoted` with `quote_valid_until < now` like an expired hold. `quote_requested` never holds.
  - `Orders::insertOrder(Actor $a, array $d, string $status): string` (was private `book`); `quote_requested` skips the availability check and sets no hold.
  - `Companies::setSpecialRates(Actor $a, string $companyId, bool $on): void` (admin only, audited).
  - `Quotes::request(Actor $a, string $kind, array $in): string` — `$kind` 'truck'|'equipment'; same input as booking plus required `note`.
  - `Quotes::offer(Actor $a, string $orderId, array $rates, string $note, ?float $pctOff = null): void` — `$rates` = `[lineId => [field => amount]]`; `$pctOff` fills unspecified fields.
  - `Quotes::preview(Actor $a, string $orderId, array $rates, ?float $pctOff): array` — same maths, no save (for the screen's live totals).
  - `Quotes::accept(Actor $a, string $orderId): void`, `Quotes::counter(Actor $a, string $orderId, string $note): void`, `Quotes::decline(Actor $a, string $orderId, string $reason): void` (customer admin: reason optional; Canpas admin: required).
  - `Quotes::expire(): int` (quoted past validity → `quote_expired`) and `Quotes::reofferExpiredHolds(): int` (called from `Orders::expireHolds` before it expires `requested` orders whose `quote_round > 0`: back to `quoted` if every line still free and window open, else `quote_expired`; emails both sides).
  - `Quotes::rounds(Actor $a, string $orderId): array`, `Quotes::validUntil(string $startDate, ?\DateTimeImmutable $now = null): string`.

- [ ] **Step 1: Self-test checks** (in `milestoneQuotes`): `A company not ticked cannot request a quotation` (`not_eligible`); `Only the Canpas admin can tick special rates` (dispatcher → `forbidden`); `A ticked company's user can request; nothing is held` (free stock unchanged); `A dispatcher cannot price a quote` (`forbidden`); `A special rate above normal is refused` (`rate_too_high`); `A rate written as text is refused` (`'1,200'` → `invalid_input`); `10% off fills every rate at 90% of normal` (frame rate_month 100 → 90.00; total = Pricing on 90); `Sending holds the stock` (another booking of the remaining+1 → `not_available`); `Validity is capped at the day before the start` (start tomorrow → valid until today 23:59:59 Manila in UTC); `A customer user cannot accept` (`forbidden`); `Another company cannot see or accept the quote` (`not_found`); `Asking for a better price releases the hold and starts round 2`; `Accepting writes the special rates onto the order and the deposit follows them` (line `rates_json` rate_month 90, `deposit_amount` = line rule on special amount, status `requested`); `The final bill charges late days at the special daily rate` (truck quote, `Billing::systemLines` late-day amount uses special `daily_rate`); `An accepted quote whose hold ends goes back to Quotation sent when still free` and `…expires when the stock has gone`; `A quote past its validity expires and frees the stock`; `Every round is in the history and audit log`; `Quote emails go to the customer admin and the Canpas address and are logged on the order`.
- [ ] **Step 2: Deploy, run selftest** — Expected: new checks FAIL (`Class Quotes not found`).
- [ ] **Step 3: Implement** Orders changes, `Companies::setSpecialRates`, `Quotes`, hourly hook.
- [ ] **Step 4: Deploy, migrate, run selftest** — all pass (including every earlier check).
- [ ] **Step 5: Commit** "Special-rate quotations: request, price, accept, counter, expire"

### Task 4: Quotation PDF, REST routes and screens

**Files:**
- Modify: `src/Domain/Quotes.php` (`pdf`), `src/Portal/Pages.php` (`file` → `?quote=&round=`), `src/Http/Rest.php`, `src/Portal/Screens/OrdersScreen.php`, `src/Portal/Screens/CompaniesScreen.php`, `src/Portal/Ui.php` (crossed-out price helper), `src/Portal/Layout.php` (CSS), `assets/portal.js` (live quote totals), `CHANGELOG.md`, plugin version 0.7.0.

**Interfaces:**
- Consumes: everything in Task 3; `Pdf::letterhead` (Task 2).
- Produces: `Quotes::pdf(Actor $a, string $orderId, int $round): string`; REST `POST quote-requests/{truck|equipment}`, `orders/{id}/quote-preview`, `orders/{id}/quote-offer`, `orders/{id}/quote-accept`, `orders/{id}/quote-counter`, `orders/{id}/quote-decline`, `companies/{id}/special-rates`.

- [ ] **Step 1: Self-test checks:** `The quotation PDF has the letterhead, both rates and the validity` (bytes start `%PDF`, contain `QUOTATION`, the ref + `/ Q1`, `Valid until`, `/Subtype /Image`); `Opening the quotation PDF is logged`; `Another company cannot open the quotation PDF` (`not_found`).
- [ ] **Step 2: Deploy, run selftest** — Expected: FAIL.
- [ ] **Step 3: Implement** `Quotes::pdf`, file route, REST routes, screens per spec "Screens" (company tick; **Request a quotation** beside Book when eligible; staff **Quotations** tab with editable rate table, % shortcut, live totals, availability, Send / Decline; customer view with crossed-out normal prices, saving, valid-until, history, emails sent, PDF link, Accept / Ask for a better price / Decline for the company admin only).
- [ ] **Step 4: Verify:** deploy, migrate, selftest all pass; render as demo_admin, demo_customer_admin, a customer user and demo_dispatcher (quote list, quote detail, booking page) with no PHP warnings; screenshot customer quote at 390px and 1280px; render the quotation PDF to PNG and look at it.
- [ ] **Step 5: Commit** "Quotation screens, routes and print-ready PDF; v0.7.0"
