# Company profile, registration documents and change approval — design

Agreed with Ramon, 2026-11-13.

## Why
Customers cannot update anything about their company today (only colleagues and their own password).
Contractors change contact people, phones and offices, and their permits expire every year. Canpas
needs current, checked registration documents before it offers special rates, and must never put an
unchecked name or TIN on a quotation, bill or agreement.

## Goals
- A **Company profile** page for the customer's company admin.
- **Contact details change immediately**: contact person, contact email, contact phone, website (new).
  Audited; Canpas is emailed what changed (old → new).
- **Legal details need Canpas approval**: company name, TIN, registered address, business structure.
  Submitted as a change request; the approved details stay in use until the admin approves.
- **Registration documents** with Canpas review and expiry:
  - Business / Mayor's permit — everyone; expiry date required.
  - DTI certificate — sole proprietorship; expiry date required.
  - SEC registration — corporation or partnership; no expiry.
- **Business structure**: sole proprietorship | partnership | corporation, asked at sign-up and in the profile.
- **Request a quotation is blocked** unless the company has an approved, unexpired business permit
  AND an approved DTI (sole prop) or SEC (partnership/corporation) registration. Normal booking stays open.
- The company admin can make a colleague a company admin (at least one admin must remain).

## Non-goals
- BIR 2303, IDs of the authorised person, secretary's certificates.
- Blocking normal bookings on documents.
- Re-issuing past bills or agreements with new company details.

## Anti-goals
- An unchecked name/TIN/address appearing on any quotation, bill or agreement.
- A customer losing access to booking because a permit lapsed.
- Company documents being deleted by the ID-document retention purge.

## Approaches considered
1. **Separate company-documents table + change-request table (picked).** Full history, review status,
   approved details always used; independent of the ID-document retention rule.
2. Reuse the Milestone 3 ID/licence documents system — rejected: built around people and orders, and
   its retention purge would delete company permits unless exceptions are added everywhere.
3. Extra columns on companies — rejected: no history of past permits or approvals.

## Design
### Data (migration 0008_company_profile)
- `companies.website VARCHAR(191) NULL`, `companies.structure VARCHAR(16) NULL` (sole_prop | partnership | corporation).
- `company_documents`: id, company_id (FK), kind (permit | dti | sec), file, file_name, expires_on DATE NULL,
  status (pending | approved | rejected | replaced), status_reason, uploaded_by, uploaded_at, reviewed_by, reviewed_at.
- `company_changes`: id, company_id (FK), changes_json (field → {old, new}), status (pending | approved |
  rejected | cancelled), reason, requested_by, requested_at, reviewed_by, reviewed_at.
- Existing `companies.permit_file` is copied into `company_documents` as kind `permit`, status `approved`,
  `expires_on` NULL, so it stays on record. *(builder's call)* A permit without an expiry date does not satisfy
  the quotation requirement — the customer is asked to upload the current permit with its expiry date.

### Rules
- Only the customer's **company admin** edits; company users see the profile read-only. Staff never edit customer
  contact details here. Company always from membership.
- Contact edits: validate email/phone/URL; audited `company.contact_update` with old/new; email to Canpas notify address.
- Change request: one pending at a time per company *(builder's call)*; the admin may cancel it before review.
  Name must stay unique (checked again on approval). On approval the company row is updated in one transaction,
  audited, and the customer admins are emailed; on rejection a reason (≥5 chars) is required and emailed.
- Documents: image or PDF, stored in `CANPAS_FLEET_PRIVATE_DIR/company/<company>/`, served only via
  `/fleet/file?companydoc=<id>` with a permission check and a view log. Permit and DTI need an expiry date in the
  future. A new upload of the same kind sits as `pending` beside the current approved one; when Canpas approves it,
  the previous approved document becomes `replaced`. Rejection needs a reason and is emailed.
- Quotation gate: `Companies::quoteReady(companyId): array{ok: bool, missing: list<string>}`; `Quotes::request`
  refuses with `documents_missing` listing what is missing; the booking pages show the message and a link to
  Company profile instead of the **Request a quotation** button.
- Reminders: the daily job emails the company admins and Canpas 30 days before a permit or DTI expires
  *(builder's call: 30 days, once)*, recorded in the email log.
- Sign-up: adds business structure (required) and keeps the business permit upload (required, now with its
  expiry date); DTI/SEC upload is optional at sign-up *(builder's call)* and asked for in the profile.
- Sign-up approval: when Canpas approves a new company, the permit uploaded at sign-up is approved with it
  (Canpas already checks it in that decision). *(builder's call)*
- The quotation gate is **in addition to** the "Special rates" switch: `Quotes::request` checks the switch first
  (`not_eligible`), then the documents (`documents_missing`). Only switched-on companies see the checklist banner
  and link. The admin may switch special rates on before the documents are complete. *(builder's call)*
- For the staff review screens use `cf_approve_companies` (cf_admin only). *(builder's call)*
- Promote colleague: `Companies::setRole(Actor, userId, role)` for company admins within their own company; refuses
  removing the last admin.

### Screens
- Customer: **Company profile** page (group "My account"): details card (legal details with "Waiting for Canpas
  review" if a change is pending, plus "Request a change"), contact form, documents card (status, expiry, upload new),
  and a checklist banner "Complete to request special rates".
- Staff: the company page gains "Registration documents" (open, approve, reject) and "Change requests" (old → new,
  approve, reject). The Approvals page lists pending documents and change requests with counts.

### Self-test first (CLAUDE.md)
Only company admins edit; contact edits apply and are audited; legal edits wait and do not change the company until
approved; duplicate name refused on approval; documents need expiry (permit/DTI) and land as pending; approval
replaces the previous one; quotation refused with `documents_missing` until permit + DTI/SEC approved and unexpired;
expired permit blocks again; corporation needs SEC not DTI; another company cannot open or act on the documents;
viewing a document is logged; cannot remove the last company admin.

## Left to the builder
Wording, layout, email text, exact reminder timing within the day.
