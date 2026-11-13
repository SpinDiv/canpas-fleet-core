<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Roles\Roles;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Files;
use Canpas\Fleet\Support\Mail;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Actor;

/**
 * A customer company's own profile.
 * - Contact details (contact person, email, phone, website) change straight away; Canpas is told.
 * - Legal details (name, TIN, registered address, business structure) wait for Canpas approval.
 * - Registration documents (business permit; DTI for sole proprietors, SEC for partnerships and
 *   corporations) wait for Canpas review and expire. Special-rate quotations need them complete.
 * Only the company's admin (cf_manage_company_users) edits; Canpas reviews with cf_approve_companies.
 */
final class CompanyProfile
{
    public const STRUCTURES = ['sole_prop' => 'Sole proprietorship', 'partnership' => 'Partnership', 'corporation' => 'Corporation'];
    public const KINDS = ['permit' => 'Business permit', 'dti' => 'DTI certificate', 'sec' => 'SEC registration'];
    public const LEGAL = ['name' => 'Company name', 'tin' => 'TIN', 'address' => 'Registered address', 'structure' => 'Business structure'];
    private const REMIND_DAYS = 30;

    // ----- Contact details (immediate) ----------------------------------------------------------

    /** @param array<string, mixed> $in contact_name, contact_email, contact_phone, website */
    public static function updateContact(Actor $actor, array $in): void
    {
        $companyId = self::ownCompany($actor);
        $c = self::company($companyId);
        $new = [];
        if (array_key_exists('contact_name', $in)) {
            $v = self::clean($in['contact_name'], 191);
            if (mb_strlen($v) < 2) {
                throw new DomainError('invalid_input', 'Enter the contact person’s name.', ['field' => 'contact_name']);
            }
            $new['contact_name'] = $v;
        }
        if (array_key_exists('contact_email', $in)) {
            $v = sanitize_email((string) $in['contact_email']);
            if (!is_email($v)) {
                throw new DomainError('invalid_input', 'Enter a valid email address.', ['field' => 'contact_email']);
            }
            $new['contact_email'] = $v;
        }
        if (array_key_exists('contact_phone', $in)) {
            $v = self::clean($in['contact_phone'], 32);
            if (!preg_match('/^[0-9+()\- ]{7,32}$/', $v)) {
                throw new DomainError('invalid_input', 'Enter a phone number, e.g. 0917 123 4567.', ['field' => 'contact_phone']);
            }
            $new['contact_phone'] = $v;
        }
        if (array_key_exists('website', $in)) {
            $v = trim((string) $in['website']);
            if ($v !== '' && !preg_match('#^https?://#i', $v)) {
                $v = 'https://' . $v;
            }
            if ($v !== '' && (!wp_http_validate_url($v) || !preg_match('#^https?://[^\s/]+\.[^\s/]+#i', $v))) {
                throw new DomainError('invalid_input', 'Enter a web address like www.yourcompany.ph.', ['field' => 'website']);
            }
            $new['website'] = $v !== '' ? esc_url_raw($v) : null;
        }
        $diff = [];
        foreach ($new as $k => $v) {
            if ((string) ($c[$k] ?? '') !== (string) $v) {
                $diff[$k] = ['old' => $c[$k] ?? null, 'new' => $v];
            }
        }
        if ($diff === []) {
            return;
        }
        Db::transaction(static function () use ($actor, $companyId, $new, $diff): void {
            Db::update('companies', $new + ['updated_at' => Db::now()], ['id' => $companyId], ['id']);
            Audit::log($companyId, $actor->userId, 'company.contact_update', 'company', $companyId, $diff);
        });
        $lines = [];
        foreach ($diff as $k => $d) {
            $lines[] = self::label($k) . ': ' . ($d['old'] ?: '—') . ' → ' . ($d['new'] ?: '—');
        }
        self::notifyCanpas("Contact details changed: {$c['name']}", 'Contact details updated', [self::userName($actor->userId) . " updated {$c['name']}’s contact details:", implode("\n", $lines)], $companyId, 'company_contact');
    }

    // ----- Legal details (need approval) --------------------------------------------------------

