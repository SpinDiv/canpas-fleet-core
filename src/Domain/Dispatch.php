<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Assigning the actual fleet to confirmed orders.
 *
 * - Truck orders get one truck of the booked type and, if they asked for one, a company driver
 *   (a "rental" trip that lasts the whole booking).
 * - Heavy equipment lines get their units by serial.
 * - Equipment delivered to site gets a delivery trip (start date) and a pickup trip (end date),
 *   each with a truck and driver.
 *
 * Nothing can be on two things at once: a truck or unit on an active order cannot be given to another
 * order whose dates overlap, and a driver on a rental trip is busy for its whole length. Short delivery
 * and pickup runs can share a day with each other. Checks run under row locks.
 */
final class Dispatch
{
    public const TRIP_KINDS = ['rental' => 'Rental with driver', 'delivery' => 'Delivery', 'pickup' => 'Pickup'];

    /** Assign a truck (and the driver when the order has one) to a confirmed truck order. */
    public static function assignTruck(Actor $actor, string $orderId, string $truckId, int $driverId = 0): void
    {
        $actor->require('cf_assign_fleet');
        $o = Orders::row($orderId);
        if ($o['kind'] !== 'truck') {
            throw new DomainError('invalid_input', 'This is not a truck order.');
        }
        self::assertAssignable($o);
        $line = Db::row(Db::prepare('SELECT ' . Db::hex('id') . ', ' . Db::hex('truck_type_id') . ' FROM ' . Db::t('order_lines') . ' WHERE order_id = UNHEX(%s) AND line_type = %s LIMIT 1', $o['id'], 'truck'));
        $truckId = Uuid::assertHex($truckId, 'truck');
        $needsDriver = (int) $o['needs_driver'] === 1;
        if ($needsDriver && $driverId <= 0) {
            throw new DomainError('driver_required', 'This order comes with a Canpas driver. Choose one.', ['field' => 'driver_user_id']);
        }
        Db::transaction(static function () use ($actor, $o, $line, $truckId, $needsDriver, $driverId): void {
            $t = self::lockTruck($truckId);
            if ($t['type_id'] !== $line['truck_type_id']) {
                throw new DomainError('wrong_type', 'This truck is not the type the customer booked.', ['field' => 'truck_id']);
            }
            self::assertTruckFree($truckId, (string) $o['start_date'], (string) $o['end_date'], (string) $o['id'], true);
            if ($needsDriver) {
                self::assertDriver($driverId, (string) $o['start_date'], (string) $o['end_date'], (string) $o['id'], false);
            }
            Db::exec(Db::prepare('DELETE FROM ' . Db::t('order_assets') . ' WHERE order_id = UNHEX(%s) AND asset_type = %s', $o['id'], 'truck'));
            Db::insert('order_assets', [
                'id' => Uuid::v7(), 'order_id' => $o['id'], 'line_id' => $line['id'], 'asset_type' => 'truck', 'asset_id' => $truckId,
                'start_date' => $o['start_date'], 'end_date' => $o['end_date'], 'assigned_by' => $actor->userId, 'created_at' => Db::now(),
            ], ['id', 'order_id', 'line_id', 'asset_id']);
            if ($needsDriver) {
                self::upsertTrip($actor, $o, 'rental', (string) $o['start_date'], $truckId, $driverId);
            }
            Audit::log($o['company_id'], $actor->userId, 'order.assign_truck', 'order', $o['id'], ['truck' => $t['plate'], 'driver' => $driverId ?: null]);
        });
    }

