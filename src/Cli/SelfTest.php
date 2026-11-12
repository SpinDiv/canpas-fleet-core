<?php

declare(strict_types=1);

namespace Canpas\Fleet\Cli;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Domain\Catalog;
use Canpas\Fleet\Domain\Companies;
use Canpas\Fleet\Domain\Distance;
use Canpas\Fleet\Domain\Orders;
use Canpas\Fleet\Domain\Pricing;
use Canpas\Fleet\Domain\Settings;
use Canpas\Fleet\Domain\Stock;
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
            self::milestone2($sfx, $admin, $dispatcher, $custA, $userB, $suspended);
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

    /** Milestone 2: pricing, distance, stock, availability, holds, order access. */
    private static function milestone2(string $sfx, Actor $admin, Actor $dispatcher, Actor $custA, Actor $userB, Actor $suspended): void
    {
        $savedSettings = get_option(Settings::OPTION, null);
        Orders::$refPrefix = 'TST';
        try {
            Settings::save($admin, ['yard_lat' => '14.329400', 'yard_lng' => '120.936700', 'hold_hours' => 24, 'driver_km_basis' => 'round_trip', 'trips_per_rental' => '2', 'delivery_truck_type' => '']);

            // Pricing rules (spec worked example).
            $rates = ['starting_fee' => 3000, 'rate_per_km' => 50, 'driver_flat_fee' => 1500, 'driver_flat_km' => 10, 'driver_rate_per_km' => 30, 'daily_rate' => 0, 'free_km' => 0];
            $t = Pricing::truckTrip($rates, 36, true);
            self::check('Truck price matches the spec example: 36 km 10-wheeler with driver = ₱7,080', $t['total'] === 7080.0 && $t['driver'] === 2280.0, (string) $t['total']);
            self::check('Deposit at 30% of ₱7,080 = ₱2,124', Pricing::deposit('percent', 30, 7080) === 2124.0);
            self::check('One-way driver basis counts the flat 10 km each way (₱1,980)', Pricing::truckTrip($rates, 36, true, 0, 'one_way')['driver'] === 1980.0);
            $fd = Pricing::truckTrip(['free_km' => 6, 'daily_rate' => 1000] + $rates, 36, false, 3);
            self::check('Free km reduce the distance charge; daily rate × days is added', $fd['distance'] === 1500.0 && $fd['daily'] === 3000.0 && $fd['driver'] === 0.0);
            $item = ['rate_day' => 8, 'rate_week' => 45, 'rate_month' => 150, 'min_days' => 7];
            $e40 = Pricing::equipment($item, 100, 40);
            self::check('100 frames for 40 days = 1 month + 1 week + 3 days = ₱21,900', $e40['amount'] === 21900.0 && Pricing::periodLabel($e40) === '1 month + 1 week + 3 days', (string) $e40['amount']);
            self::check('Minimum rental applies: 3 days is charged as the 7-day minimum (₱45)', Pricing::equipment($item, 1, 3)['unit_price'] === 45.0);
            self::check('Cheapest mix is used: 9 days = 1 week + 2 days (₱61), never 2 weeks', Pricing::equipment($item, 1, 9)['unit_price'] === 61.0);

            // Distance.
            self::expect('A site outside the Philippines is refused', 'outside_service_area', static fn() => Distance::parseCoords('51.5072, -0.1276'));
            [$lat, $lng] = Distance::parseCoords('14.4296, 120.9367');
            $dist = Distance::roundTrip($lat, $lng);
            $hasKey = defined('CANPAS_GOOGLE_SERVER_KEY') && CANPAS_GOOGLE_SERVER_KEY !== '';
            self::check('Round-trip distance is worked out from the yard' . ($hasKey ? ' (Google)' : ' (estimate until the Google key is set)'),
                $dist['km'] > 0 && $dist['source'] === ($hasKey ? 'routes' : 'estimate'), $dist['km'] . ' km, ' . $dist['source']);

            // Test catalog.
            $typeId = Catalog::saveTruckType($admin, ['name' => "Selftest M2 truck {$sfx}", 'deposit_mode' => 'percent', 'deposit_value' => 30, 'active' => 1] + $rates);
            Catalog::saveTruck($admin, ['type_id' => $typeId, 'plate' => "TA{$sfx}", 'odometer_km' => 0, 'status' => 'available']);
            Catalog::saveTruck($admin, ['type_id' => $typeId, 'plate' => "TB{$sfx}", 'odometer_km' => 0, 'status' => 'repair']);
            $frame = Catalog::saveItem($admin, ['name' => "Selftest M2 frame {$sfx}", 'category' => 'scaffolding', 'unit' => 'pc', 'deposit_mode' => 'percent', 'deposit_value' => 30,
                'replacement_cost' => 2500, 'damage_fee' => 600, 'active' => 1] + $item);
            $heavy = Catalog::saveItem($admin, ['name' => "Selftest M2 backhoe {$sfx}", 'category' => 'heavy', 'unit' => 'unit', 'deposit_mode' => 'percent', 'deposit_value' => 30,
                'replacement_cost' => 0, 'damage_fee' => 0, 'active' => 1, 'rate_day' => 3500, 'rate_week' => 21000, 'rate_month' => 75000, 'min_days' => 1]);

            // Stock ledger.
            self::expect('A dispatcher cannot change stock', 'forbidden', static fn() => Stock::adjust($dispatcher, $frame, 100, 'opening'));
            self::expect('Removing stock with a "received" reason is refused', 'invalid_input', static fn() => Stock::adjust($admin, $frame, -5, 'purchase'));
            self::expect('Heavy equipment is not counted by quantity', 'not_quantity', static fn() => Stock::adjust($admin, $heavy, 1, 'opening'));
            Stock::adjust($admin, $frame, 100, 'opening');
            self::check('Stock on hand is the sum of movements', Stock::onHand($frame) === 100);
            self::expect('Stock cannot go below zero', 'negative_stock', static fn() => Stock::adjust($admin, $frame, -101, 'write_off'));

            // Quotes and booking.
            $start = Db::todayManila()->modify('+10 days')->format('Y-m-d');
            $q = Orders::quoteEquipment($custA, ['start_date' => $start, 'days' => 40, 'fulfilment' => 'pickup', "qty[{$frame}]" => 100]);
            self::check('Equipment quote: 100 frames, 40 days, pickup = ₱21,900, deposit ₱6,570', $q['subtotal'] === 21900.0 && $q['deposit'] === 6570.0, $q['subtotal'] . ' / ' . $q['deposit']);
            $qd = Orders::quoteEquipment($custA, ['start_date' => $start, 'days' => 7, 'fulfilment' => 'delivery', 'site_address' => 'Imus, Cavite', 'site_coords' => '14.4296, 120.9367', "qty[{$frame}]" => 10]);
            $deliveryLine = array_values(array_filter($qd['lines'], static fn($l) => $l['line_type'] === 'delivery'))[0] ?? null;
            self::check('Delivery adds truck trips with a driver (delivery and pickup = 2 trips)', $deliveryLine !== null && $deliveryLine['qty'] === 2 && $deliveryLine['amount'] > 0);
            self::expect('A past start date is refused', 'invalid_input', static fn() => Orders::quoteEquipment($custA, ['start_date' => Db::todayManila()->modify('-1 day')->format('Y-m-d'), 'days' => 7, 'fulfilment' => 'pickup', "qty[{$frame}]" => 1]));
            self::expect('Staff cannot place customer orders', 'forbidden', static fn() => Orders::bookEquipment($dispatcher, ['start_date' => $start, 'days' => 7, 'fulfilment' => 'pickup', "qty[{$frame}]" => 1]));
            self::expect('A suspended company cannot book', 'company_inactive', static fn() => Orders::bookEquipment($suspended, ['start_date' => $start, 'days' => 7, 'fulfilment' => 'pickup', "qty[{$frame}]" => 1]));

            $o1 = Orders::bookEquipment($custA, ['start_date' => $start, 'days' => 7, 'fulfilment' => 'pickup', "qty[{$frame}]" => 60, 'total' => '1', 'subtotal' => '1']);
            $order = Orders::get($custA, $o1);
            self::check('A booking gets an order number like ORD-2611-0001 and is held', (bool) preg_match('/^TST-\d{4}-\d{4,}$/', $order['ref']) && $order['status'] === 'requested' && $order['hold_active'], $order['ref']);
            self::check('Prices sent by the browser are ignored (60 frames × 7 days = ₱2,700)', (float) $order['total'] === 2700.0, (string) $order['total']);

            $endA = Db::todayManila()->modify('+16 days')->format('Y-m-d');
            self::expect('Overbooking is refused: 50 more frames on the same dates (only 40 free)', 'not_available',
                static fn() => Orders::bookEquipment($userB, ['start_date' => $start, 'days' => 7, 'fulfilment' => 'pickup', "qty[{$frame}]" => 50]));
            $after = Db::todayManila()->modify('+17 days')->format('Y-m-d');
            $o2 = Orders::bookEquipment($userB, ['start_date' => $after, 'days' => 7, 'fulfilment' => 'pickup', "qty[{$frame}]" => 50]);
            self::check('The same stock can be booked again on dates that do not overlap', Orders::get($userB, $o2)['status'] === 'requested');
            self::check('Free stock is worked out per day (40 free during the first booking)', Orders::itemFree($frame, $start, $endA) === 40);

            Catalog::saveItem($admin, ['rate_day' => 9, 'rate_week' => 50, 'rate_month' => 160], $frame);
            $line = Orders::get($custA, $o1)['lines'][0];
            self::check('Changing a rate later does not change an existing order', (float) json_decode($line['rates_json'], true)['rate_day'] === 8.0 && (float) $line['amount'] === 2700.0);

            // Trucks: one available, one in repair.
            $truckIn = ['truck_type_id' => $typeId, 'start_date' => $start, 'days' => 2, 'site_address' => 'Imus, Cavite', 'site_coords' => '14.4296, 120.9367', 'needs_driver' => '1'];
            Orders::bookTruck($custA, $truckIn);
            self::expect('A truck type with one free truck cannot be booked twice for the same dates (the one in repair does not count)', 'not_available', static fn() => Orders::bookTruck($userB, $truckIn));

            // Access between companies.
            self::expect('Another company cannot open this order', 'not_found', static fn() => Orders::get($userB, $o1));
            $listA = array_column(Orders::list($custA), 'id');
            $listB = array_column(Orders::list($userB), 'id');
            self::check("Order lists show only the company's own orders", in_array($o1, $listA, true) && !in_array($o1, $listB, true));

            // Holds.
            Db::exec(Db::prepare('UPDATE ' . Db::t('orders') . ' SET hold_expires_at = %s WHERE id = UNHEX(%s)', '2000-01-01 00:00:00.000000', $o1));
            self::check('An expired hold frees its stock straight away', Orders::itemFree($frame, $start, $endA) === 100);
            Orders::expireHolds();
            self::check('The hourly job marks expired holds', Orders::get($custA, $o1)['status'] === 'expired');

            // Cancelling.
            self::expect('Staff must give a reason to cancel', 'reason_required', static fn() => Orders::cancel($dispatcher, $o2, ''));
            Orders::cancel($userB, $o2);
            self::check('A customer can cancel their own held order', Orders::get($userB, $o2)['status'] === 'cancelled');
            self::expect('A cancelled order cannot be cancelled again', 'wrong_status', static fn() => Orders::cancel($userB, $o2));
        } finally {
            Orders::$refPrefix = 'ORD';
            $savedSettings === null ? delete_option(Settings::OPTION) : update_option(Settings::OPTION, $savedSettings, false);
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
            Db::exec(Db::prepare('DELETE l FROM ' . Db::t('order_lines') . ' l JOIN ' . Db::t('orders') . ' o ON o.id = l.order_id WHERE o.company_id = UNHEX(%s)', $c));
            Db::exec(Db::prepare('DELETE FROM ' . Db::t('orders') . ' WHERE company_id = UNHEX(%s)', $c));
        }
        foreach ($companies as $c) {
            Files::delete((string) Db::value(Db::prepare('SELECT permit_file FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $c)));
            Db::exec(Db::prepare('DELETE FROM ' . Db::t('audit_log') . ' WHERE company_id = UNHEX(%s) OR object_id = UNHEX(%s)', $c, $c));
            Db::exec(Db::prepare('DELETE FROM ' . Db::t('memberships') . ' WHERE company_id = UNHEX(%s)', $c));
            Db::exec(Db::prepare('DELETE FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $c));
        }
        $like = '%' . Db::wpdb()->esc_like($sfx) . '%';
        $likeUp = '%' . Db::wpdb()->esc_like(strtoupper($sfx)) . '%';
        Db::exec(Db::prepare('DELETE m FROM ' . Db::t('stock_movements') . ' m JOIN ' . Db::t('equipment_items') . ' i ON i.id = m.item_id WHERE i.name LIKE %s', $like));
        Db::exec(Db::prepare('DELETE FROM ' . Db::t('equipment_units') . ' WHERE serial LIKE %s', $likeUp));
        Db::exec(Db::prepare('DELETE FROM ' . Db::t('equipment_items') . ' WHERE name LIKE %s', $like));
        Db::exec(Db::prepare('DELETE FROM ' . Db::t('trucks') . ' WHERE plate LIKE %s', $likeUp));
        Db::exec(Db::prepare('DELETE FROM ' . Db::t('truck_types') . ' WHERE name LIKE %s', $like));
        Db::exec(Db::prepare('DELETE FROM ' . Db::t('sequences') . ' WHERE name LIKE %s OR name LIKE %s', 'T' . strtoupper(substr($sfx, 0, 3)) . '-%', 'TST-%'));
        require_once ABSPATH . 'wp-admin/includes/user.php';
        foreach ($users as $uid) {
            wp_delete_user($uid);
        }
    }
}
