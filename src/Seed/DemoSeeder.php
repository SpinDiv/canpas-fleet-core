<?php

declare(strict_types=1);

namespace Canpas\Fleet\Seed;

use Canpas\Fleet\Domain\Catalog;
use Canpas\Fleet\Domain\Settings;
use Canpas\Fleet\Domain\Stock;
use Canpas\Fleet\Roles\Roles;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Tenancy\Accounts;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Staging demo data: roles, one demo customer company and one sign-in per role.
 * Safe to re-run. Passwords are random and never printed; set one in wp-admin > Users
 * (or send a reset link) to sign in as a demo user. Never run on the live site.
 */
final class DemoSeeder
{
    public const COMPANY = 'Demo Builders Corp.';

    /** @return list<string> */
    public static function run(): array
    {
        Roles::install();
        $out = ['Roles installed: ' . implode(', ', Roles::all())];

        $company = Accounts::company(self::COMPANY, 'approved');
        $out[] = 'Company: ' . self::COMPANY;

        $people = [
            ['demo_admin', 'Ana Villanueva', Roles::ADMIN, null],
            ['demo_dispatcher', 'Maria Reyes', Roles::DISPATCHER, null],
            ['demo_yard', 'Noel Garcia', Roles::YARD, null],
            ['demo_driver', 'Jun Santos', Roles::DRIVER, null],
            ['demo_customer_admin', 'Paolo Cruz', Roles::CUSTOMER_ADMIN, $company],
            ['demo_customer_user', 'Liza Ramos', Roles::CUSTOMER_USER, $company],
        ];
        foreach ($people as [$login, $name, $role, $companyId]) {
            [$uid, $created] = Accounts::user($login, str_replace('_', '.', $login) . '@canpas-demo.example', $name, $role);
            Accounts::membership($uid, $role, $companyId);
            $out[] = sprintf('%s %s (%s)', $created ? 'Created' : 'Kept', $login, Roles::LABELS[$role]);
        }
        return array_merge($out, self::catalog());
    }

