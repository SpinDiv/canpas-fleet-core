<?php

declare(strict_types=1);

namespace Canpas\Fleet\Tenancy;

use Canpas\Fleet\Roles\Roles;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Uuid;

/**
 * Low-level account plumbing used by seeding, tests and (later) the approval screens:
 * companies, WordPress users and the membership that links them.
 */
final class Accounts
{
    /** Create a company, or return the id of the one with this name. */
    public static function company(string $name, string $status = 'approved'): string
    {
        $existing = Db::value(Db::prepare('SELECT LOWER(HEX(id)) FROM ' . Db::t('companies') . ' WHERE name = %s', $name));
        if ($existing) {
            return (string) $existing;
        }
        $id = Uuid::v7();
        Db::insert('companies', [
            'id' => $id,
            'name' => $name,
            'status' => $status,
            'approved_at' => $status === 'approved' ? Db::now() : null,
            'created_at' => Db::now(),
        ], ['id']);
        return $id;
    }

    /**
     * Create a WordPress user with a random password (the person sets their own through a reset link).
     *
     * @return array{0:int, 1:bool} user id, whether it was created now
     */
    public static function user(string $login, string $email, string $displayName, string $role): array
    {
        $existing = get_user_by('login', $login);
        if ($existing) {
            return [(int) $existing->ID, false];
        }
        $uid = wp_insert_user([
            'user_login' => $login,
            'user_email' => $email,
            'display_name' => $displayName,
            'user_pass' => wp_generate_password(32, true, true),
            'role' => $role,
        ]);
        if (is_wp_error($uid)) {
            throw new DomainError('user_create_failed', $uid->get_error_message());
        }
        return [(int) $uid, true];
    }

    /** Link a user to Canpas staff (no company) or to a customer company. One membership per user. */
    public static function membership(int $userId, string $role, ?string $companyId = null): void
    {
        if (!isset(Roles::CAPS[$role])) {
            throw new DomainError('unknown_role', "Unknown role {$role}.");
        }
        if (Roles::isStaff($role) !== ($companyId === null)) {
            throw new DomainError('bad_membership', 'Staff have no company; customers need one.');
        }
        $exists = Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('memberships') . ' WHERE user_id = %d', $userId));
        if ((int) $exists > 0) {
            return;
        }
        Db::insert('memberships', [
            'id' => Uuid::v7(),
            'user_id' => $userId,
            'company_id' => $companyId,
            'role' => $role,
            'status' => 'active',
            'created_at' => Db::now(),
        ], ['id', 'company_id']);
        $u = new \WP_User($userId);
        $u->set_role($role);
    }
}
