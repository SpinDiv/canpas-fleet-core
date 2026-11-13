# Special-rate quotations — design

Agreed with Ramon, 2026-11-13.

## Why
New clients book at the published rates. Long-standing Canpas contractors usually ask for a
special rate. Today the only place a price can change is the final bill, so a negotiated deal
would show the wrong quote and the wrong deposit until the very end. Canpas wants to show these
clients flexibility openly: a quotation they can see, negotiate, print and accept.

## Goals
- Companies the Canpas admin chooses can **request a quotation** instead of booking at normal rates.
- The **Canpas admin** (`cf_admin`) prices the quotation by editing each line's rates.
- The **customer company admin** (`cf_customer_admin`) accepts, declines, or asks for a better price.
- The accepted special rates become the order's booked rates, so the deposit, late-return days and
  extra km on the final bill all use them.
- Every quotation round can be printed / saved as a **PDF**.

## Non-goals
- Standing per-company rate cards (special rates are per quotation, not permanent).
- Negotiating after an order is held/confirmed (the final bill's existing add/remove-line flow covers later changes).
- Dispatchers pricing quotes.

## Anti-goals (a failure even if it works)
- A quote that silently differs from what the customer later pays.
- Two customers holding the same truck/stock because a quote and a booking overlapped.
- New clients seeing a "negotiate" option by default.

## Rules decided with Ramon
1. **Who can request:** only companies with **"Can request special rates"** ticked. Only the Canpas
   admin can tick it, on the company record. Everyone else sees only normal booking.
2. **Request:** any user of an eligible company (customer admin or customer user) fills in the same
   form as booking (truck, or equipment lines + fulfilment) plus a required note ("what rate are you
   hoping for / why"). Nothing is reserved while it waits for a price.
3. **Pricing:** only the Canpas admin. The admin sees each line with its normal rates and current
   availability for the dates, and types special rates per line:
   - truck line: every peso rate field of the truck type — starting fee, rate per km, driver flat fee,
     driver rate per km, daily rate. Free km and the driver's flat km stay as normal. *(builder's call)*;
   - equipment line: day / week / month rate (and keeps min days, deposit rule, replacement cost, damage fee as normal);
   - delivery-trip lines (equipment delivery priced as a truck trip): the same truck rate fields.
   A **"% off all lines"** shortcut fills every rate field with normal × (1 − %), rounded to the
   centavo, which the admin can then adjust. Line amounts and the total are recomputed with the
   existing `Pricing` functions from the special rates; the admin never types a total. Special rates
   may not exceed normal rates and may not be negative (zero is allowed).
   The admin adds a note to the customer and clicks **Send quotation**. Sending is refused if any
   line is not available for the dates.
4. **Validity and hold:** once sent, the quotation **holds** the trucks/stock and is valid for
   **3 days** (new setting `quote_valid_days`, 1–30, default 3), but never past the end of the day
   before the rental starts (Manila time). Sending is refused once that day has passed. If not accepted
   in time it becomes **Quote expired** and the hold is released (hourly job). *(builder's call)*
5. **Customer response (company admin only):**
   - **Accept** → the order becomes a normal **Held — requirements needed** order (existing flow:
     documents, deposit, signature, Canpas confirmation) with a fresh hold of `hold_hours`.
   - **Ask for a better price** (note required) → back to the Canpas admin as a new round; the hold is
     released. No limit on rounds.
   - **Decline** (reason optional) → closed.
   Customer users (non-admin) can view the quote but not respond.
5a. **Accepted but not completed in time (Ramon's choice):** when the hold of an order that came
   from a quotation runs out (`expireHolds()`), it goes back to **Quotation sent** at the same special
   rates with a fresh validity (rule 4), **only if every line is still free** for its dates and the
   validity window has not passed; otherwise it expires as today. Anything already uploaded (ID,
   licence, deposit proof, signature) stays on the order, so accepting again continues where they left
   off. Recorded as a round of kind `reoffer` by the system and in the audit log. **Both sides are
   emailed** (Ramon: "they need to be notified as our record"): the customer company admin(s) and the
   Canpas notify address get "Your hold on ORD-… ended; the special quotation is open again until …"
   — or, if it could not be re-offered, "… has expired because the items are no longer free".
   Ordinary bookings expire exactly as now.
6. **Canpas admin can also decline** a request (reason required).
7. **Deposit** = each line's own deposit rule applied to its special amount (same rule as booking today).
8. **What the customer sees:** each line shows the normal amount crossed out with the special amount
   beside it, the total saving, the valid-until date (Manila time) and the admin's note.
9. **History:** every round (who, when, the rates offered, totals, note) is kept and shown on the order
   and written to the audit log. Accept/decline/ask-again are audited too.
10. **Notifications:** email to the Canpas notify address when a request or "ask for a better price"
    arrives; email to the customer company admin(s) when a quotation is sent; email to both sides when
    an accepted quotation is re-offered or expires (rule 5a). Every email sent is also listed in the
    order's quotation history (what was sent, to whom, when) so Canpas keeps a record.
11. **PDF:** "Download quotation (PDF)" for the customer and Canpas on any sent round: Canpas logo,
    quotation number (the order ref + round, e.g. ORD-2611-0007 / Q2), company, dates, site, each line
    with normal and special rates and amounts, total, saving, deposit, valid until, admin's note, and
    "Prices are valid until … and subject to availability." Generated on the fly with the existing
    `Support\Pdf` writer (like the bill PDF); the browser's own print handles printing.

## Branded communications (added by Ramon, 2026-11-13)
Every change to a client's transaction must be backed by a proper, professional email, and the
quotation must be print-ready. Applies to **all** client emails and to the quotation, final bill and
signed agreement PDFs, not only quotations.

- **Company details** are new Settings fields with placeholder values, editable later by the admin:
  `company_legal_name` ("Canpas Philippines Inc."), `company_address` ("Company address, City, Province"),
  `company_phone` ("+63 900 000 0000"), `company_email` ("office@canpas.example"),
  `company_tin` ("000-000-000-000"), `records_bcc` (blank = off). Placeholders are obviously fake so
  nobody mistakes them for real details.
- **Email template** (`Support\Mail`, used by every existing and new email): navy (#141D30) header band
  with the white logo; title line; optional summary box of label/value rows; paragraphs; one button;
  footer with legal name, address, phone, email, TIN and "Replies go to the Canpas office". Reply-To =
  `company_email`. Plain-text alternative kept. Subjects carry the order/quote reference.
- **Email record:** new table `cf_email_log` (id, order_id NULL, company_id NULL, to_email, subject,
  kind, sent_ok, created_at). Every `Mail::send` writes a row. The order page shows "Emails sent"
  (when, to, subject) for staff and the customer. If `records_bcc` is set, every client email is BCC'd there.
- **PDF letterhead** (`Support\Pdf` gains a letterhead helper): Canpas logo (embedded as JPEG on white)
  top-left, company details top-right, document title (QUOTATION / FINAL BILL / RENTAL AGREEMENT),
  number, date, client block, and page numbers "Page n of N". The bill and agreement PDFs adopt it.
  The quotation PDF additionally shows: valid until, normal rate / special rate / amount per line,
  total, saving, deposit, terms, "Prepared by <admin name>".

## Approaches considered
1. **Quotation = an order at an earlier stage (picked).** New statuses before `requested`; special
   rates replace the copied `rates_json` on each line; existing pricing, availability, deposit and
   billing reuse them. One order number per job.
2. **Separate quotation tables copied into an order on accept.** Rejected: duplicates pricing,
   availability and listing code, and the copy step can drift.
3. **Discount line or fixed total.** Rejected in conversation: extras (late days, extra km) would fall
   back to normal rates.

## Design
### Data (migration 0007_quotations)
- `companies.special_rates TINYINT(1) NOT NULL DEFAULT 0`.
- `orders.quote_round INT UNSIGNED NOT NULL DEFAULT 0` and `orders.quote_valid_until DATETIME(6) NULL`.
- New table `cf_quote_rounds`: id, order_id (FK RESTRICT), round, kind (`request` | `offer` | `counter`
  | `accept` | `decline` | `reoffer`), by_user, note, lines_json (per line: line id, normal rates, special rates,
  normal amount, special amount), total, normal_total, deposit, created_at.
- Order line `rates_json` keeps the **normal** rates until acceptance; offered rates live in the round.
  On accept, the latest offer's special rates are written into each line's `rates_json`
  (plus `"normal_rates"` kept alongside for display), and line `unit_price`/`amount`/`deposit` and
  order `subtotal`/`total`/`deposit_amount` are set from the offer.

### Statuses
`quote_requested` → (`quoted` ⇄ `quote_requested` via ask-again) → `requested` (accepted) | `quote_declined` | `quote_expired`.
Labels: "Quotation requested", "Quotation sent", "Quotation declined", "Quotation expired".
- **Availability:** a `quoted` order whose `quote_valid_until` is in the future counts as holding,
  exactly like a `requested` order before `hold_expires_at`. `quote_requested` holds nothing.
  Introduce `Orders::HOLDING` for `peakBooked` only (dispatch checks use assets/trips, which quotes never have) rather
  than adding quote statuses to `Orders::ACTIVE`, which also drives dispatch boards, yard lists and reports
  where quotations must not appear.
- Accepting re-checks availability inside the transaction (the hold should guarantee it; the check is
  the safety net) and refuses with `not_available` if something changed.

### Domain (`Domain\Quotes`)
`request(Actor, array $in): string` (reuses the booking validation/pricing; refuses `not_eligible` if
the company is not ticked), `offer(Actor, string $orderId, array $rates, string $note): void`,
`accept(Actor, string $orderId): void`, `counter(Actor, string $orderId, string $note): void`,
`decline(Actor, string $orderId, string $reason): void`, `expire(): int` (hourly),
`rounds(Actor, string $orderId): array`, `pdf(Actor, string $orderId, int $round): string`.
Refusal codes: `not_eligible`, `forbidden`, `wrong_status`, `quote_expired`, `rate_too_high`,
`invalid_input`, `not_available`, `reason_required`.

### Screens
- Company record (admin): "Can request special rates" checkbox.
- Customer booking pages (Book a truck, Rent equipment): when eligible, a second button
  **Request a quotation** next to Book, with the note field.
- My orders: quotations appear with their status; quote detail shows the comparison table, valid-until,
  note, history, PDF link, and (company admin only) Accept / Ask for a better price / Decline.
- Staff Orders: new **Quotations** tab (requested + sent); quote detail for the admin with the editable
  rate table, % shortcut, live recomputed totals ("Get price" pattern), availability per line, note,
  Send quotation / Decline. Dispatchers see it read-only.
- PDF served through `/fleet/file?quote=<order>&round=<n>` with the usual permission check and view log.

### Self-test (before code, per CLAUDE.md)
Eligibility, admin-only pricing and ticking, customer-admin-only accept, rates above normal refused,
% shortcut maths, hold only while `quoted` and valid, expiry releases stock, ask-again releases the
hold and starts a new round, an accepted quote whose hold runs out returns to Quotation sent only if still free, accept writes special rates so the final bill's late days and extra km
use them, deposit from special amounts, another company cannot see or accept, PDF bytes start with `%PDF`.

## Left to the builder
Exact wording, table layout, email text, how the % shortcut UI looks, PDF layout details.