    /**
     * Assign heavy units (by serial) to one equipment line.
     *
     * @param list<string> $unitIds exactly as many as the line's quantity
     */
    public static function assignUnits(Actor $actor, string $orderId, string $lineId, array $unitIds): void
    {
        $actor->require('cf_assign_fleet');
        $o = Orders::row($orderId);
        self::assertAssignable($o);
        $lineId = Uuid::assertHex($lineId, 'line');
        $line = Db::row(Db::prepare(
            'SELECT ' . Db::hex('l.id', 'id') . ', ' . Db::hex('l.item_id', 'item_id') . ', l.qty, l.description, i.tracking FROM ' . Db::t('order_lines') . ' l
             JOIN ' . Db::t('equipment_items') . ' i ON i.id = l.item_id WHERE l.id = UNHEX(%s) AND l.order_id = UNHEX(%s)', $lineId, $o['id']
        ));
        if ($line === null || $line['tracking'] !== 'serial') {
            throw new DomainError('not_found', 'Heavy equipment line not found.');
        }
        $unitIds = array_values(array_unique(array_map(static fn($u): string => Uuid::assertHex(strtolower((string) $u), 'unit'), $unitIds)));
        if (count($unitIds) !== (int) $line['qty']) {
            throw new DomainError('wrong_count', 'Choose exactly ' . (int) $line['qty'] . ' unit' . ((int) $line['qty'] === 1 ? '' : 's') . ' for ' . $line['description'] . '.', ['field' => 'units']);
        }
        Db::transaction(static function () use ($actor, $o, $line, $unitIds): void {
            $serials = [];
            foreach ($unitIds as $uid) {
                $u = Db::row(Db::prepare('SELECT ' . Db::hex('item_id') . ', serial, status FROM ' . Db::t('equipment_units') . ' WHERE id = UNHEX(%s) FOR UPDATE', $uid));
                if ($u === null || $u['item_id'] !== $line['item_id']) {
                    throw new DomainError('not_found', 'Unit not found for this item.');
                }
                if ($u['status'] !== 'available') {
                    throw new DomainError('unit_unavailable', "Unit {$u['serial']} is not available (" . $u['status'] . ').');
                }
                $clash = self::assetClash('unit', $uid, (string) $o['start_date'], (string) $o['end_date'], (string) $o['id']);
                if ($clash !== null) {
                    throw new DomainError('double_booked', "Unit {$u['serial']} is already on order {$clash} for some of these dates.");
                }
                $serials[] = $u['serial'];
            }
            Db::exec(Db::prepare('DELETE FROM ' . Db::t('order_assets') . ' WHERE order_id = UNHEX(%s) AND line_id = UNHEX(%s) AND asset_type = %s', $o['id'], $line['id'], 'unit'));
            foreach ($unitIds as $uid) {
                Db::insert('order_assets', [
                    'id' => Uuid::v7(), 'order_id' => $o['id'], 'line_id' => $line['id'], 'asset_type' => 'unit', 'asset_id' => $uid,
                    'start_date' => $o['start_date'], 'end_date' => $o['end_date'], 'assigned_by' => $actor->userId, 'created_at' => Db::now(),
                ], ['id', 'order_id', 'line_id', 'asset_id']);
            }
            Audit::log($o['company_id'], $actor->userId, 'order.assign_units', 'order', $o['id'], ['serials' => $serials]);
        });
    }

    /** Give the delivery (start date) or pickup (end date) run of an equipment order a truck and driver. */
    public static function assignRun(Actor $actor, string $orderId, string $kind, string $truckId, int $driverId): void
    {
        $actor->require('cf_assign_fleet');
        $o = Orders::row($orderId);
        if ($o['kind'] !== 'equipment' || $o['fulfilment'] !== 'delivery' || !in_array($kind, ['delivery', 'pickup'], true)) {
            throw new DomainError('invalid_input', 'This order has no ' . $kind . ' run.');
        }
        $allowed = $kind === 'delivery' ? ['confirmed'] : ['confirmed', 'dispatched', 'on_site'];
        if (!in_array($o['status'], $allowed, true)) {
            throw new DomainError('wrong_status', $o['status'] === 'submitted' || $o['status'] === 'requested' ? 'Confirm the order first.' : 'This run can no longer be changed.');
        }
        $date = (string) ($kind === 'delivery' ? $o['start_date'] : $o['end_date']);
        $truckId = Uuid::assertHex($truckId, 'truck');
        if ($driverId <= 0) {
            throw new DomainError('driver_required', 'Choose a driver.', ['field' => 'driver_user_id']);
        }
        Db::transaction(static function () use ($actor, $o, $kind, $date, $truckId, $driverId): void {
            $t = self::lockTruck($truckId);
            self::assertTruckFree($truckId, $date, $date, (string) $o['id'], false);
            self::assertDriver($driverId, $date, $date, (string) $o['id'], true);
            self::upsertTrip($actor, $o, $kind, $date, $truckId, $driverId);
            Audit::log($o['company_id'], $actor->userId, 'order.assign_' . $kind, 'order', $o['id'], ['truck' => $t['plate'], 'driver' => $driverId]);
        });
    }

    // ----- Reading -----------------------------------------------------------------------------

