<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Roles\Roles;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Company drivers: WordPress users with the cf_driver role, plus their licence and phone.
 * A driver can be given a trip only while active and with a licence valid on the trip dates.
 */
final class Drivers
{
    /** @return list<array<string,mixed>> drivers with profile and their next trips count */
    public static function list(Actor $actor): array
    {
        $actor->require('cf_assign_fleet');
        $out = [];
        foreach (get_users(['role' => Roles::DRIVER, 'orderby' => 'display_name']) as $u) {
            $out[] = self::row($u);
        }
        return $out;
    }

    /** @return array<string,mixed>|null */
    public static function get(int $userId): ?array
    {
        $u = get_userdata($userId);
        return $u && in_array(Roles::DRIVER, (array) $u->roles, true) ? self::row($u) : null;
    }

    /** Drivers who can take a trip from $start to $end (active, licence valid to $end). @return list<array<string,mixed>> */
    public static function usable(string $start, string $end): array
    {
        $out = [];
        foreach (get_users(['role' => Roles::DRIVER, 'orderby' => 'display_name']) as $u) {
            $d = self::row($u);
            if ($d['active'] && $d['license_expiry'] !== null && $d['license_expiry'] >= $end) {
                $out[] = $d;
            }
        }
        return $out;
    }

    /**
     * Add a driver login. WordPress emails them a link to set their password.
     *
     * @param array<string,mixed> $in name, email, phone, license_number, license_expiry
     */
    public static function create(Actor $actor, array $in): int
    {
        $actor->require('cf_manage_staff');
        $name = sanitize_text_field(wp_unslash((string) ($in['name'] ?? '')));
        $email = sanitize_email((string) ($in['email'] ?? ''));
        if (mb_strlen($name) < 3) {
            throw new DomainError('invalid_input', 'Enter the driver\'s full name.', ['field' => 'name']);
        }
        if (!is_email($email)) {
            throw new DomainError('invalid_input', 'Enter a valid email address.', ['field' => 'email']);
        }
        if (email_exists($email)) {
            throw new DomainError('email_taken', 'Someone already uses this email.', ['field' => 'email']);
        }
        $profile = self::clean($in);
        $login = sanitize_user(strtolower(strtok($email, '@')), true) ?: 'driver';
        $base = $login;
        for ($i = 2; username_exists($login); $i++) {
            $login = $base . $i;
        }
        $parts = explode(' ', $name, 2);
        $uid = wp_insert_user([
            'user_login' => $login, 'user_email' => $email, 'user_pass' => wp_generate_password(24, true, true),
            'display_name' => $name, 'first_name' => $parts[0], 'last_name' => $parts[1] ?? '', 'role' => Roles::DRIVER,
        ]);
        if (is_wp_error($uid)) {
            throw new DomainError('invalid_input', $uid->get_error_message());
        }
        self::write($actor, (int) $uid, $profile + ['active' => 1]);
        Audit::log(null, $actor->userId, 'driver.create', 'user', null, ['user_id' => (int) $uid]);
        if (!(defined('WP_CLI') && WP_CLI)) {
            wp_new_user_notification((int) $uid, null, 'user');
        }
        return (int) $uid;
    }

    /** @param array<string,mixed> $in phone, license_number, license_expiry, active */
    public static function save(Actor $actor, int $userId, array $in): void
    {
        $actor->require('cf_manage_staff');
        if (self::get($userId) === null) {
            throw new DomainError('not_found', 'Driver not found.');
        }
        $profile = self::clean($in);
        $profile['active'] = !empty($in['active']) && $in['active'] !== '0' ? 1 : 0;
        self::write($actor, $userId, $profile);
        Audit::log(null, $actor->userId, 'driver.update', 'user', null, ['user_id' => $userId] + $profile);
    }

    // ----- internals ---------------------------------------------------------------------------

    /** @return array<string,mixed> */
    private static function clean(array $in): array
    {
        $phone = preg_replace('/[^0-9+ ]/', '', (string) ($in['phone'] ?? '')) ?? '';
        $lic = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) ($in['license_number'] ?? '')) ?? '');
        $exp = (string) ($in['license_expiry'] ?? '');
        if ($lic === '' || strlen($lic) < 5) {
            throw new DomainError('invalid_input', 'Enter the driver\'s licence number.', ['field' => 'license_number']);
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $exp);
        if (!$d || $d->format('Y-m-d') !== $exp) {
            throw new DomainError('invalid_input', 'Enter the licence expiry date.', ['field' => 'license_expiry']);
        }
        return ['phone' => $phone !== '' ? mb_substr($phone, 0, 32) : null, 'license_number' => mb_substr($lic, 0, 40), 'license_expiry' => $exp];
    }

    private static function write(Actor $actor, int $userId, array $p): void
    {
        Db::exec(Db::prepare(
            'INSERT INTO ' . Db::t('driver_profiles') . ' (user_id, phone, license_number, license_expiry, active, updated_by, updated_at) VALUES (%d, %s, %s, %s, %d, %d, %s)
             ON DUPLICATE KEY UPDATE phone = VALUES(phone), license_number = VALUES(license_number), license_expiry = VALUES(license_expiry), active = VALUES(active), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            $userId, $p['phone'], $p['license_number'], $p['license_expiry'], (int) $p['active'], $actor->userId, Db::now()
        ));
    }

    /** @return array<string,mixed> */
    private static function row(\WP_User $u): array
    {
        $p = Db::row(Db::prepare('SELECT phone, license_number, license_expiry, active FROM ' . Db::t('driver_profiles') . ' WHERE user_id = %d', $u->ID));
        $upcoming = (int) Db::value(Db::prepare(
            'SELECT COUNT(*) FROM ' . Db::t('trips') . ' WHERE driver_user_id = %d AND status NOT IN (%s, %s) AND trip_date >= %s',
            $u->ID, 'back', 'cancelled', Db::todayManila()->modify('-60 days')->format('Y-m-d')
        ));
        return [
            'user_id' => $u->ID, 'name' => $u->display_name, 'email' => $u->user_email,
            'phone' => $p['phone'] ?? null, 'license_number' => $p['license_number'] ?? null, 'license_expiry' => $p['license_expiry'] ?? null,
            'active' => $p === null ? true : (bool) (int) $p['active'], 'open_trips' => $upcoming,
        ];
    }
}