    /** @param array<string, mixed> $in any of name, tin, address, structure */
    public static function requestChange(Actor $actor, array $in): string
    {
        $companyId = self::ownCompany($actor);
        $c = self::company($companyId);
        if (Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('company_changes') . ' WHERE company_id = UNHEX(%s) AND status = %s', $companyId, 'pending')) > 0) {
            throw new DomainError('change_pending', 'A change is already waiting for Canpas. Cancel it first if you need to correct it.');
        }
        $diff = [];
        foreach (self::LEGAL as $k => $label) {
            if (!array_key_exists($k, $in) || trim((string) $in[$k]) === '') {
                continue;
            }
            $v = self::validLegal($k, $in[$k]);
            if ((string) ($c[$k] ?? '') !== $v) {
                $diff[$k] = ['old' => $c[$k] ?? null, 'new' => $v];
            }
        }
        if ($diff === []) {
            throw new DomainError('invalid_input', 'Nothing has changed.');
        }
        if (isset($diff['name'])) {
            self::assertNameFree($diff['name']['new'], $companyId);
        }
        $id = Uuid::v7();
        Db::transaction(static function () use ($actor, $companyId, $diff, $id): void {
            Db::insert('company_changes', [
                'id' => $id, 'company_id' => $companyId, 'changes_json' => wp_json_encode($diff), 'status' => 'pending',
                'requested_by' => $actor->userId, 'requested_at' => Db::now(),
            ], ['id', 'company_id']);
            Audit::log($companyId, $actor->userId, 'company.change_request', 'company', $companyId, $diff);
        });
        $lines = [];
        foreach ($diff as $k => $d) {
            $lines[] = self::label($k) . ': ' . (self::show($k, $d['old']) ?: '—') . ' → ' . self::show($k, $d['new']);
        }
        self::notifyCanpas("Company change to review: {$c['name']}", 'Company details change to review', [self::userName($actor->userId) . " asked to change {$c['name']}’s registered details:", implode("\n", $lines), 'Check the registration documents, then approve or reject it.'], $companyId, 'company_change');
        return $id;
    }

    public static function cancelChange(Actor $actor, string $changeId): void
    {
        $companyId = self::ownCompany($actor);
        $n = Db::exec(Db::prepare('UPDATE ' . Db::t('company_changes') . ' SET status = %s, reviewed_by = %d, reviewed_at = %s WHERE id = UNHEX(%s) AND company_id = UNHEX(%s) AND status = %s',
            'cancelled', $actor->userId, Db::now(), Uuid::assertHex($changeId, 'change'), $companyId, 'pending'));
        if ($n !== 1) {
            throw new DomainError('not_found', 'That change was not found or is no longer waiting.');
        }
        Audit::log($companyId, $actor->userId, 'company.change_cancel', 'company', $companyId, []);
    }

    public static function reviewChange(Actor $actor, string $changeId, bool $approve, string $reason = ''): void
    {
        $actor->require('cf_approve_companies');
        $reason = self::clean($reason, 255);
        if (!$approve && mb_strlen($reason) < 5) {
            throw new DomainError('reason_required', 'Give a short reason (at least 5 characters). The customer sees it.', ['field' => 'reason']);
        }
        $changeId = Uuid::assertHex($changeId, 'change');
        $companyId = '';
        $diff = [];
        Db::transaction(static function () use ($actor, $changeId, $approve, $reason, &$companyId, &$diff): void {
            $ch = Db::row(Db::prepare('SELECT ' . Db::hex('company_id') . ', changes_json, status FROM ' . Db::t('company_changes') . ' WHERE id = UNHEX(%s) FOR UPDATE', $changeId));
            if ($ch === null) {
                throw new DomainError('not_found', 'That change was not found.');
            }
            if ($ch['status'] !== 'pending') {
                throw new DomainError('wrong_status', 'That change is no longer waiting.');
            }
            $companyId = $ch['company_id'];
            $diff = (array) json_decode((string) $ch['changes_json'], true);
            if ($approve) {
                $set = [];
                foreach ($diff as $k => $d) {
                    if (isset(self::LEGAL[$k])) {
                        $set[$k] = $d['new'];
                    }
                }
                if (isset($set['name'])) {
                    self::assertNameFree((string) $set['name'], $companyId);
                }
                Db::update('companies', $set + ['updated_at' => Db::now()], ['id' => $companyId], ['id']);
            }
            Db::exec(Db::prepare('UPDATE ' . Db::t('company_changes') . ' SET status = %s, reason = %s, reviewed_by = %d, reviewed_at = %s WHERE id = UNHEX(%s)',
                $approve ? 'approved' : 'rejected', $reason !== '' ? $reason : null, $actor->userId, Db::now(), $changeId));
            Audit::log($companyId, $actor->userId, $approve ? 'company.change_approve' : 'company.change_reject', 'company', $companyId, $approve ? $diff : ['reason' => $reason]);
        });
        $c = self::company($companyId);
        $lines = [];
        foreach ($diff as $k => $d) {
            $lines[] = self::label($k) . ': ' . self::show($k, $d['new']);
        }
        self::notifyCustomer($companyId, $approve ? 'Your company details were updated' : 'Your company details change was not approved', $approve ? 'Company details updated' : 'Change not approved',
            $approve ? ['Canpas approved the change to ' . $c['name'] . '’s registered details:', implode("\n", $lines)]
                : ['Canpas could not approve the change to your registered details.', 'Reason: “' . $reason . '”', 'Your current details stay as they are. You can send a corrected change from Company profile.'],
            'company_change');
    }

    // ----- Registration documents ---------------------------------------------------------------

    public static function uploadDocument(Actor $actor, string $kind, ?string $expiresOn, ?array $file): string
    {
        $companyId = self::ownCompany($actor);
        if (!isset(self::KINDS[$kind])) {
            throw new DomainError('invalid_input', 'Choose which document this is.', ['field' => 'kind']);
        }
        $expires = null;
        if ($kind !== 'sec') {
            $expires = trim((string) $expiresOn);
            $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $expires, new \DateTimeZone('Asia/Manila'));
            if ($d === false || $d->format('Y-m-d') !== $expires) {
                throw new DomainError('invalid_input', 'Enter the date the ' . strtolower(self::KINDS[$kind]) . ' expires.', ['field' => 'expires_on']);
            }
            if ($expires <= Db::todayManila()->format('Y-m-d')) {
                throw new DomainError('document_expired', 'This ' . strtolower(self::KINDS[$kind]) . ' has expired. Upload the current one.', ['field' => 'expires_on']);
            }
        }
        if ($file === null || empty($file['tmp_name'])) {
            throw new DomainError('file_missing', 'Attach a photo or PDF of the ' . strtolower(self::KINDS[$kind]) . '.', ['field' => 'file']);
        }
        $stored = Files::storeUpload($file, 'company/' . $companyId);
        $id = Uuid::v7();
        try {
            Db::transaction(static function () use ($actor, $companyId, $kind, $expires, $stored, $id): void {
                Db::insert('company_documents', [
                    'id' => $id, 'company_id' => $companyId, 'kind' => $kind, 'file' => $stored['path'], 'file_name' => $stored['name'],
                    'expires_on' => $expires, 'status' => 'pending', 'uploaded_by' => $actor->userId, 'uploaded_at' => Db::now(),
                ], ['id', 'company_id']);
                Audit::log($companyId, $actor->userId, 'company.document_upload', 'company', $companyId, ['kind' => $kind, 'expires_on' => $expires]);
            });
        } catch (\Throwable $e) {
            Files::delete($stored['path']);
            throw $e;
        }
        $c = self::company($companyId);
        self::notifyCanpas('New ' . strtolower(self::KINDS[$kind]) . " to check: {$c['name']}", 'Registration document to check',
            [self::userName($actor->userId) . " uploaded a {$c['name']} " . strtolower(self::KINDS[$kind]) . ($expires ? ' (expires ' . self::date($expires) . ')' : '') . '.', 'Open it in the portal, compare it with the company details, then approve or reject it.'],
            $companyId, 'company_document');
        return $id;
    }

    /** Called inside sign-up: the permit uploaded with the request, waiting with the company. */
    public static function addSignupPermit(string $companyId, int $userId, array $stored, string $expiresOn): void
    {
        Db::insert('company_documents', [
            'id' => Uuid::v7(), 'company_id' => $companyId, 'kind' => 'permit', 'file' => $stored['path'], 'file_name' => $stored['name'],
            'expires_on' => $expiresOn, 'status' => 'pending', 'uploaded_by' => $userId, 'uploaded_at' => Db::now(),
        ], ['id', 'company_id']);
    }

    /** Called inside company approval: the sign-up permit is approved with the company. */
    public static function approveSignupDocuments(Actor $actor, string $companyId): void
    {
        Db::exec(Db::prepare('UPDATE ' . Db::t('company_documents') . ' SET status = %s, reviewed_by = %d, reviewed_at = %s WHERE company_id = UNHEX(%s) AND status = %s',
            'approved', $actor->userId, Db::now(), $companyId, 'pending'));
    }

    public static function reviewDocument(Actor $actor, string $docId, bool $approve, string $reason = ''): void
    {
        $actor->require('cf_approve_companies');
        $reason = self::clean($reason, 255);
        if (!$approve && mb_strlen($reason) < 5) {
            throw new DomainError('reason_required', 'Give a short reason (at least 5 characters). The customer sees it.', ['field' => 'reason']);
        }
        $docId = Uuid::assertHex($docId, 'document');
        $doc = [];
        Db::transaction(static function () use ($actor, $docId, $approve, $reason, &$doc): void {
            $doc = Db::row(Db::prepare('SELECT ' . Db::hex('company_id') . ', kind, status, expires_on FROM ' . Db::t('company_documents') . ' WHERE id = UNHEX(%s) FOR UPDATE', $docId));
            if ($doc === null) {
                throw new DomainError('not_found', 'That document was not found.');
            }
            if ($doc['status'] !== 'pending') {
                throw new DomainError('wrong_status', 'That document has already been checked.');
            }
            if ($approve) {
                Db::exec(Db::prepare('UPDATE ' . Db::t('company_documents') . ' SET status = %s WHERE company_id = UNHEX(%s) AND kind = %s AND status = %s',
                    'replaced', $doc['company_id'], $doc['kind'], 'approved'));
            }
            Db::exec(Db::prepare('UPDATE ' . Db::t('company_documents') . ' SET status = %s, status_reason = %s, reviewed_by = %d, reviewed_at = %s WHERE id = UNHEX(%s)',
                $approve ? 'approved' : 'rejected', $reason !== '' ? $reason : null, $actor->userId, Db::now(), $docId));
            Audit::log($doc['company_id'], $actor->userId, $approve ? 'company.document_approve' : 'company.document_reject', 'company', $doc['company_id'], ['kind' => $doc['kind'], 'reason' => $reason]);
        });
        $label = self::KINDS[$doc['kind']];
        self::notifyCustomer($doc['company_id'], $approve ? "Your {$label} was approved" : "Your {$label} was not accepted", $approve ? "{$label} approved" : "{$label} not accepted",
            $approve ? ["Canpas checked and approved your {$label}" . ($doc['expires_on'] ? ' (valid until ' . self::date((string) $doc['expires_on']) . ').' : '.')]
                : ["Canpas could not accept the {$label} you uploaded.", 'Reason: “' . $reason . '”', 'Please upload a clear, current copy in Company profile.'],
            'company_document');
    }

    /** @return list<array<string, mixed>> newest first, with uploader names and a computed 'state' */
    public static function documents(Actor $actor, string $companyId): array
    {
        self::assertCanSee($actor, $companyId);
        $rows = Db::rows(Db::prepare('SELECT ' . Db::hex('id') . ', kind, file_name, expires_on, status, status_reason, uploaded_by, uploaded_at, reviewed_at FROM ' . Db::t('company_documents')
            . ' WHERE company_id = UNHEX(%s) ORDER BY uploaded_at DESC', $companyId));
        $today = Db::todayManila()->format('Y-m-d');
        foreach ($rows as &$r) {
            $r['state'] = $r['status'] === 'approved' && $r['expires_on'] !== null && $r['expires_on'] <= $today ? 'expired' : $r['status'];
            $r['by_name'] = self::userName((int) $r['uploaded_by']);
        }
        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public static function changes(Actor $actor, string $companyId): array
    {
        self::assertCanSee($actor, $companyId);
        $rows = Db::rows(Db::prepare('SELECT ' . Db::hex('id') . ', changes_json, status, reason, requested_by, requested_at, reviewed_at FROM ' . Db::t('company_changes')
            . ' WHERE company_id = UNHEX(%s) ORDER BY requested_at DESC', $companyId));
        foreach ($rows as &$r) {
            $r['changes'] = (array) json_decode((string) $r['changes_json'], true);
            $r['by_name'] = self::userName((int) $r['requested_by']);
        }
        return $rows;
    }

    /** @return array{documents: int, changes: int} waiting for Canpas, for the Approvals page */
    public static function pending(): array
    {
        return [
            'documents' => (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('company_documents') . ' d JOIN ' . Db::t('companies') . ' c ON c.id = d.company_id WHERE d.status = %s AND c.status <> %s', 'pending', 'pending')),
            'changes' => (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('company_changes') . ' WHERE status = %s', 'pending')),
        ];
    }

    /**
     * Approved or suspended companies with documents or changes waiting for Canpas, oldest first.
     * @return list<array{id: string, name: string, documents: int, changes: int}>
     */
    public static function pendingCompanies(): array
    {
        $c = Db::t('companies');
        $rows = Db::rows(Db::prepare(
            "SELECT LOWER(HEX(c.id)) AS id, c.name,
                (SELECT COUNT(*) FROM " . Db::t('company_documents') . " d WHERE d.company_id = c.id AND d.status = %s) AS documents,
                (SELECT COUNT(*) FROM " . Db::t('company_changes') . " x WHERE x.company_id = c.id AND x.status = %s) AS changes
             FROM {$c} c WHERE c.status <> %s HAVING documents > 0 OR changes > 0 ORDER BY c.name",
            'pending', 'pending', 'pending'
        ));
        return array_map(static fn($r) => ['id' => (string) $r['id'], 'name' => (string) $r['name'], 'documents' => (int) $r['documents'], 'changes' => (int) $r['changes']], $rows);
    }

    /**
     * Whether the company may request special-rate quotations: a business structure, an approved
     * unexpired business permit, and an approved DTI (sole proprietor, unexpired) or SEC registration.
     *
     * @return array{ok: bool, missing: list<string>}
     */
    public static function quoteReady(string $companyId): array
    {
        $structure = (string) Db::value(Db::prepare('SELECT structure FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $companyId));
        $today = Db::todayManila()->format('Y-m-d');
        $valid = static function (string $kind, bool $expires) use ($companyId, $today): bool {
            $sql = 'SELECT COUNT(*) FROM ' . Db::t('company_documents') . ' WHERE company_id = UNHEX(%s) AND kind = %s AND status = %s';
            $args = [$companyId, $kind, 'approved'];
            if ($expires) {
                $sql .= ' AND expires_on > %s';
                $args[] = $today;
            }
            return (int) Db::value(Db::prepare($sql, ...$args)) > 0;
        };
        $missing = [];
        if (!$valid('permit', true)) {
            $missing[] = self::KINDS['permit'];
        }
        if (!isset(self::STRUCTURES[$structure])) {
            $missing[] = 'Business structure';
        } elseif ($structure === 'sole_prop' ? !$valid('dti', true) : !$valid('sec', false)) {
            $missing[] = $structure === 'sole_prop' ? self::KINDS['dti'] : self::KINDS['sec'];
        }
        return ['ok' => $missing === [], 'missing' => $missing];
    }

    /** @return array{path: string, name: string} the file for /fleet/file; the view is logged */
    public static function file(Actor $actor, string $docId): array
    {
        $doc = Db::row(Db::prepare('SELECT ' . Db::hex('company_id') . ', file, file_name FROM ' . Db::t('company_documents') . ' WHERE id = UNHEX(%s)', Uuid::assertHex($docId, 'document')));
        if ($doc === null || !self::canSee($actor, $doc['company_id'])) {
            throw new DomainError('not_found', 'That document was not found.');
        }
        Documents::logView($actor, 'company_doc', $docId);
        return ['path' => (string) $doc['file'], 'name' => (string) ($doc['file_name'] ?: 'document')];
    }

    // ----- Company admins -----------------------------------------------------------------------

    public static function setRole(Actor $actor, int $userId, string $role): void
    {
        $companyId = self::ownCompany($actor);
        if (!in_array($role, [Roles::CUSTOMER_ADMIN, Roles::CUSTOMER_USER], true)) {
            throw new DomainError('invalid_input', 'Choose admin or user.');
        }
        Db::transaction(static function () use ($actor, $companyId, $userId, $role): void {
            $m = Db::row(Db::prepare('SELECT role FROM ' . Db::t('memberships') . ' WHERE user_id = %d AND company_id = UNHEX(%s) AND status = %s FOR UPDATE', $userId, $companyId, 'active'));
            if ($m === null) {
                throw new DomainError('not_found', 'That person is not in your company.');
            }
            if ($m['role'] === Roles::CUSTOMER_ADMIN && $role === Roles::CUSTOMER_USER) {
                $admins = (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('memberships') . ' WHERE company_id = UNHEX(%s) AND role = %s AND status = %s', $companyId, Roles::CUSTOMER_ADMIN, 'active'));
                if ($admins <= 1) {
                    throw new DomainError('last_admin', 'Your company needs at least one admin. Make someone else an admin first.');
                }
            }
            Db::exec(Db::prepare('UPDATE ' . Db::t('memberships') . ' SET role = %s, updated_at = %s WHERE user_id = %d AND company_id = UNHEX(%s)', $role, Db::now(), $userId, $companyId));
            Audit::log($companyId, $actor->userId, 'company.role_change', 'user', null, ['user_id' => $userId, 'role' => $role]);
        });
        (new \WP_User($userId))->set_role($role);
    }

    // ----- Daily reminders ----------------------------------------------------------------------

    /** Email the company admins and Canpas once, 30 days before a permit or DTI expires. */
    public static function remind(): int
    {
        $soon = Db::todayManila()->modify('+' . self::REMIND_DAYS . ' days')->format('Y-m-d');
        $rows = Db::rows(Db::prepare('SELECT ' . Db::hex('id') . ', ' . Db::hex('company_id') . ', kind, expires_on FROM ' . Db::t('company_documents')
            . ' WHERE status = %s AND expires_on IS NOT NULL AND expires_on <= %s AND reminded_at IS NULL', 'approved', $soon));
        $n = 0;
        foreach ($rows as $r) {
            if (Db::exec(Db::prepare('UPDATE ' . Db::t('company_documents') . ' SET reminded_at = %s WHERE id = UNHEX(%s) AND reminded_at IS NULL', Db::now(), $r['id'])) !== 1) {
                continue;
            }
            $n++;
            try {
                $label = self::KINDS[$r['kind']];
                $c = self::company($r['company_id']);
                $paras = ["{$c['name']}’s {$label} expires on " . self::date((string) $r['expires_on']) . '.', 'Upload the renewed ' . strtolower($label) . ' in Company profile so special-rate quotations stay available.'];
                self::notifyCustomer($r['company_id'], "Your {$label} expires soon", "{$label} expires soon", $paras, 'company_reminder');
                self::notifyCanpas("{$c['name']}: {$label} expires " . self::date((string) $r['expires_on']), "{$label} expires soon", $paras, $r['company_id'], 'company_reminder');
            } catch (\Throwable $e) {
                error_log('Canpas document reminder failed: ' . $e->getMessage());
            }
        }
        return $n;
    }

    // ----- Helpers ------------------------------------------------------------------------------

    public static function validLegal(string $k, mixed $raw): string
    {
        $v = self::clean($raw, 255);
        switch ($k) {
            case 'name':
                if (mb_strlen($v) < 2 || mb_strlen($v) > 191) {
                    throw new DomainError('invalid_input', 'Company name is required (2–191 characters).', ['field' => 'name']);
                }
                return $v;
            case 'tin':
                if (!preg_match('/^[0-9\- ]{9,20}$/', $v)) {
                    throw new DomainError('invalid_input', 'TIN should contain digits and dashes only, e.g. 123-456-789-000.', ['field' => 'tin']);
                }
                return $v;
            case 'address':
                if (mb_strlen($v) < 5) {
                    throw new DomainError('invalid_input', 'Enter the registered address.', ['field' => 'address']);
                }
                return $v;
            case 'structure':
                if (!isset(self::STRUCTURES[$v])) {
                    throw new DomainError('invalid_input', 'Choose the business structure.', ['field' => 'structure']);
                }
                return $v;
        }
        throw new DomainError('invalid_input', 'Unknown field.');
    }

    private static function assertNameFree(string $name, string $companyId): void
    {
        if ((int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('companies') . ' WHERE name = %s AND id <> UNHEX(%s)', $name, $companyId)) > 0) {
            throw new DomainError('company_exists', 'Another company already uses that name.', ['field' => 'name']);
        }
    }

    private static function ownCompany(Actor $actor): string
    {
        $actor->require('cf_manage_company_users');
        if ($actor->companyId === null) {
            throw new DomainError('forbidden', 'Only a company admin can change the company profile.');
        }
        return $actor->companyId;
    }

    private static function canSee(Actor $actor, string $companyId): bool
    {
        return $actor->can('cf_approve_companies') || $actor->can('cf_manage_orders') || ($actor->companyId !== null && $actor->companyId === $companyId);
    }

    private static function assertCanSee(Actor $actor, string $companyId): void
    {
        if (!self::canSee($actor, $companyId)) {
            throw new DomainError('not_found', 'Company not found.');
        }
    }

    /** @return array<string, mixed> */
    public static function company(string $companyId): array
    {
        $c = Db::row(Db::prepare('SELECT ' . Db::hex('id') . ', name, tin, address, structure, contact_name, contact_email, contact_phone, website, status, special_rates FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $companyId));
        if ($c === null) {
            throw new DomainError('not_found', 'Company not found.');
        }
        return $c;
    }

    private static function label(string $k): string
    {
        return self::LEGAL[$k] ?? ['contact_name' => 'Contact person', 'contact_email' => 'Contact email', 'contact_phone' => 'Phone', 'website' => 'Website'][$k] ?? $k;
    }

    public static function show(string $k, mixed $v): string
    {
        return $k === 'structure' ? (self::STRUCTURES[(string) $v] ?? (string) $v) : (string) $v;
    }

    private static function clean(mixed $v, int $max): string
    {
        return mb_substr(sanitize_text_field(wp_unslash((string) $v)), 0, $max);
    }

    private static function date(string $ymd): string
    {
        return (new \DateTimeImmutable($ymd))->format('j M Y');
    }

    private static function userName(int $userId): string
    {
        $u = $userId > 0 ? get_userdata($userId) : false;
        return $u ? $u->display_name : 'Someone';
    }

    /** @param list<string> $paras */
    private static function notifyCanpas(string $subject, string $title, array $paras, string $companyId, string $kind): void
    {
        try {
            Mail::send((string) Settings::get('notify_email'), $subject, $paras, ['label' => 'Open the company', 'href' => add_query_arg('id', $companyId, Router::url('companies'))], ['title' => $title, 'company_id' => $companyId, 'kind' => $kind]);
        } catch (\Throwable $e) {
            error_log('Canpas mail failed: ' . $e->getMessage());
        }
    }

    /** @param list<string> $paras */
    private static function notifyCustomer(string $companyId, string $subject, string $title, array $paras, string $kind): void
    {
        try {
            foreach (Db::rows(Db::prepare('SELECT user_id FROM ' . Db::t('memberships') . ' WHERE company_id = UNHEX(%s) AND role = %s AND status = %s', $companyId, Roles::CUSTOMER_ADMIN, 'active')) as $m) {
                $u = get_userdata((int) $m['user_id']);
                if ($u) {
                    Mail::send($u->user_email, $subject, $paras, ['label' => 'Open Company profile', 'href' => Router::url('company-profile')], ['title' => $title, 'company_id' => $companyId, 'kind' => $kind]);
                }
            }
        } catch (\Throwable $e) {
            error_log('Canpas mail failed: ' . $e->getMessage());
        }
    }
}