    /** @return array{trucks:list<array<string,mixed>>,units:array<string,list<array<string,mixed>>>,trips:array<string,array<string,mixed>>} */
    public static function assignments(string $orderId): array
    {
        $trucks = Db::rows(Db::prepare(
            'SELECT ' . Db::hex('t.id', 'id') . ', t.plate, t.label, t.odometer_km FROM ' . Db::t('order_assets') . ' a JOIN ' . Db::t('trucks') . ' t ON t.id = a.asset_id
             WHERE a.order_id = UNHEX(%s) AND a.asset_type = %s', $orderId, 'truck'
        ));
        $units = [];
        foreach (Db::rows(Db::prepare(
            'SELECT ' . Db::hex('a.line_id', 'line_id') . ', ' . Db::hex('u.id', 'id') . ', u.serial FROM ' . Db::t('order_assets') . ' a JOIN ' . Db::t('equipment_units') . ' u ON u.id = a.asset_id
             WHERE a.order_id = UNHEX(%s) AND a.asset_type = %s ORDER BY u.serial', $orderId, 'unit'
        )) as $u) {
            $units[$u['line_id']][] = $u;
        }
        $trips = [];
        foreach (Db::rows(Db::prepare(
            'SELECT tr.*, ' . Db::hex('tr.id', 'id') . ', ' . Db::hex('tr.truck_id', 'truck_id') . ', t.plate FROM ' . Db::t('trips') . ' tr LEFT JOIN ' . Db::t('trucks') . ' t ON t.id = tr.truck_id
             WHERE tr.order_id = UNHEX(%s)', $orderId
        )) as $tr) {
            $u = $tr['driver_user_id'] ? get_userdata((int) $tr['driver_user_id']) : null;
            $tr['driver_name'] = $u ? $u->display_name : '';
            $trips[$tr['kind']] = $tr;
        }
        return ['trucks' => $trucks, 'units' => $units, 'trips' => $trips];
    }

    /** What still has to be assigned before the order can go out. @return list<string> */
    public static function gaps(array $o): array
    {
        $a = self::assignments((string) $o['id']);
        $gaps = [];
        if ($o['kind'] === 'truck') {
            if ($a['trucks'] === []) {
                $gaps[] = 'truck';
            }
            if ((int) $o['needs_driver'] && empty($a['trips']['rental'])) {
                $gaps[] = 'driver';
            }
            return $gaps;
        }
        foreach (Db::rows(Db::prepare(
            'SELECT ' . Db::hex('l.id', 'id') . ', l.qty FROM ' . Db::t('order_lines') . ' l JOIN ' . Db::t('equipment_items') . ' i ON i.id = l.item_id
             WHERE l.order_id = UNHEX(%s) AND i.tracking = %s', $o['id'], 'serial'
        )) as $l) {
            if (count($a['units'][$l['id']] ?? []) !== (int) $l['qty']) {
                $gaps[] = 'units';
                break;
            }
        }
        if ($o['fulfilment'] === 'delivery') {
            foreach (['delivery', 'pickup'] as $k) {
                if (empty($a['trips'][$k])) {
                    $gaps[] = $k . ' run';
                }
            }
        }
        return $gaps;
    }

    /** Trucks of a type (or all) that are free for the dates, for the assign form. @return list<array<string,mixed>> */
    public static function freeTrucks(?string $typeId, string $start, string $end, string $orderId, bool $wholePeriod): array
    {
        $rows = Db::rows('SELECT ' . Db::hex('t.id', 'id') . ', ' . Db::hex('t.type_id', 'type_id') . ', t.plate, t.label, t.odometer_km, y.name AS type_name FROM ' . Db::t('trucks') . ' t
            JOIN ' . Db::t('truck_types') . ' y ON y.id = t.type_id WHERE t.status = \'available\''
            . ($typeId ? Db::prepare(' AND t.type_id = UNHEX(%s)', $typeId) : '') . ' ORDER BY y.name, t.plate');
        return array_values(array_filter($rows, static function (array $t) use ($start, $end, $orderId, $wholePeriod): bool {
            try {
                self::assertTruckFree($t['id'], $start, $end, $orderId, $wholePeriod);
                return true;
            } catch (DomainError) {
                return false;
            }
        }));
    }

    /** Drivers free for the dates. @return list<array<string,mixed>> */
    public static function freeDrivers(string $start, string $end, string $orderId, bool $short): array
    {
        return array_values(array_filter(Drivers::usable($start, $end), static function (array $d) use ($start, $end, $orderId, $short): bool {
            return self::driverClash((int) $d['user_id'], $start, $end, $orderId, $short) === null;
        }));
    }

