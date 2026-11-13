# Company Profile, Registration Documents and Change Approval Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Customer company admins keep their company details and registration documents current; Canpas approves legal changes and documents; quotation requests need complete, unexpired documents.

**Architecture:** New `Domain\CompanyProfile` (contact edits, change requests, documents, quote readiness, role changes) over two new tables; `Quotes::request` gains the document gate; screens in a new `CompanyProfileScreen` plus cards on the staff company page.

**Tech Stack:** WordPress plugin PHP 8.1, MySQL, `wp canpas selftest`.

**Spec:** `docs/superpowers/specs/2026-11-13-company-profile-design.md`

## Global Constraints
- Customer edits: `cf_customer_admin` of that company only (`cf_manage_company_users`); staff review: `cf_approve_companies`.
- Structures: `sole_prop` → DTI, `partnership` | `corporation` → SEC. Permit for all. Permit and DTI need a future `expires_on`; SEC none.
- Refusal codes: `forbidden`, `invalid_input`, `file_missing`, `file_type`, `document_expired`, `change_pending`, `company_exists`, `reason_required`, `last_admin`, `documents_missing`, `wrong_status`, `not_found`.
- Files under `CANPAS_FLEET_PRIVATE_DIR/company/<company>/`; served via `/fleet/file?companydoc=<id>` with view log (`object_type` `company_doc`).
- Audit every change in its transaction; emails after commit through `Mail::send` with `company_id`.
- Commits as Jose Ramon Catanjal, no co-author lines. Version 0.8.0.

## Review Focus
- A customer user (not admin) posting directly to the profile routes → `forbidden`.
- Change request with a name already used by another company → refused at submit and again at approval.
- Approving a second permit while the first is approved → old becomes `replaced`, quote gate still satisfied.
- Permit expiring today → treated as expired (blocks quotes).
- Structure changed from sole_prop to corporation (approved) → DTI no longer counts; SEC now required.

---

### Task 1: Data and domain (self-test first)
**Files:** Create `migrations/0008_company_profile.php`, `src/Domain/CompanyProfile.php`; modify `src/Domain/Companies.php` (signup structure + permit expiry; approve() approves the sign-up permit doc), `src/Domain/Quotes.php` (gate after `not_eligible`), `src/Plugin.php` (daily reminders), `src/Cli/SelfTest.php` (`milestoneProfile()` + teardown).

**Interfaces (produces):**
- `CompanyProfile::updateContact(Actor $a, array $in): void` — fields `contact_name`, `contact_email`, `contact_phone`, `website`.
- `CompanyProfile::requestChange(Actor $a, array $in): string` — any of `name`, `tin`, `address`, `structure`; refuses `change_pending`, `company_exists`, `invalid_input` (nothing changed).
- `CompanyProfile::cancelChange(Actor $a, string $changeId): void`; `reviewChange(Actor $a, string $changeId, bool $approve, string $reason = ''): void`.
- `CompanyProfile::uploadDocument(Actor $a, string $kind, ?string $expiresOn, ?array $file): string`; `reviewDocument(Actor $a, string $docId, bool $approve, string $reason = ''): void`.
- `CompanyProfile::documents(Actor $a, string $companyId): array`, `changes(Actor $a, string $companyId): array`, `pending(): array{documents:int, changes:int}`.
- `CompanyProfile::quoteReady(string $companyId): array{ok: bool, missing: list<string>}` — labels "Business permit", "DTI certificate", "SEC registration", "Business structure".
- `CompanyProfile::file(Actor $a, string $docId): array{path, name}` (logs view).
- `CompanyProfile::setRole(Actor $a, int $userId, string $role): void` (`last_admin`).
- `CompanyProfile::remind(): int` — 30 days before expiry, once per document.

- [ ] Step 1: Self-test checks per spec "Self-test first" plus Review Focus lines (names as in spec).
- [ ] Step 2: Deploy, run — Expected: FAIL (`Class CompanyProfile not found` / migration missing).
- [ ] Step 3: Implement migration (incl. copying `permit_file` rows as approved, `expires_on` NULL), domain, gate, reminders, signup/approve changes.
- [ ] Step 4: Deploy, `wp canpas migrate`, selftest — all pass.
- [ ] Step 5: Commit "Company profile rules: contact edits, change approval, registration documents, quotation gate".

### Task 2: REST, screens, file route
**Files:** Create `src/Portal/Screens/CompanyProfileScreen.php`; modify `src/Portal/Nav.php` (`company-profile` page, group "My account", cap `cf_book`), `Pages.php` (route + `companydoc` file), `Http/Rest.php` (profile routes), `CompaniesScreen.php` (documents + change cards, Approvals counts), `PublicPages.php` (signup structure + permit expiry), `OrdersScreen.php` (missing-documents banner instead of the quotation button), CHANGELOG, version 0.8.0.

- [ ] Step 1: Self-test checks: `Opening a company document is logged`; `Another company cannot open a company document` (`not_found`).
- [ ] Step 2: Deploy, run — FAIL.
- [ ] Step 3: Implement routes and screens.
- [ ] Step 4: Deploy, selftest all pass; render profile (customer admin, customer user), staff company page, booking page (eligible without docs → banner) with no PHP warnings; screenshot profile at 390 and 1280.
- [ ] Step 5: Commit "Company profile screens, document review and sign-up structure; v0.8.0".

### Task 3: Final review
- [ ] Fresh reviewer on the whole change; fix Critical/Important with a failing check first; report rulings and deferred minors.
