<?php

declare(strict_types=1);

namespace Canpas\Fleet\Tenancy;

use Canpas\Fleet\Roles\Roles;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;

/**
 * Who is acting, with which role, for which customer company. Every service call takes one.
 *
 * The company always comes from the user's membership row, never from a request parameter.
 * A capability counts only if BOTH the WordPress user has it AND the membership role grants it.
 * Staff have no company; customers always have one.
 */
final class Actor
{
    public function __construct(
        public readonly int $userId,
        public readonly string $role,
        public readonly ?string $companyId = null,
        public readonly ?string $companyStatus = null,
    ) {
    }

    public static function forUser(int $userId): self
    {
        $r = Db::row(Db::prepare(
            'SELECT m.role, m.status, ' . Db::hex('m.company_id', 'company_id') . ', c.status AS company_status
             FROM ' . Db::t('memberships') . ' m LEFT JOIN ' . Db::t('companies') . ' c ON c.id = m.company_id
             WHERE m.user_id = %d',
            $userId
        ));
        if ($r === null || $r['status'] !== 'active') {
            throw new DomainError('no_membership', 'This account is not set up for the Canpas portal.');
        }
        $company = $r['company_id'] ?: null;
        if (Roles::isStaff($r['role']) && $company !== null) {
            throw new DomainError('bad_membership', 'Staff accounts cannot belong to a customer company.');
        }
        if (!Roles::isStaff($r['role']) && $company === null) {
            throw new DomainError('bad_membership', 'Customer accounts must belong to a company.');
        }
        return new self($userId, $r['role'], $company, $r['company_status'] ?? null);
    }

    public static function current(): self
    {
        $uid = get_current_user_id();
        if ($uid === 0) {
            throw new DomainError('not_logged_in', 'Please sign in.');
        }
        return self::forUser($uid);
    }

    public function isStaff(): bool
    {
        return Roles::isStaff($this->role);
    }

    /** A customer may only act while their company is approved. Staff are not affected. */
    public function companyActive(): bool
    {
        return $this->isStaff() || $this->companyStatus === 'approved';
    }

    public function can(string $cap): bool
    {
        return in_array($cap, Roles::caps($this->role), true) && user_can($this->userId, $cap);
    }

    public function require(string $cap): void
    {
        if (!$this->can($cap)) {
            throw new DomainError('forbidden', 'You do not have permission to do this.', ['capability' => $cap]);
        }
        if (!$this->companyActive()) {
            throw new DomainError('company_inactive', 'Your company account is not active. Please contact Canpas.');
        }
    }
}
