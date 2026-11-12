<?php

declare(strict_types=1);

namespace Canpas\Fleet\Cli;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Portal\Nav;
use Canpas\Fleet\Roles\Roles;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Sequence;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Accounts;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Proves the system's rules against the real database using throwaway companies and users,
 * then removes everything it created. Each milestone adds its checks here before its code.
 */
final class SelfTest
{
    /** @var list<array{0:bool,1:string,2:string}> */
    private static array $results = [];

    /** @return list<array{0:bool,1:string,2:string}> */
    public static function run(): array
    {
        self::$results = [];
        $sfx = substr(Uuid::v7(), -6);
        $users = [];
        $companies = [];
        try {
            // Foundation: schema and roles.
            $applied = Db::value(Db::prepare('SELECT status FROM ' . Db::t('migrations') . ' WHERE version = %s', '0001_core'));
            self::check('Migration 0001_core is applied', $applied === 'applied', (string) $applied);
            Roles::install();
            $stockLeak = array_intersect(array_keys(get_role('administrator')->capabilities ?? []), Roles::allCaps());
            self::check('WordPress administrator role carries no Canpas capabilities', $stockLeak === [], implode(',', $stockLeak));

            $companyA = Accounts::company("Selftest A {$sfx}");
            $companyB = Accounts::company("Selftest B {$sfx}");
            $companyS = Accounts::company("Selftest Suspended {$sfx}", 'suspended');
            $companies = [$companyA, $companyB, $companyS];

            $mk = static function (string $who, string $role, ?string $company) use (&$users, $sfx): Actor {
                [$uid] = Accounts::user("cftest_{$who}_{$sfx}", "cftest.{$who}.{$sfx}@canpas-demo.example", "Selftest {$who}", $role);
                $users[] = $uid;
                Accounts::membership($uid, $role, $company);
                return Actor::forUser($uid);
            };
            $dispatcher = $mk('dispatcher', Roles::DISPATCHER, null);
            $driver = $mk('driver', Roles::DRIVER, null);
            $custA = $mk('custa', Roles::CUSTOMER_ADMIN, $companyA);
            $userB = $mk('userb', Roles::CUSTOMER_USER, $companyB);
            $suspended = $mk('susp', Roles::CUSTOMER_USER, $companyS);

            // Who can act.
            self::expect('A website administrator without a membership has no portal access', 'no_membership', static fn() => Actor::forUser(1));
            self::expect('A staff role cannot be linked to a customer company', 'bad_membership', static function () use ($companyA, &$users, $sfx): void {
                [$uid] = Accounts::user("cftest_bad_{$sfx}", "cftest.bad.{$sfx}@canpas-demo.example", 'Selftest bad', Roles::DISPATCHER);
                $users[] = $uid;
                Accounts::membership($uid, Roles::DISPATCHER, $companyA);
            });
            self::check('Staff actors have no company', $dispatcher->companyId === null && $dispatcher->isStaff());
            self::check('Customer actors carry their own company', $custA->companyId === $companyA && $userB->companyId === $companyB);

            // What each role can do.
            self::check('Dispatcher can manage orders but not rates or settings',
                $dispatcher->can('cf_manage_orders') && !$dispatcher->can('cf_manage_rates') && !$dispatcher->can('cf_manage_settings'));
            self::check('Driver can only drive', $driver->can('cf_drive') && !$driver->can('cf_manage_orders') && !$driver->can('cf_book'));
            self::check('Customer user can book but cannot see staff pages or manage company users',
                $userB->can('cf_book') && !$userB->can('cf_view_dashboard') && !$userB->can('cf_manage_company_users'));
            self::check('Customer admin can manage company users', $custA->can('cf_manage_company_users'));
            self::expect('A suspended company cannot act', 'company_inactive', static fn() => $suspended->require('cf_book'));
            self::expect('A customer is refused a staff action', 'forbidden', static fn() => $userB->require('cf_manage_orders'));

            // Portal menus follow the roles.
            self::check('Each role lands on its own home page',
                Nav::home($dispatcher) === 'dashboard' && Nav::home($driver) === 'today' && Nav::home($userB) === 'home',
                Nav::home($dispatcher) . '/' . Nav::home($driver) . '/' . Nav::home($userB));
            self::check('Customers cannot open staff pages', !Nav::canOpen($userB, 'orders') && !Nav::canOpen($custA, 'approvals'));
            $menuSlugs = array_merge(...array_map(static fn($g) => array_column($g['items'], 'slug'), Nav::menu($driver)));
            self::check('Driver menu shows only driver pages', $menuSlugs === ['today', 'expenses'], implode(',', $menuSlugs));

            // Reference numbers.
            $prefix = 'T' . strtoupper(substr($sfx, 0, 3));
            $r1 = Sequence::ref($prefix);
            $r2 = Sequence::ref($prefix);
            $yymm = Db::todayManila()->format('ym');
            self::check('Reference numbers count up per month (e.g. ORD-YYMM-0001)',
                $r1 === "{$prefix}-{$yymm}-0001" && $r2 === "{$prefix}-{$yymm}-0002", "{$r1}, {$r2}");

            // Audit trail.
            Audit::log($companyA, $custA->userId, 'selftest.ping', 'company', $companyA, ['n' => 1]);
            $n = (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('audit_log') . ' WHERE actor_user_id = %d AND action = %s', $custA->userId, 'selftest.ping'));
            self::check('Audit log records an action with its company', $n === 1, "{$n} rows");

            // Database errors are never silent.
            self::expectThrows('A failed query throws instead of returning false', static fn() => Db::exec('SELECT * FROM ' . Db::t('no_such_table')));
        } catch (\Throwable $e) {
            self::check('Self-test ran to completion', false, get_class($e) . ': ' . $e->getMessage());
        } finally {
            self::teardown($companies, $users, $sfx);
        }
        return self::$results;
    }

    private static function expect(string $name, string $reason, callable $fn): void
    {
        try {
            $fn();
            self::$results[] = [false, $name, "expected {$reason}, but it was allowed"];
        } catch (DomainError $e) {
            self::$results[] = [$e->reason === $reason, $name, $e->reason === $reason ? $reason : "expected {$reason}, got {$e->reason}: {$e->getMessage()}"];
        }
    }

    private static function expectThrows(string $name, callable $fn): void
    {
        try {
            $fn();
            self::$results[] = [false, $name, 'no exception'];
        } catch (\Throwable $e) {
            self::$results[] = [true, $name, ''];
        }
    }

    private static function check(string $name, bool $ok, string $detail = ''): void
    {
        self::$results[] = [$ok, $name, $detail];
    }

    /** @param list<string> $companies @param list<int> $users */
    private static function teardown(array $companies, array $users, string $sfx): void
    {
        foreach ($users as $uid) {
            Db::exec(Db::prepare('DELETE FROM ' . Db::t('audit_log') . ' WHERE actor_user_id = %d', $uid));
            Db::exec(Db::prepare('DELETE FROM ' . Db::t('memberships') . ' WHERE user_id = %d', $uid));
        }
        foreach ($companies as $c) {
            Db::exec(Db::prepare('DELETE FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $c));
        }
        Db::exec(Db::prepare('DELETE FROM ' . Db::t('sequences') . ' WHERE name LIKE %s', 'T' . strtoupper(substr($sfx, 0, 3)) . '-%'));
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($users as $uid) {
            wp_delete_user($uid);
        }
    }
}