    /** Free units of an item for the dates. @return list<array<string,mixed>> */
    public static function freeUnits(string $itemId, string $start, string $end, string $orderId): array
    {
        $rows = Db::rows(Db::prepare('SELECT ' . Db::hex('id') . ', serial, hour_meter FROM ' . Db::t('equipment_units') . ' WHERE item_id = UNHEX(%s) AND status = %s ORDER BY serial', $itemId, 'available'));
        return array_values(array_filter($rows, static fn(array $u): bool => self::assetClash('unit', $u['id'], $start, $end, $orderId) === null));
    }

    /**
     * Dispatch board: what goes out and comes back over the next days, what is overdue,
     * and confirmed orders still missing assignments.
     *
     * @return array<string, list<array<string,mixed>>>
     */
    public static function board(Actor $actor, int $days = 7): array
    {
        $actor->require('cf_dispatch');
        $today = Db::todayManila()->format('Y-m-d');
        $until = Db::todayManila()->modify('+' . ($days - 1) . ' days')->format('Y-m-d');
        $soon = Db::todayManila()->modify('+14 days')->format('Y-m-d');
        $rows = Db::rows(Db::prepare(
            'SELECT o.*, ' . Db::hex('o.id', 'id') . ', ' . Db::hex('o.company_id', 'company_id') . ', c.name AS company_name,
                (SELECT GROUP_CONCAT(CONCAT(l.qty, \' × \', l.description) ORDER BY l.sort SEPARATOR \', \') FROM ' . Db::t('order_lines') . ' l WHERE l.order_id = o.id AND l.line_type <> \'delivery\') AS summary
             FROM ' . Db::t('orders') . ' o JOIN ' . Db::t('companies') . ' c ON c.id = o.company_id
             WHERE o.status IN (%s, %s, %s, %s) AND (o.start_date <= %s OR o.end_date <= %s) ORDER BY o.start_date',
            'confirmed', 'dispatched', 'on_site', 'returning', $soon, $until
        ));
        $out = ['assign' => [], 'out' => [], 'back' => [], 'overdue' => []];
        foreach ($rows as $o) {
            $o['gaps'] = $o['status'] === 'confirmed' ? self::gaps($o) : [];
            if ($o['status'] === 'confirmed' && $o['gaps'] !== []) {
                $out['assign'][] = $o;
            }
            if ($o['status'] === 'confirmed' && $o['start_date'] <= $until) {
                $out['out'][] = $o;
            }
            if (in_array($o['status'], ['dispatched', 'on_site', 'returning'], true)) {
                if ($o['end_date'] < $today) {
                    $out['overdue'][] = $o;
                } elseif ($o['end_date'] <= $until) {
                    $out['back'][] = $o;
                }
            }
        }
        usort($out['back'], static fn($a, $b) => strcmp($a['end_date'], $b['end_date']));
        return $out;
    }

    /**
     * Calendar: for each truck and heavy unit, what it is doing each day from $from for $days.
     *
     * @return list<array{label:string,sub:string,kind:string,days:array<string,list<array{ref:string,id:string,what:string,status:string}>>}>
     */
    public static function calendar(Actor $actor, string $from, int $days = 14): array
    {
        $actor->require('cf_dispatch');
        $to = (new \DateTimeImmutable($from))->modify('+' . ($days - 1) . ' days')->format('Y-m-d');
        $active = Orders::ACTIVE;
        $in = implode(',', array_fill(0, count($active), '%s'));
        $rowsOut = [];
        $put = static function (string $key, string $d1, string $d2, array $cell) use (&$rowsOut, $from, $to): void {
            $day = new \DateTimeImmutable(max($d1, $from));
            $last = new \DateTimeImmutable(min($d2, $to));
            while ($day <= $last) {
                $rowsOut[$key][$day->format('Y-m-d')][] = $cell;
                $day = $day->modify('+1 day');
            }
        };
        foreach (Db::rows(Db::prepare(
            'SELECT a.asset_type, ' . Db::hex('a.asset_id', 'asset_id') . ', a.start_date, a.end_date, o.ref, o.status, ' . Db::hex('o.id', 'order_id') . ' FROM ' . Db::t('order_assets') . ' a
             JOIN ' . Db::t('orders') . ' o ON o.id = a.order_id WHERE o.status IN (' . $in . ') AND a.start_date <= %s AND a.end_date >= %s',
            ...array_merge($active, [$to, $from])
        )) as $r) {
            $put($r['asset_type'] . ':' . $r['asset_id'], $r['start_date'], $r['end_date'], ['ref' => $r['ref'], 'id' => $r['order_id'], 'what' => 'rental', 'status' => $r['status']]);
        }
        foreach (Db::rows(Db::prepare(
            'SELECT tr.kind, ' . Db::hex('tr.truck_id', 'truck_id') . ', tr.trip_date, o.ref, tr.status, ' . Db::hex('o.id', 'order_id') . ' FROM ' . Db::t('trips') . ' tr
             JOIN ' . Db::t('orders') . ' o ON o.id = tr.order_id WHERE tr.kind <> %s AND tr.truck_id IS NOT NULL AND o.status IN (' . $in . ') AND tr.trip_date BETWEEN %s AND %s',
            ...array_merge(['rental'], $active, [$from, $to])
        )) as $r) {
            $put('truck:' . $r['truck_id'], $r['trip_date'], $r['trip_date'], ['ref' => $r['ref'], 'id' => $r['order_id'], 'what' => $r['kind'], 'status' => $r['status']]);
        }
        $out = [];
        foreach (Db::rows('SELECT ' . Db::hex('t.id', 'id') . ', t.plate, t.status, y.name AS type_name FROM ' . Db::t('trucks') . ' t JOIN ' . Db::t('truck_types') . ' y ON y.id = t.type_id WHERE t.status <> \'retired\' ORDER BY y.name, t.plate') as $t) {
            $out[] = ['label' => $t['plate'], 'sub' => $t['type_name'] . ($t['status'] === 'repair' ? ' · in repair' : ''), 'kind' => 'truck', 'days' => $rowsOut['truck:' . $t['id']] ?? []];
        }
        foreach (Db::rows('SELECT ' . Db::hex('u.id', 'id') . ', u.serial, u.status, i.name FROM ' . Db::t('equipment_units') . ' u JOIN ' . Db::t('equipment_items') . ' i ON i.id = u.item_id WHERE u.status <> \'retired\' ORDER BY i.name, u.serial') as $u) {
            $out[] = ['label' => $u['serial'], 'sub' => $u['name'] . ($u['status'] === 'repair' ? ' · in repair' : ''), 'kind' => 'unit', 'days' => $rowsOut['unit:' . $u['id']] ?? []];
        }
        return $out;
    }

    // ----- internals ---------------------------------------------------------------------------

    private static function assertAssignable(array $o): void
    {
        if ($o['status'] !== 'confirmed') {
            throw new DomainError('wrong_status', in_array($o['status'], ['requested', 'submitted', 'needs_fix'], true)
                ? 'Confirm the order first: trucks, units and drivers are assigned only to confirmed orders.'
                : 'This order has already gone out; its assignment can no longer be changed.');
        }
    }

    /** @return array<string,mixed> */
    private static function lockTruck(string $truckId): array
    {
        $t = Db::row(Db::prepare('SELECT ' . Db::hex('type_id') . ', plate, status FROM ' . Db::t('trucks') . ' WHERE id = UNHEX(%s) FOR UPDATE', $truckId));
        if ($t === null) {
            throw new DomainError('not_found', 'Truck not found.', ['field' => 'truck_id']);
        }
        if ($t['status'] !== 'available') {
            throw new DomainError('truck_unavailable', "Truck {$t['plate']} is not available (" . ($t['status'] === 'repair' ? 'in repair' : $t['status']) . ').', ['field' => 'truck_id']);
        }
        return $t;
    }

    /**
     * A truck is busy if it is on another active order's rental for any of the dates. For a whole rental
     * ($wholePeriod), delivery and pickup runs on those dates also count.
     */
    private static function assertTruckFree(string $truckId, string $start, string $end, string $orderId, bool $wholePeriod): void
    {
        $clash = self::assetClash('truck', $truckId, $start, $end, $orderId);
        if ($clash === null && $wholePeriod) {
            $clash = Db::value(Db::prepare(
                'SELECT o.ref FROM ' . Db::t('trips') . ' tr JOIN ' . Db::t('orders') . ' o ON o.id = tr.order_id
                 WHERE tr.truck_id = UNHEX(%s) AND tr.kind <> %s AND tr.status NOT IN (%s, %s) AND tr.order_id <> UNHEX(%s)
                   AND o.status IN (' . implode(',', array_fill(0, count(Orders::ACTIVE), '%s')) . ') AND tr.trip_date BETWEEN %s AND %s LIMIT 1',
                ...array_merge([$truckId, 'rental', 'back', 'cancelled', $orderId], Orders::ACTIVE, [$start, $end])
            ));
        }
        if ($clash !== null) {
            throw new DomainError('double_booked', "This truck is already on order {$clash} for some of these dates.", ['field' => 'truck_id']);
        }
    }

    /** Ref of another active order holding this asset on overlapping dates, or null. */
    private static function assetClash(string $type, string $assetId, string $start, string $end, string $orderId): ?string
    {
        $ref = Db::value(Db::prepare(
            'SELECT o.ref FROM ' . Db::t('order_assets') . ' a JOIN ' . Db::t('orders') . ' o ON o.id = a.order_id
             WHERE a.asset_type = %s AND a.asset_id = UNHEX(%s) AND a.order_id <> UNHEX(%s)
               AND o.status IN (' . implode(',', array_fill(0, count(Orders::ACTIVE), '%s')) . ') AND a.start_date <= %s AND a.end_date >= %s LIMIT 1',
            ...array_merge([$type, $assetId, $orderId], Orders::ACTIVE, [$end, $start])
        ));
        return $ref === null ? null : (string) $ref;
    }

    private static function assertDriver(int $driverId, string $start, string $end, string $orderId, bool $short): void
    {
        $d = Drivers::get($driverId);
        if ($d === null || !$d['active']) {
            throw new DomainError('not_found', 'Driver not found.', ['field' => 'driver_user_id']);
        }
        if ($d['license_expiry'] === null || $d['license_expiry'] < $end) {
            throw new DomainError('license_expires', "{$d['name']}'s licence is missing or expires before " . $end . '.', ['field' => 'driver_user_id']);
        }
        // Lock the driver's trips so two dispatchers cannot book the same driver at once.
        Db::exec(Db::prepare('SELECT id FROM ' . Db::t('trips') . ' WHERE driver_user_id = %d FOR UPDATE', $driverId));
        $clash = self::driverClash($driverId, $start, $end, $orderId, $short);
        if ($clash !== null) {
            throw new DomainError('double_booked', "{$d['name']} is already driving order {$clash} on these dates.", ['field' => 'driver_user_id']);
        }
    }

    /**
     * A driver on a rental trip is busy for the whole rental. For a whole rental ($short = false),
     * delivery and pickup runs on those dates also clash; short runs can share a day.
     */
    private static function driverClash(int $driverId, string $start, string $end, string $orderId, bool $short): ?string
    {
        $ref = Db::value(Db::prepare(
            'SELECT o.ref FROM ' . Db::t('trips') . ' tr JOIN ' . Db::t('orders') . ' o ON o.id = tr.order_id
             WHERE tr.driver_user_id = %d AND tr.order_id <> UNHEX(%s) AND tr.status NOT IN (%s, %s)
               AND o.status IN (' . implode(',', array_fill(0, count(Orders::ACTIVE), '%s')) . ')
               AND ((tr.kind = %s AND o.start_date <= %s AND o.end_date >= %s)' . ($short ? '' : ' OR (tr.kind <> %s AND tr.trip_date BETWEEN %s AND %s)') . ') LIMIT 1',
            ...array_merge([$driverId, $orderId, 'back', 'cancelled'], Orders::ACTIVE, ['rental', $end, $start], $short ? [] : ['rental', $start, $end])
        ));
        return $ref === null ? null : (string) $ref;
    }

    private static function upsertTrip(Actor $actor, array $o, string $kind, string $date, string $truckId, int $driverId): void
    {
        $existing = Db::row(Db::prepare('SELECT ' . Db::hex('id') . ', status FROM ' . Db::t('trips') . ' WHERE order_id = UNHEX(%s) AND kind = %s FOR UPDATE', $o['id'], $kind));
        if ($existing !== null) {
            if ($existing['status'] !== 'planned') {
                throw new DomainError('trip_started', 'This trip has already started and cannot be reassigned.');
            }
            Db::exec(Db::prepare(
                'UPDATE ' . Db::t('trips') . ' SET truck_id = UNHEX(%s), driver_user_id = %d, trip_date = %s, updated_at = %s WHERE id = UNHEX(%s)',
                $truckId, $driverId, $date, Db::now(), $existing['id']
            ));
            return;
        }
        Db::insert('trips', [
            'id' => Uuid::v7(), 'order_id' => $o['id'], 'kind' => $kind, 'trip_date' => $date, 'truck_id' => $truckId,
            'driver_user_id' => $driverId, 'status' => 'planned', 'created_by' => $actor->userId, 'created_at' => Db::now(),
        ], ['id', 'order_id', 'truck_id']);
    }
}