    /**
     * Sample catalog with the spec's placeholder rates (10-wheeler: ₱3,000 start, ₱50/km,
     * driver ₱1,500 for the first 10 km then ₱30/km, 30% deposit). Replace with Canpas's real rates.
     *
     * @return list<string>
     */
    private static function catalog(): array
    {
        $admin = Actor::forUser((int) get_user_by('login', 'demo_admin')->ID);
        $out = [];
        $try = static function (callable $fn, string $what) use (&$out): ?string {
            try {
                $id = $fn();
                $out[] = "Added {$what}";
                return $id;
            } catch (DomainError $e) {
                if ($e->reason !== 'duplicate') {
                    throw $e;
                }
                return null;
            }
        };
        $types = [
            ['10-wheeler dropside', '12 tons', 3000, 50, 1500, 30, 30],
            ['6-wheeler', '5 tons', 2000, 40, 1200, 25, 30],
            ['Boom truck 5T', '5-ton boom', 4500, 60, 1800, 35, 40],
        ];
        $typeIds = [];
        foreach ($types as [$name, $cap, $start, $km, $flat, $dkm, $dep]) {
            $typeIds[$name] = $try(static fn() => Catalog::saveTruckType($admin, [
                'name' => $name, 'capacity' => $cap, 'starting_fee' => $start, 'rate_per_km' => $km, 'driver_flat_fee' => $flat,
                'driver_flat_km' => 10, 'driver_rate_per_km' => $dkm, 'deposit_mode' => 'percent', 'deposit_value' => $dep, 'active' => 1,
            ]), "truck type {$name}") ?? (string) Db::value(Db::prepare('SELECT LOWER(HEX(id)) FROM ' . Db::t('truck_types') . ' WHERE name = %s', $name));
        }
        $trucks = [
            ['NAB 4521', '10-wheeler dropside', 128434, '+20 days', 'available'],
            ['NAQ 7710', '10-wheeler dropside', 201876, '+140 days', 'repair'],
            ['NCX 1187', '6-wheeler', 88210, '+200 days', 'available'],
            ['NDF 3304', 'Boom truck 5T', 64320, '+90 days', 'available'],
        ];
        foreach ($trucks as [$plate, $type, $odo, $reg, $status]) {
            $try(static fn() => Catalog::saveTruck($admin, [
                'type_id' => $typeIds[$type], 'plate' => $plate, 'odometer_km' => $odo, 'status' => $status,
                'registration_expiry' => Db::todayManila()->modify($reg)->format('Y-m-d'),
                'insurance_expiry' => Db::todayManila()->modify('+180 days')->format('Y-m-d'),
            ]), "truck {$plate}");
        }
        $items = [
            ['Scaffold frame 1.7 m', 'scaffolding', 'pc', 8, 45, 150, 7, 2500, 600, 14],
            ['Cross brace 1.8 m', 'scaffolding', 'pc', 3, 18, 60, 7, 900, 250, 4],
            ['Adjustable jack base', 'scaffolding', 'pc', 2, 12, 40, 7, 750, 200, 4.5],
            ['Wall formwork panel 1.2 × 2.4 m', 'formwork', 'pc', 25, 150, 520, 14, 9500, 2500, 48],
            ['Adjustable steel prop 3.0 m', 'formwork', 'pc', 6, 35, 120, 14, 1800, 450, 15],
            ['Swivel coupler', 'tools', 'pc', 1, 6, 20, 7, 180, 60, 1.2],
        ];
        $stockQty = ['Scaffold frame 1.7 m' => 1200, 'Cross brace 1.8 m' => 1000, 'Adjustable jack base' => 600,
            'Wall formwork panel 1.2 × 2.4 m' => 800, 'Adjustable steel prop 3.0 m' => 400, 'Swivel coupler' => 3000];
        foreach ($items as [$name, $cat, $unit, $d, $w, $m, $min, $rep, $dmg, $kg]) {
            $try(static fn() => Catalog::saveItem($admin, [
                'name' => $name, 'category' => $cat, 'unit' => $unit, 'rate_day' => $d, 'rate_week' => $w, 'rate_month' => $m,
                'min_days' => $min, 'deposit_mode' => 'percent', 'deposit_value' => 30, 'replacement_cost' => $rep, 'damage_fee' => $dmg,
                'weight_kg' => $kg, 'active' => 1,
            ]), "item {$name}");
        }
        foreach ($stockQty as $name => $n) {
            $itemId = (string) Db::value(Db::prepare('SELECT LOWER(HEX(id)) FROM ' . Db::t('equipment_items') . ' WHERE name = %s', $name));
            if ($itemId !== '' && Stock::onHand($itemId) === 0) {
                Stock::adjust($admin, $itemId, $n, 'opening', 'Sample opening stock');
                $out[] = "Opening stock {$n} × {$name}";
            }
        }
        if ((string) Settings::get('yard_lat') === '') {
            Settings::save($admin, ['yard_address' => 'Sample yard: Dasmariñas, Cavite (replace with the real Canpas yard)', 'yard_lat' => '14.329400', 'yard_lng' => '120.936700']);
            $out[] = 'Sample yard location set (Dasmariñas, Cavite)';
        }
        $heavy = [
            ['Backhoe loader', 3500, 21000, 75000, 'BH-02'],
            ['Mobile crane 25T', 18000, 110000, 400000, 'CR-01'],
            ['Generator 60 kVA', 2500, 15000, 52000, 'GEN-07'],
        ];
        foreach ($heavy as [$name, $d, $w, $m, $serial]) {
            $itemId = $try(static fn() => Catalog::saveItem($admin, [
                'name' => $name, 'category' => 'heavy', 'unit' => 'unit', 'rate_day' => $d, 'rate_week' => $w, 'rate_month' => $m,
                'min_days' => 1, 'deposit_mode' => 'percent', 'deposit_value' => 30, 'replacement_cost' => $m * 20, 'damage_fee' => $d * 3, 'active' => 1,
            ]), "heavy equipment {$name}") ?? (string) Db::value(Db::prepare('SELECT LOWER(HEX(id)) FROM ' . Db::t('equipment_items') . ' WHERE name = %s', $name));
            $try(static fn() => Catalog::saveUnit($admin, ['item_id' => $itemId, 'serial' => $serial, 'status' => 'available',
                'inspection_expiry' => Db::todayManila()->modify('+25 days')->format('Y-m-d')]), "unit {$serial}");
        }
        return $out === [] ? ['Sample catalog already present.'] : $out;
    }
}
