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
 * Customer companies: sign-up, approval, decline, suspension, and the people who can book for them.
 *
 * Status flow: pending -> approved | declined;  approved <-> suspended.
 */
final class Companies
{
    public const STATUSES = ['pending', 'approved', 'declined', 'suspended'];

    /**
     * Public sign-up: creates a pending company and its first login (awaiting approval).
     *
     * @param array<string, mixed> $in company_name, tin, address, contact_name, email, phone, password
     * @param array<string, mixed>|null $permit one $_FILES entry (business permit or DTI/SEC registration)
     * @return array{company_id:string, user_id:int}
     */
    public static function signup(array $in, ?array $permit): array
    {
        self::throttle();
        $name = self::text($in, 'company_name', 'Company name', 2, 191);
        $tin = self::text($in, 'tin', 'TIN', 9, 20);
        if (!preg_match('/^[0-9\- ]{9,20}$/', $tin)) {
            throw new DomainError('invalid_input', 'TIN should contain digits and dashes only, e.g. 123-456-789-000.', ['field' => 'tin']);
        }
        $address = self::text($in, 'address', 'Company address', 5, 255);
        $contact = self::text($in, 'contact_name', 'Contact person', 2, 191);
        $phone = self::text($in, 'phone', 'Mobile number', 7, 32);
        $email = sanitize_email((string) ($in['email'] ?? ''));
        if (!is_email($email)) {
            throw new DomainError('invalid_input', 'Enter a valid email address.', ['field' => 'email']);
        }
        if (email_exists($email)) {
            throw new DomainError('email_taken', 'An account with this email already exists. Sign in instead, or use another email.', ['field' => 'email']);
        }
        $password = (string) ($in['password'] ?? '');
        if (strlen($password) < 10) {
            throw new DomainError('weak_password', 'Use a password of at least 10 characters.', ['field' => 'password']);
        }
        if (Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('companies') . ' WHERE name = %s', $name)) > 0) {
            throw new DomainError('company_exists', 'This company already has an account. Ask your company admin to add you.', ['field' => 'company_name']);
        }
        $structure = (string) ($in['structure'] ?? '');
        if (!isset(CompanyProfile::STRUCTURES[$structure])) {
            throw new DomainError('invalid_input', 'Choose your business structure.', ['field' => 'structure']);
        }
        $permitExpires = trim((string) ($in['permit_expires'] ?? ''));
        $pe = \DateTimeImmutable::createFromFormat('!Y-m-d', $permitExpires);
        if ($pe === false || $pe->format('Y-m-d') !== $permitExpires) {
            throw new DomainError('invalid_input', 'Enter the date your business permit expires.', ['field' => 'permit_expires']);
        }
        if ($permitExpires <= Db::todayManila()->format('Y-m-d')) {
            throw new DomainError('document_expired', 'This business permit has expired. Attach the current one.', ['field' => 'permit_expires']);
        }
        if ($permit === null) {
            throw new DomainError('file_missing', 'Attach your business permit.', ['field' => 'permit']);
        }

        $companyId = Uuid::v7();
        $file = Files::storeUpload($permit, 'companies/' . $companyId);
        $userId = 0;
        try {
            $userId = (int) wp_insert_user([
                'user_login' => self::login($email),
                'user_email' => $email,
                'user_pass' => $password,
                'display_name' => $contact,
                'first_name' => explode(' ', $contact)[0],
                'role' => Roles::PENDING,
            ]);
            if ($userId <= 0) {
                throw new DomainError('user_create_failed', 'The account could not be created. Please try again.');
            }
            Db::transaction(static function () use ($companyId, $name, $tin, $address, $contact, $email, $phone, $file, $userId, $structure, $permitExpires): void {
                Db::insert('companies', [
                    'id' => $companyId, 'name' => $name, 'tin' => $tin, 'address' => $address, 'structure' => $structure,
                    'contact_name' => $contact, 'contact_email' => $email, 'contact_phone' => $phone,
                    'status' => 'pending', 'permit_file' => $file['path'], 'permit_name' => $file['name'],
                    'created_by' => $userId, 'created_at' => Db::now(),
                ], ['id']);
                Db::insert('memberships', [
                    'id' => Uuid::v7(), 'user_id' => $userId, 'company_id' => $companyId,
                    'role' => Roles::PENDING, 'status' => 'active', 'created_at' => Db::now(),
                ], ['id', 'company_id']);
                CompanyProfile::addSignupPermit($companyId, $userId, $file, $permitExpires);
                Audit::log($companyId, $userId, 'company.signup', 'company', $companyId);
            });
        } catch (\Throwable $e) {
            Files::delete($file['path']);
            if ($userId > 0) {
                require_once ABSPATH . 'wp-admin/includes/user.php';
                wp_delete_user($userId);
            }
            throw $e;
        }

        Mail::send((string) Settings::get('notify_email'), "New company sign-up: {$name}", [
            "{$name} has asked for a Canpas account.",
            "Contact: {$contact}, {$phone}.",
            'Review the details and business permit, then approve or decline.',
        ], ['label' => 'Open approvals', 'href' => Router::url('approvals')]);
        Mail::send($email, 'We received your Canpas account request', [
            "Hello {$contact},",
            "Thank you for registering {$name}. Canpas will review your details and business permit, usually within one working day.",
            'We will email you as soon as your account is approved and you can book.',
        ]);

        return ['company_id' => $companyId, 'user_id' => $userId];
    }

    public static function approve(Actor $actor, string $companyId): void
    {
        $actor->require('cf_approve_companies');
        $c = self::row($companyId);
        if ($c['status'] !== 'pending') {
            throw new DomainError('wrong_status', 'Only pending companies can be approved.');
        }
        $users = [];
        Db::transaction(static function () use ($actor, $companyId, &$users): void {
            Db::exec(Db::prepare(
                'UPDATE ' . Db::t('companies') . ' SET status = %s, status_reason = NULL, approved_by = %d, approved_at = %s, updated_at = %s WHERE id = UNHEX(%s) AND status = %s',
                'approved', $actor->userId, Db::now(), Db::now(), $companyId, 'pending'
            ));
            $users = array_map('intval', array_column(Db::rows(Db::prepare(
                'SELECT user_id FROM ' . Db::t('memberships') . ' WHERE company_id = UNHEX(%s) AND role = %s', $companyId, Roles::PENDING
            )), 'user_id'));
            Db::exec(Db::prepare(
                'UPDATE ' . Db::t('memberships') . ' SET role = %s, updated_at = %s WHERE company_id = UNHEX(%s) AND role = %s',
                Roles::CUSTOMER_ADMIN, Db::now(), $companyId, Roles::PENDING
            ));
            CompanyProfile::approveSignupDocuments($actor, $companyId);
            Audit::log($companyId, $actor->userId, 'company.approve', 'company', $companyId);
        });
        foreach ($users as $uid) {
            (new \WP_User($uid))->set_role(Roles::CUSTOMER_ADMIN);
        }
        Mail::send((string) $c['contact_email'], 'Your Canpas account is approved', [
            "Hello {$c['contact_name']},",
            "{$c['name']} can now book trucks and rent equipment from Canpas.",
            'As company admin you can also add the other people in your company who will book.',
        ], ['label' => 'Sign in', 'href' => Router::url('login')]);
    }

    public static function decline(Actor $actor, string $companyId, string $reason): void
    {
        $actor->require('cf_approve_companies');
        $reason = self::reason($reason);
        $c = self::row($companyId);
        if ($c['status'] !== 'pending') {
            throw new DomainError('wrong_status', 'Only pending companies can be declined.');
        }
        Db::transaction(static function () use ($actor, $companyId, $reason): void {
            self::setStatus($companyId, 'declined', $reason);
            Db::exec(Db::prepare('UPDATE ' . Db::t('company_documents') . ' SET status = %s, status_reason = %s, reviewed_by = %d, reviewed_at = %s WHERE company_id = UNHEX(%s) AND status = %s',
                'rejected', $reason, $actor->userId, Db::now(), $companyId, 'pending'));
            Db::exec(Db::prepare('UPDATE ' . Db::t('memberships') . ' SET status = %s, updated_at = %s WHERE company_id = UNHEX(%s)', 'inactive', Db::now(), $companyId));
            Audit::log($companyId, $actor->userId, 'company.decline', 'company', $companyId);
        });
        Mail::send((string) $c['contact_email'], 'About your Canpas account request', [
            "Hello {$c['contact_name']},",
            "We could not approve the account for {$c['name']}.",
            "Reason: {$reason}",
            'Please contact Canpas if you have questions or would like to apply again.',
        ]);
    }

    public static function suspend(Actor $actor, string $companyId, string $reason): void
    {
        $actor->require('cf_approve_companies');
        $reason = self::reason($reason);
        if (self::row($companyId)['status'] !== 'approved') {
            throw new DomainError('wrong_status', 'Only approved companies can be suspended.');
        }
        Db::transaction(static function () use ($actor, $companyId, $reason): void {
            self::setStatus($companyId, 'suspended', $reason);
            Audit::log($companyId, $actor->userId, 'company.suspend', 'company', $companyId);
        });
    }

    public static function reactivate(Actor $actor, string $companyId): void
    {
        $actor->require('cf_approve_companies');
        if (self::row($companyId)['status'] !== 'suspended') {
            throw new DomainError('wrong_status', 'Only suspended companies can be reactivated.');
        }
        Db::transaction(static function () use ($actor, $companyId): void {
            self::setStatus($companyId, 'approved', null);
            Audit::log($companyId, $actor->userId, 'company.reactivate', 'company', $companyId);
        });
    }

    /** @return list<array<string, mixed>> */
    /** The Canpas admin lets a company request special-rate quotations (or stops it). */
    public static function setSpecialRates(Actor $actor, string $companyId, bool $on): void
    {
        $actor->require('cf_manage_rates');
        $companyId = Uuid::assertHex($companyId, 'company');
        if ($on && Db::value(Db::prepare('SELECT status FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $companyId)) !== 'approved') {
            throw new DomainError('wrong_status', 'Only approved companies can be allowed special rates.');
        }
        Db::transaction(static function () use ($actor, $companyId, $on): void {
            if (Db::update('companies', ['special_rates' => $on ? 1 : 0], ['id' => $companyId], ['id']) === 0
                && Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $companyId)) == 0) {
                throw new DomainError('not_found', 'Company not found.');
            }
            Audit::log($companyId, $actor->userId, 'company.special_rates', 'company', $companyId, ['on' => $on ? 1 : 0]);
        });
    }

    public static function specialRates(string $companyId): bool
    {
        return (int) Db::value(Db::prepare('SELECT special_rates FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $companyId)) === 1;
    }

    public static function list(Actor $actor, ?string $status = null): array
    {
        $actor->require('cf_manage_companies');
        $where = '';
        if ($status !== null && $status !== '') {
            if (!in_array($status, self::STATUSES, true)) {
                throw new DomainError('invalid_input', 'Unknown status.');
            }
            $where = Db::prepare(' WHERE c.status = %s', $status);
        }
        return Db::rows('SELECT ' . Db::hex('c.id', 'id') . ', c.name, c.tin, c.contact_name, c.contact_email, c.contact_phone, c.status, c.status_reason, c.created_at,
                (SELECT COUNT(*) FROM ' . Db::t('memberships') . ' m WHERE m.company_id = c.id AND m.status = \'active\') AS users
            FROM ' . Db::t('companies') . ' c' . $where . ' ORDER BY FIELD(c.status, \'pending\', \'suspended\', \'approved\', \'declined\'), c.created_at DESC');
    }

    /** One company: staff may read any; customers only their own. */
    public static function get(Actor $actor, string $companyId): array
    {
        $companyId = Uuid::assertHex($companyId, 'company');
        if (!$actor->can('cf_manage_companies') && $actor->companyId !== $companyId) {
            throw new DomainError('forbidden', 'You do not have permission to do this.');
        }
        $c = self::row($companyId);
        unset($c['permit_file']);
        $c['has_permit'] = !empty(self::row($companyId)['permit_file']);
        return $c;
    }

    /** @return list<array<string, mixed>> people who can sign in for the company */
    public static function users(Actor $actor, ?string $companyId = null): array
    {
        $companyId ??= $actor->companyId;
        if ($companyId === null) {
            throw new DomainError('invalid_input', 'Choose a company.');
        }
        $companyId = Uuid::assertHex($companyId, 'company');
        $own = $actor->companyId === $companyId && $actor->can('cf_manage_company_users');
        if (!$own && !$actor->can('cf_manage_companies')) {
            throw new DomainError('forbidden', 'You do not have permission to do this.');
        }
        $rows = Db::rows(Db::prepare(
            'SELECT m.user_id, m.role, m.status, m.created_at FROM ' . Db::t('memberships') . ' m WHERE m.company_id = UNHEX(%s) ORDER BY m.status, m.created_at',
            $companyId
        ));
        foreach ($rows as &$r) {
            $u = get_userdata((int) $r['user_id']);
            $r['user_id'] = (int) $r['user_id'];
            $r['name'] = $u ? $u->display_name : '(deleted)';
            $r['email'] = $u ? $u->user_email : '';
            $r['role_label'] = str_replace('Canpas: ', '', Roles::LABELS[$r['role']] ?? $r['role']);
        }
        return $rows;
    }

    /** A customer admin adds a colleague to their own company. The colleague gets a set-password email. */
    public static function addUser(Actor $actor, string $name, string $email, string $role): int
    {
        $actor->require('cf_manage_company_users');
        if (!in_array($role, [Roles::CUSTOMER_ADMIN, Roles::CUSTOMER_USER], true)) {
            throw new DomainError('invalid_input', 'Choose company admin or company user.', ['field' => 'role']);
        }
        $name = self::text(['name' => $name], 'name', 'Name', 2, 191);
        $email = sanitize_email($email);
        if (!is_email($email)) {
            throw new DomainError('invalid_input', 'Enter a valid email address.', ['field' => 'email']);
        }
        if (email_exists($email)) {
            throw new DomainError('email_taken', 'This email already has a Canpas account.', ['field' => 'email']);
        }
        $companyId = (string) $actor->companyId;
        $uid = (int) wp_insert_user([
            'user_login' => self::login($email), 'user_email' => $email, 'display_name' => $name,
            'first_name' => explode(' ', $name)[0], 'user_pass' => wp_generate_password(32, true, true), 'role' => $role,
        ]);
        if ($uid <= 0) {
            throw new DomainError('user_create_failed', 'The account could not be created. Please try again.');
        }
        Db::transaction(static function () use ($actor, $uid, $companyId, $role): void {
            Db::insert('memberships', [
                'id' => Uuid::v7(), 'user_id' => $uid, 'company_id' => $companyId,
                'role' => $role, 'status' => 'active', 'created_at' => Db::now(),
            ], ['id', 'company_id']);
            Audit::log($companyId, $actor->userId, 'company.user_add', 'user', null, ['user_id' => $uid, 'role' => $role]);
        });
        $user = get_userdata($uid);
        $key = $user ? get_password_reset_key($user) : '';
        if ($user && is_string($key)) {
            $company = self::row($companyId)['name'];
            $link = network_site_url('wp-login.php?action=rp&key=' . rawurlencode($key) . '&login=' . rawurlencode($user->user_login), 'login');
            Mail::send($email, "You've been added to {$company} on Canpas", [
                "Hello {$name},",
                wp_get_current_user()->display_name . " added you to {$company}'s account on the Canpas Fleet & Equipment Portal.",
                'Set your password to sign in. The link works for 24 hours.',
            ], ['label' => 'Set my password', 'href' => $link]);
        }
        return $uid;
    }

    public static function deactivateUser(Actor $actor, int $userId): void
    {
        $actor->require('cf_manage_company_users');
        if ($userId === $actor->userId) {
            throw new DomainError('cannot_remove_self', 'You cannot remove your own access. Ask another company admin.');
        }
        $m = Db::row(Db::prepare('SELECT ' . Db::hex('company_id') . ', status FROM ' . Db::t('memberships') . ' WHERE user_id = %d', $userId));
        if ($m === null || $m['company_id'] !== $actor->companyId) {
            throw new DomainError('not_found', 'This person is not in your company.');
        }
        Db::transaction(static function () use ($actor, $userId): void {
            Db::exec(Db::prepare('UPDATE ' . Db::t('memberships') . ' SET status = %s, updated_at = %s WHERE user_id = %d', 'inactive', Db::now(), $userId));
            Audit::log($actor->companyId, $actor->userId, 'company.user_remove', 'user', null, ['user_id' => $userId]);
        });
        if (class_exists('WP_Session_Tokens')) {
            \WP_Session_Tokens::get_instance($userId)->destroy_all();
        }
    }

    /** @return array{path:string,name:string} the permit file, after checking access and logging the view */
    public static function permitFile(Actor $actor, string $companyId): array
    {
        $companyId = Uuid::assertHex($companyId, 'company');
        $own = $actor->companyId === $companyId && $actor->can('cf_manage_company_users');
        if (!$own && !$actor->can('cf_manage_companies')) {
            throw new DomainError('forbidden', 'You do not have permission to do this.');
        }
        $c = self::row($companyId);
        if (empty($c['permit_file'])) {
            throw new DomainError('not_found', 'No business permit on file.');
        }
        Audit::log($companyId, $actor->userId, 'company.permit_viewed', 'company', $companyId);
        return ['path' => (string) $c['permit_file'], 'name' => (string) ($c['permit_name'] ?: 'permit')];
    }

    /** Number of people who can currently sign in for a company. */
    public static function activeUsers(string $companyId): int
    {
        return (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('memberships') . ' WHERE company_id = UNHEX(%s) AND status = %s', Uuid::assertHex($companyId), 'active'));
    }

    /** @return array<string, int> company counts by status */
    public static function counts(): array
    {
        $out = array_fill_keys(self::STATUSES, 0);
        foreach (Db::rows('SELECT status, COUNT(*) AS n FROM ' . Db::t('companies') . ' GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private static function row(string $companyId): array
    {
        $companyId = Uuid::assertHex($companyId, 'company');
        $c = Db::row(Db::prepare('SELECT ' . Db::hex('id') . ', name, tin, address, contact_name, contact_email, contact_phone, status, status_reason, billing_cycle, permit_file, permit_name, approved_at, created_at FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $companyId));
        if ($c === null) {
            throw new DomainError('not_found', 'Company not found.');
        }
        return $c;
    }

    private static function setStatus(string $companyId, string $status, ?string $reason): void
    {
        Db::exec(Db::prepare(
            'UPDATE ' . Db::t('companies') . ' SET status = %s, status_reason = ' . ($reason === null ? 'NULL' : '%s') . ', updated_at = %s WHERE id = UNHEX(%s)',
            ...array_values(array_filter([$status, $reason, Db::now(), $companyId], static fn($v) => $v !== null))
        ));
    }

    private static function reason(string $reason): string
    {
        $reason = sanitize_text_field(wp_unslash($reason));
        if (mb_strlen($reason) < 5) {
            throw new DomainError('reason_required', 'Give a short reason (at least 5 characters).', ['field' => 'reason']);
        }
        return mb_substr($reason, 0, 255);
    }

    /** @param array<string, mixed> $in */
    private static function text(array $in, string $key, string $label, int $min, int $max): string
    {
        $v = sanitize_text_field(wp_unslash((string) ($in[$key] ?? '')));
        if (mb_strlen($v) < $min || mb_strlen($v) > $max) {
            throw new DomainError('invalid_input', "{$label} is required ({$min}–{$max} characters).", ['field' => $key]);
        }
        return $v;
    }

    private static function login(string $email): string
    {
        $base = sanitize_user(strstr($email, '@', true) ?: 'user', true) ?: 'user';
        $login = substr($base, 0, 40);
        while (username_exists($login)) {
            $login = substr($base, 0, 40) . '_' . strtolower(wp_generate_password(4, false));
        }
        return $login;
    }

    /** Max 5 sign-ups per hour from one network address. */
    private static function throttle(): void
    {
        if (defined('WP_CLI') && WP_CLI) {
            return;
        }
        $key = 'cf_signup_' . substr(hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . wp_salt('nonce')), 0, 24);
        $n = (int) get_transient($key);
        if ($n >= 5) {
            throw new DomainError('rate_limited', 'Too many sign-ups from your network. Please try again in an hour.');
        }
        set_transient($key, $n + 1, HOUR_IN_SECONDS);
    }
}
