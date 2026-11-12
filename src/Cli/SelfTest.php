<?php

declare(strict_types=1);

namespace Canpas\Fleet\Cli;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Domain\Catalog;
use Canpas\Fleet\Domain\Companies;
use Canpas\Fleet\Domain\Settings;
use Canpas\Fleet\Portal\Nav;
use Canpas\Fleet\Roles\Roles;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Files;
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

            $admin = $mk('admin', Roles::ADMIN, null);
            self::milestone1($sfx, $admin, $dispatcher, $mk, $users, $companies);
        } catch (\Throwable $e) {
            self::check('Self-test ran to completion', false, get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
        } finally {
            self::teardown($companies, $users, $sfx);
        }
        return self::$results;
    }

    /**
     * Milestone 1: sign-up and approval, company users, catalog and settings.
     *
     * @param list<int> $users
     * @param list<string> $companies
     */
    private static function milestone1(string $sfx, Actor $admin, Actor $dispatcher, callable $mk, array &$users, array &$companies): void
    {
        $png = wp_tempnam('cftest.png');
        file_put_contents($png, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
        $txt = wp_tempnam('cftest.txt');
        file_put_contents($txt, 'not a document');
        $file = static fn(string $path): array => ['name' => basename($path), 'tmp_name' => $path, 'size' => filesize($path), 'error' => UPLOAD_ERR_OK];
        $form = static fn(string $who): array => [
            'company_name' => "Signup {$who} {$sfx}", 'tin' => '123-456-789-000', 'address' => 'Imus, Cavite',
            'contact_name' => "Signup {$who}", 'email' => "cftest.signup{$who}.{$sfx}@canpas-demo.example", 'phone' => '09170000000',
            'password' => 'correct-horse-battery',
        ];
        $savedSettings = get_option(Settings::OPTION, null);
        // No real emails during the self-test.
        add_filter('pre_wp_mail', '__return_true');
        try {
            // Sign-up.
            self::expect('Sign-up without a business permit is refused', 'file_missing', static fn() => Companies::signup($form('x'), null));
            self::expect('Sign-up with a non-document file is refused', 'file_type', static fn() => Companies::signup($form('y'), $file($txt)));
            $s1 = Companies::signup($form('a'), $file($png));
            $users[] = $s1['user_id'];
            $companies[] = $s1['company_id'];
            $pending = Actor::forUser($s1['user_id']);
            self::check('Sign-up creates a pending company and an awaiting-approval login',
                $pending->role === Roles::PENDING && $pending->companyStatus === 'pending' && !$pending->can('cf_book'));
            self::expect('The same email cannot sign up twice', 'email_taken', static fn() => Companies::signup($form('a'), $file($png)));
            self::expect('The same company cannot sign up twice', 'company_exists',
                static fn() => Companies::signup(['email' => "cftest.other.{$sfx}@canpas-demo.example"] + $form('a'), $file($png)));
            $s2 = Companies::signup($form('b'), $file($png));
            $users[] = $s2['user_id'];
            $companies[] = $s2['company_id'];

            // Approval and decline.
            self::expect('A dispatcher cannot approve companies', 'forbidden', static fn() => Companies::approve($dispatcher, $s1['company_id']));
            Companies::approve($admin, $s1['company_id']);
            $approved = Actor::forUser($s1['user_id']);
            self::check('Approval turns the first login into company admin and allows booking',
                $approved->role === Roles::CUSTOMER_ADMIN && $approved->companyActive() && $approved->can('cf_book'));
            self::expect('Declining needs a reason', 'reason_required', static fn() => Companies::decline($admin, $s2['company_id'], ''));
            Companies::decline($admin, $s2['company_id'], 'Permit is not readable');
            self::expect('A declined company login cannot use the portal', 'no_membership', static fn() => Actor::forUser($s2['user_id']));

            // Suspension.
            Companies::suspend($admin, $s1['company_id'], 'Unpaid balance');
            self::expect('A suspended company cannot book', 'company_inactive', static fn() => Actor::forUser($s1['user_id'])->require('cf_book'));
            Companies::reactivate($admin, $s1['company_id']);
            self::check('Reactivating restores booking', Actor::forUser($s1['user_id'])->companyActive());

            // Permit viewing is logged.
            Companies::permitFile($admin, $s1['company_id']);
            $views = (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('audit_log') . ' WHERE action = %s AND object_id = UNHEX(%s)', 'company.permit_viewed', $s1['company_id']));
            self::check('Opening a business permit is recorded in the audit log', $views === 1, "{$views} views");

            // Company users.
            $custAdmin = Actor::forUser($s1['user_id']);
            $colleague = Companies::addUser($custAdmin, 'Signup Colleague', "cftest.colleague.{$sfx}@canpas-demo.example", Roles::CUSTOMER_USER);
            $users[] = $colleague;
            $col = Actor::forUser($colleague);
            self::check('A company admin can add a colleague to their own company', $col->companyId === $s1['company_id'] && $col->role === Roles::CUSTOMER_USER);
            self::expect('A company user cannot add people', 'forbidden', static fn() => Companies::addUser($col, 'X Y', "cftest.no.{$sfx}@canpas-demo.example", Roles::CUSTOMER_USER));
            self::expect('A company admin cannot remove their own access', 'cannot_remove_self', static fn() => Companies::deactivateUser($custAdmin, $custAdmin->userId));
            self::expect('A company admin cannot remove someone from another company', 'not_found', static fn() => Companies::deactivateUser($custAdmin, $dispatcher->userId));
            Companies::deactivateUser($custAdmin, $colleague);
            self::expect('A removed colleague can no longer sign in to the portal', 'no_membership', static fn() => Actor::forUser($colleague));

            // Catalog.
            $rates = ['name' => "Selftest 10-wheeler {$sfx}", 'starting_fee' => '3,000', 'rate_per_km' => '50', 'driver_flat_fee' => '1500',
                'driver_flat_km' => '10', 'driver_rate_per_km' => '30', 'deposit_mode' => 'percent', 'deposit_value' => '30', 'active' => '1'];
            self::expect('A dispatcher cannot change rates', 'forbidden', static fn() => Catalog::saveTruckType($dispatcher, $rates));
            self::expect('A percentage deposit above 100 is refused', 'invalid_input', static fn() => Catalog::saveTruckType($admin, ['deposit_value' => '130'] + $rates));
            $typeId = Catalog::saveTruckType($admin, $rates);
            $saved = Catalog::find('truck_types', $typeId);
            self::check('Truck type rates are saved as entered (₱3,000 start, ₱50/km)', (float) $saved['starting_fee'] === 3000.0 && (float) $saved['rate_per_km'] === 50.0);
            self::expect('Two truck types cannot share a name', 'duplicate', static fn() => Catalog::saveTruckType($admin, $rates));
            $rateAudit = (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('audit_log') . ' WHERE action = %s AND object_id = UNHEX(%s)', 'truck_type.create', $typeId));
            self::check('Rate changes are recorded in the audit log', $rateAudit === 1);

            $plate = 'T' . strtoupper($sfx);
            Catalog::saveTruck($admin, ['type_id' => $typeId, 'plate' => strtolower($plate), 'odometer_km' => '128452', 'status' => 'available']);
            self::check('Plate numbers are stored in capitals', (string) Db::value(Db::prepare('SELECT plate FROM ' . Db::t('trucks') . ' WHERE plate = %s', $plate)) === $plate);
            self::expect('Two trucks cannot share a plate', 'duplicate', static fn() => Catalog::saveTruck($admin, ['type_id' => $typeId, 'plate' => $plate, 'odometer_km' => '0', 'status' => 'available']));

            $item = ['name' => "Selftest frame {$sfx}", 'category' => 'scaffolding', 'unit' => 'pc', 'rate_day' => '8', 'rate_week' => '45', 'rate_month' => '150',
                'min_days' => '7', 'deposit_mode' => 'percent', 'deposit_value' => '30', 'replacement_cost' => '2500', 'damage_fee' => '600', 'active' => '1'];
            self::expect('A week rate above 7 day rates is refused', 'invalid_input', static fn() => Catalog::saveItem($admin, ['rate_week' => '80'] + $item));
            $frameId = Catalog::saveItem($admin, $item);
            $heavyId = Catalog::saveItem($admin, ['name' => "Selftest backhoe {$sfx}", 'category' => 'heavy', 'unit' => 'unit', 'min_days' => '1'] + $item);
            self::check('Heavy equipment is tracked by serial, other items by quantity',
                Catalog::find('equipment_items', $heavyId)['tracking'] === 'serial' && Catalog::find('equipment_items', $frameId)['tracking'] === 'qty');
            self::expect('Serial units cannot be added to quantity items', 'not_serial', static fn() => Catalog::saveUnit($admin, ['item_id' => $frameId, 'serial' => "S{$sfx}", 'status' => 'available']));
            Catalog::saveUnit($admin, ['item_id' => $heavyId, 'serial' => "BH{$sfx}", 'status' => 'available']);
            self::check('A heavy unit can be added to a heavy item', (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('equipment_units') . ' WHERE serial = %s', strtoupper("BH{$sfx}"))) === 1);

            // Settings.
            self::expect('A dispatcher cannot change settings', 'forbidden', static fn() => Settings::save($dispatcher, ['hold_hours' => 24]));
            self::expect('Hold time outside 1–72 hours is refused', 'invalid_setting', static fn() => Settings::save($admin, ['hold_hours' => 100]));
            Settings::save($admin, ['hold_hours' => 12]);
            self::check('Valid settings are saved', (int) Settings::get('hold_hours') === 12);

            // Sign-in page.
            self::check('Sign-in links point to the Canpas sign-in page', str_contains(wp_login_url(), '/fleet/login'), wp_login_url());
        } finally {
            remove_filter('pre_wp_mail', '__return_true');
            $savedSettings === null ? delete_option(Settings::OPTION) : update_option(Settings::OPTION, $savedSettings, false);
            @unlink($png);
            @unlink($txt);
        }
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
            Files::delete((string) Db::value(Db::prepare('SELECT permit_file FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $c)));
            Db::exec(Db::prepare('DELETE FROM ' . Db::t('audit_log') . ' WHERE company_id = UNHEX(%s) OR object_id = UNHEX(%s)', $c, $c));
            Db::exec(Db::prepare('DELETE FROM ' . Db::t('memberships') . ' WHERE company_id = UNHEX(%s)', $c));
            Db::exec(Db::prepare('DELETE FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $c));
        }
        $like = '%' . Db::wpdb()->esc_like($sfx) . '%';
        $likeUp = '%' . Db::wpdb()->esc_like(strtoupper($sfx)) . '%';
        Db::exec(Db::prepare('DELETE FROM ' . Db::t('equipment_units') . ' WHERE serial LIKE %s', $likeUp));
        Db::exec(Db::prepare('DELETE FROM ' . Db::t('equipment_items') . ' WHERE name LIKE %s', $like));
        Db::exec(Db::prepare('DELETE FROM ' . Db::t('trucks') . ' WHERE plate LIKE %s', $likeUp));
        Db::exec(Db::prepare('DELETE FROM ' . Db::t('truck_types') . ' WHERE name LIKE %s', $like));
        Db::exec(Db::prepare('DELETE FROM ' . Db::t('sequences') . ' WHERE name LIKE %s', 'T' . strtoupper(substr($sfx, 0, 3)) . '-%'));
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($users as $uid) {
            wp_delete_user($uid);
        }
    }
}
