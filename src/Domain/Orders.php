<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Sequence;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Orders: quoting and booking trucks and equipment, holds, cancellation, listing.
 *
 * Prices are always worked out here from the catalog, never taken from the request.
 * Booking locks the truck type / item rows (SELECT ... FOR UPDATE) before checking availability,
 * so two customers can never book the last unit at the same moment.
 * A new order is "requested" and holds its trucks/items until hold_expires_at; after that it
 * no longer blocks anything and the hourly job marks it expired.
 */
final class Orders
{
    /** Statuses that keep trucks and stock reserved. "requested" counts only while its hold lasts. */
    public const ACTIVE = ['requested', 'submitted', 'needs_fix', 'confirmed', 'dispatched', 'on_site', 'returning'];

    public const STATUS_LABELS = [
        'requested' => 'Held — requirements needed',
        'submitted' => 'Waiting for Canpas check',
        'needs_fix' => 'Needs your action',
        'confirmed' => 'Confirmed',
        'dispatched' => 'On the way',
        'on_site' => 'On site',
        'returning' => 'Being returned',
        'returned' => 'Returned',
        'closed' => 'Closed',
        'cancelled' => 'Cancelled',
        'declined' => 'Declined',
        'expired' => 'Hold expired',
    ];

    public const MAX_DAYS = 365;

    /** Order number prefix. The self-test switches it to TST so it never uses up real order numbers. */
    public static string $refPrefix = 'ORD';

    // ----- Quotes ------------------------------------------------------------------------------

    /** @param array<string, mixed> $in @return array<string, mixed> priced draft, nothing saved */
    public static function quoteTruck(Actor $actor, array $in): array
    {
        $actor->require('cf_book');
        [$start, $end, $days] = self::dates($in);
        $type = self::truckType((string) ($in['truck_type_id'] ?? ''));
        [$address, $lat, $lng] = self::site($in);
        $dist = Distance::roundTrip($lat, $lng);
        $driver = !empty($in['needs_driver']) && $in['needs_driver'] !== '0';
        $p = Pricing::truckTrip($type, $dist['km'], $driver, $days, (string) Settings::get('driver_km_basis'));
        $deposit = Pricing::deposit($type['deposit_mode'], (float) $type['deposit_value'], $p['total']);
        $line = [
            'line_type' => 'truck', 'truck_type_id' => $type['id'], 'item_id' => null,
            'description' => $type['name'] . ($driver ? ' with Canpas driver' : ''),
            'qty' => 1, 'charged_days' => $days, 'unit_price' => $p['total'], 'amount' => $p['total'], 'deposit' => $deposit,
            'rates' => self::truckRates($type), 'breakdown' => $p,
        ];
        // A self-drive truck needs the customer's driver's licence.
        return ['license_required' => !$driver] + self::draft('truck', $start, $end, $days, 'delivery', $driver, $address, $lat, $lng, $dist, [$line], $in);
    }

    /** @param array<string, mixed> $in @return array<string, mixed> */
    public static function quoteEquipment(Actor $actor, array $in): array
    {
        $actor->require('cf_book');
        [$start, $end, $days] = self::dates($in);
        $qty = self::quantities($in);
        if ($qty === []) {
            throw new DomainError('invalid_input', 'Enter a quantity for at least one item.', ['field' => 'qty']);
        }
        $lines = [];
        $heavy = false;
        foreach ($qty as $itemId => $n) {
            $item = Db::row(Db::prepare('SELECT *, ' . Db::hex('id') . ' FROM ' . Db::t('equipment_items') . ' WHERE id = UNHEX(%s) AND active = 1', $itemId));
            if ($item === null) {
                throw new DomainError('invalid_input', 'One of the items is no longer offered. Refresh the page.', ['field' => 'qty']);
            }
            $e = Pricing::equipment($item, $n, $days);
            $heavy = $heavy || $item['category'] === 'heavy';
            $lines[] = [
                'line_type' => 'item', 'truck_type_id' => null, 'item_id' => $item['id'],
                'description' => $item['name'], 'qty' => $n, 'charged_days' => $e['charged_days'],
                'unit_price' => $e['unit_price'], 'amount' => $e['amount'],
                'deposit' => Pricing::deposit($item['deposit_mode'], (float) $item['deposit_value'], $e['amount']),
                'rates' => array_intersect_key($item, array_flip(['name', 'unit', 'rate_day', 'rate_week', 'rate_month', 'min_days', 'deposit_mode', 'deposit_value', 'replacement_cost', 'damage_fee'])),
                'breakdown' => $e + ['period' => Pricing::periodLabel($e)],
            ];
        }
        $fulfilment = ($in['fulfilment'] ?? 'delivery') === 'pickup' ? 'pickup' : 'delivery';
        $address = null;
        $lat = $lng = null;
        $dist = null;
        if ($fulfilment === 'delivery') {
            [$address, $lat, $lng] = self::site($in);
            $dist = Distance::roundTrip($lat, $lng);
            $type = self::deliveryTruckType();
            $trips = (int) Settings::get('trips_per_rental');
            $trip = Pricing::truckTrip($type, $dist['km'], true, 0, (string) Settings::get('driver_km_basis'));
            $lines[] = [
                'line_type' => 'delivery', 'truck_type_id' => $type['id'], 'item_id' => null,
                'description' => $trips === 2 ? 'Delivery and pickup (' . $type['name'] . ', with driver)' : 'Delivery (' . $type['name'] . ', with driver)',
                'qty' => $trips, 'charged_days' => 0, 'unit_price' => $trip['total'], 'amount' => round($trip['total'] * $trips, 2), 'deposit' => 0.0,
                'rates' => self::truckRates($type), 'breakdown' => $trip,
            ];
        }
        // Heavy equipment needs the operator's licence.
        return ['license_required' => $heavy] + self::draft('equipment', $start, $end, $days, $fulfilment, false, $address, $lat, $lng, $dist, $lines, $in);
    }

    // ----- Booking -----------------------------------------------------------------------------

    /** @param array<string, mixed> $in */
    public static function bookTruck(Actor $actor, array $in): string
    {
        return self::book($actor, self::quoteTruck($actor, $in));
    }

    /** @param array<string, mixed> $in */
    public static function bookEquipment(Actor $actor, array $in): string
    {
        return self::book($actor, self::quoteEquipment($actor, $in));
    }

    /** @param array<string, mixed> $d */
    private static function book(Actor $actor, array $d): string
    {
        $actor->require('cf_book');
        $companyId = (string) $actor->companyId;
        if ($companyId === '') {
            throw new DomainError('forbidden', 'Only customer accounts can place orders.');
        }
        return Db::transaction(static function () use ($actor, $d, $companyId): string {
            // Lock what is being booked, then check what is left for these dates.
            foreach ($d['lines'] as $l) {
                if ($l['line_type'] === 'truck') {
                    Db::value(Db::prepare('SELECT id FROM ' . Db::t('truck_types') . ' WHERE id = UNHEX(%s) FOR UPDATE', $l['truck_type_id']));
                    $free = self::truckTypeCapacity($l['truck_type_id']) - self::peakBooked('truck_type_id', $l['truck_type_id'], $d['start'], $d['end'], 'truck');
                    if ($free < 1) {
                        throw new DomainError('not_available', "No {$l['description']} is free for these dates. Try other dates or another truck type.");
                    }
                } elseif ($l['line_type'] === 'item') {
                    Db::value(Db::prepare('SELECT id FROM ' . Db::t('equipment_items') . ' WHERE id = UNHEX(%s) FOR UPDATE', $l['item_id']));
                    $free = self::itemCapacity($l['item_id']) - self::peakBooked('item_id', $l['item_id'], $d['start'], $d['end'], 'item');
                    if ($free < $l['qty']) {
                        throw new DomainError('not_available', sprintf('Only %d × %s free for these dates.', max(0, $free), $l['description']), ['item_id' => $l['item_id'], 'free' => max(0, $free)]);
                    }
                }
            }
            $id = Uuid::v7();
            $ref = Sequence::ref(self::$refPrefix);
            $hold = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . (int) Settings::get('hold_hours') . ' hours')->format('Y-m-d H:i:s.u');
            Db::insert('orders', [
                'id' => $id, 'ref' => $ref, 'company_id' => $companyId, 'created_by' => $actor->userId,
                'kind' => $d['kind'], 'status' => 'requested',
                'start_date' => $d['start'], 'end_date' => $d['end'], 'days' => $d['days'],
                'fulfilment' => $d['fulfilment'], 'needs_driver' => $d['needs_driver'] ? 1 : 0, 'license_required' => $d['license_required'] ? 1 : 0,
                'site_address' => $d['site_address'], 'site_lat' => $d['site_lat'], 'site_lng' => $d['site_lng'],
                'distance_km' => $d['distance_km'], 'distance_source' => $d['distance_source'],
                'subtotal' => $d['subtotal'], 'deposit_amount' => $d['deposit'], 'total' => $d['total'],
                'hold_expires_at' => $hold, 'notes' => $d['notes'], 'created_at' => Db::now(),
            ], ['id', 'company_id']);
            foreach ($d['lines'] as $i => $l) {
                Db::insert('order_lines', [
                    'id' => Uuid::v7(), 'order_id' => $id, 'line_type' => $l['line_type'],
                    'truck_type_id' => $l['truck_type_id'], 'item_id' => $l['item_id'],
                    'description' => mb_substr($l['description'], 0, 255), 'qty' => $l['qty'], 'charged_days' => $l['charged_days'],
                    'unit_price' => $l['unit_price'], 'amount' => $l['amount'], 'deposit' => $l['deposit'],
                    'rates_json' => wp_json_encode($l['rates']), 'breakdown_json' => wp_json_encode($l['breakdown']),
                    'sort' => $i, 'created_at' => Db::now(),
                ], ['id', 'order_id', 'truck_type_id', 'item_id']);
            }
            Audit::log($companyId, $actor->userId, 'order.create', 'order', $id, ['ref' => $ref, 'total' => $d['total'], 'kind' => $d['kind']]);
            return $id;
        });
    }

    // ----- Status changes ----------------------------------------------------------------------

    /** Customers may cancel their own order before it is sent to Canpas; staff may cancel with a reason. */
    public static function cancel(Actor $actor, string $orderId, string $reason = ''): void
    {
        $o = self::row($orderId);
        $staff = $actor->can('cf_manage_orders');
        if (!$staff) {
            $actor->require('cf_book');
            self::assertCustomerAccess($actor, $o);
        }
        if (!in_array($o['status'], ['requested', 'needs_fix'], true)) {
            throw new DomainError('wrong_status', 'Only orders not yet sent to Canpas can be cancelled here. Contact Canpas.');
        }
        $reason = sanitize_text_field(wp_unslash($reason));
        if ($staff && mb_strlen($reason) < 5) {
            throw new DomainError('reason_required', 'Give a short reason (at least 5 characters). It is shown to the customer.', ['field' => 'reason']);
        }
        Db::transaction(static function () use ($actor, $o, $reason): void {
            Db::exec(Db::prepare(
                'UPDATE ' . Db::t('orders') . ' SET status = %s, status_reason = %s, status_changed_by = %d, status_changed_at = %s, updated_at = %s WHERE id = UNHEX(%s) AND status IN (%s, %s)',
                'cancelled', $reason !== '' ? mb_substr($reason, 0, 255) : 'Cancelled by customer', $actor->userId, Db::now(), Db::now(), $o['id'], 'requested', 'needs_fix'
            ));
            Audit::log($o['company_id'], $actor->userId, 'order.cancel', 'order', $o['id'], ['by' => $actor->isStaff() ? 'staff' : 'customer']);
        });
    }

    /** Mark held orders past their hold time as expired. Runs hourly; safe to run any time. */
    public static function expireHolds(): int
    {
        return Db::exec(Db::prepare(
            'UPDATE ' . Db::t('orders') . ' SET status = %s, status_reason = %s, status_changed_at = %s, updated_at = %s WHERE status = %s AND hold_expires_at < %s',
            'expired', 'Requirements were not completed in time', Db::now(), Db::now(), 'requested', Db::now()
        ));
    }

    // ----- Reading -----------------------------------------------------------------------------

    /** @return list<array<string, mixed>> */
    public static function list(Actor $actor, ?string $status = null, int $limit = 200): array
    {
        $where = [];
        if ($actor->can('cf_manage_orders')) {
            // staff see everything
        } elseif ($actor->can('cf_view_company_orders')) {
            $where[] = Db::prepare('o.company_id = UNHEX(%s)', (string) $actor->companyId);
        } elseif ($actor->can('cf_book')) {
            $where[] = Db::prepare('o.company_id = UNHEX(%s) AND o.created_by = %d', (string) $actor->companyId, $actor->userId);
        } else {
            throw new DomainError('forbidden', 'You do not have permission to do this.');
        }
        if ($status !== null && $status !== '') {
            if (!isset(self::STATUS_LABELS[$status])) {
                throw new DomainError('invalid_input', 'Unknown status.');
            }
            $where[] = Db::prepare('o.status = %s', $status);
        }
        return Db::rows('SELECT ' . Db::hex('o.id', 'id') . ', o.ref, o.kind, o.status, o.start_date, o.end_date, o.days, o.fulfilment, o.site_address, o.total, o.deposit_amount, o.hold_expires_at, o.created_at, o.created_by, c.name AS company_name,
                (SELECT GROUP_CONCAT(CONCAT(l.qty, \' × \', l.description) ORDER BY l.sort SEPARATOR \', \') FROM ' . Db::t('order_lines') . ' l WHERE l.order_id = o.id AND l.line_type <> \'delivery\') AS summary
            FROM ' . Db::t('orders') . ' o JOIN ' . Db::t('companies') . ' c ON c.id = o.company_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY o.created_at DESC LIMIT ' . max(1, min(500, $limit)));
    }

    /** @return array<string, mixed> order with lines */
    public static function get(Actor $actor, string $orderId): array
    {
        $o = self::row($orderId);
        if (!$actor->can('cf_manage_orders')) {
            self::assertCustomerAccess($actor, $o);
        }
        $o['lines'] = Db::rows(Db::prepare('SELECT line_type, description, qty, charged_days, unit_price, amount, deposit, breakdown_json, rates_json FROM ' . Db::t('order_lines') . ' WHERE order_id = UNHEX(%s) ORDER BY sort', $o['id']));
        $o['company_name'] = (string) Db::value(Db::prepare('SELECT name FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $o['company_id']));
        $u = get_userdata((int) $o['created_by']);
        $o['created_by_name'] = $u ? $u->display_name : '';
        $o['hold_active'] = $o['status'] === 'requested' && $o['hold_expires_at'] > Db::now();
        return $o;
    }

    /** @return array<string, int> count per status */
    public static function counts(): array
    {
        $out = [];
        foreach (Db::rows('SELECT status, COUNT(*) AS n FROM ' . Db::t('orders') . ' GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    // ----- Availability ------------------------------------------------------------------------

    public static function truckTypeCapacity(string $typeId): int
    {
        return (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('trucks') . ' WHERE type_id = UNHEX(%s) AND status = %s', $typeId, 'available'));
    }

    public static function itemCapacity(string $itemId): int
    {
        $tracking = Db::value(Db::prepare('SELECT tracking FROM ' . Db::t('equipment_items') . ' WHERE id = UNHEX(%s)', $itemId));
        return $tracking === 'serial'
            ? (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('equipment_units') . ' WHERE item_id = UNHEX(%s) AND status = %s', $itemId, 'available'))
            : Stock::onHand($itemId);
    }

    /** Free quantity for an item over a date range (capacity minus the busiest day). */
    public static function itemFree(string $itemId, string $start, string $end): int
    {
        return max(0, self::itemCapacity($itemId) - self::peakBooked('item_id', $itemId, $start, $end, 'item'));
    }

    /**
     * The highest quantity reserved on any single day in [start, end], across all active orders.
     * Day-by-day peak, so two orders that both touch the range but never overlap each other are not added together.
     */
    public static function peakBooked(string $column, string $id, string $start, string $end, string $lineType, ?string $excludeOrder = null): int
    {
        $rows = Db::rows(Db::prepare(
            'SELECT o.start_date, o.end_date, l.qty FROM ' . Db::t('order_lines') . ' l JOIN ' . Db::t('orders') . ' o ON o.id = l.order_id
             WHERE l.' . ($column === 'truck_type_id' ? 'truck_type_id' : 'item_id') . ' = UNHEX(%s) AND l.line_type = %s
               AND o.status IN (' . implode(',', array_fill(0, count(self::ACTIVE), '%s')) . ')
               AND NOT (o.status = \'requested\' AND o.hold_expires_at < %s)
               AND o.start_date <= %s AND o.end_date >= %s' . ($excludeOrder ? ' AND o.id <> UNHEX(%s)' : ''),
            ...array_merge([$id, $lineType], self::ACTIVE, [Db::now(), $end, $start], $excludeOrder ? [$excludeOrder] : [])
        ));
        if ($rows === []) {
            return 0;
        }
        $peak = 0;
        $day = new \DateTimeImmutable($start);
        $last = new \DateTimeImmutable($end);
        while ($day <= $last) {
            $d = $day->format('Y-m-d');
            $sum = 0;
            foreach ($rows as $r) {
                if ($r['start_date'] <= $d && $r['end_date'] >= $d) {
                    $sum += (int) $r['qty'];
                }
            }
            $peak = max($peak, $sum);
            $day = $day->modify('+1 day');
        }
        return $peak;
    }

    // ----- Internals ---------------------------------------------------------------------------

    /** @return array{0:string,1:string,2:int} start, end (inclusive), days */
    private static function dates(array $in): array
    {
        $start = (string) ($in['start_date'] ?? '');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $start, new \DateTimeZone('Asia/Manila'));
        if (!$d || $d->format('Y-m-d') !== $start) {
            throw new DomainError('invalid_input', 'Choose a start date.', ['field' => 'start_date']);
        }
        $today = Db::todayManila();
        if ($d < $today) {
            throw new DomainError('invalid_input', 'The start date cannot be in the past.', ['field' => 'start_date']);
        }
        if ($d > $today->modify('+365 days')) {
            throw new DomainError('invalid_input', 'Bookings can be made up to one year ahead.', ['field' => 'start_date']);
        }
        $days = (int) ($in['days'] ?? 0);
        if ($days < 1 || $days > self::MAX_DAYS) {
            throw new DomainError('invalid_input', 'Number of days must be between 1 and 365.', ['field' => 'days']);
        }
        return [$start, $d->modify('+' . ($days - 1) . ' days')->format('Y-m-d'), $days];
    }

    /** @return array{0:string,1:float,2:float} address, lat, lng */
    private static function site(array $in): array
    {
        $address = sanitize_text_field(wp_unslash((string) ($in['site_address'] ?? '')));
        if (mb_strlen($address) < 5) {
            throw new DomainError('invalid_input', 'Enter the site address.', ['field' => 'site_address']);
        }
        if (isset($in['site_lat'], $in['site_lng']) && $in['site_lat'] !== '' && $in['site_lng'] !== '') {
            [$lat, $lng] = Distance::parseCoords($in['site_lat'] . ',' . $in['site_lng']);
        } else {
            [$lat, $lng] = Distance::parseCoords((string) ($in['site_coords'] ?? ''));
        }
        return [mb_substr($address, 0, 255), $lat, $lng];
    }

    /** @return array<string,int> item id => qty (> 0) from qty[id] form fields or a qty map */
    private static function quantities(array $in): array
    {
        $raw = is_array($in['qty'] ?? null) ? $in['qty'] : [];
        foreach ($in as $k => $v) {
            if (is_string($k) && preg_match('/^qty\[([0-9a-f]{32})\]$/', $k, $m)) {
                $raw[$m[1]] = $v;
            }
        }
        $out = [];
        foreach ($raw as $id => $n) {
            $n = (int) $n;
            if ($n < 0 || $n > 100000) {
                throw new DomainError('invalid_input', 'Quantities must be between 0 and 100,000.', ['field' => 'qty']);
            }
            if ($n > 0 && Uuid::isHex((string) $id)) {
                $out[(string) $id] = $n;
            }
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function truckType(string $id): array
    {
        if (!Uuid::isHex($id)) {
            throw new DomainError('invalid_input', 'Choose a truck type.', ['field' => 'truck_type_id']);
        }
        $t = Db::row(Db::prepare('SELECT *, ' . Db::hex('id') . ' FROM ' . Db::t('truck_types') . ' WHERE id = UNHEX(%s) AND active = 1', $id));
        if ($t === null) {
            throw new DomainError('invalid_input', 'This truck type is no longer offered.', ['field' => 'truck_type_id']);
        }
        return $t;
    }

    /** The truck type used to price equipment delivery: the one set in Settings, else the cheapest offered. */
    private static function deliveryTruckType(): array
    {
        $id = (string) Settings::get('delivery_truck_type');
        if (Uuid::isHex($id)) {
            $t = Db::row(Db::prepare('SELECT *, ' . Db::hex('id') . ' FROM ' . Db::t('truck_types') . ' WHERE id = UNHEX(%s) AND active = 1', $id));
            if ($t) {
                return $t;
            }
        }
        $t = Db::row('SELECT *, ' . Db::hex('id') . ' FROM ' . Db::t('truck_types') . ' WHERE active = 1 ORDER BY starting_fee, name LIMIT 1');
        if ($t === null) {
            throw new DomainError('no_delivery', 'Delivery is not available yet. Choose pickup at the Canpas yard, or contact Canpas.', ['field' => 'fulfilment']);
        }
        return $t;
    }

    private static function truckRates(array $t): array
    {
        return array_intersect_key($t, array_flip(['name', 'starting_fee', 'rate_per_km', 'driver_flat_fee', 'driver_flat_km', 'driver_rate_per_km', 'daily_rate', 'free_km', 'deposit_mode', 'deposit_value']))
            + ['driver_km_basis' => (string) Settings::get('driver_km_basis')];
    }

    /** @param list<array<string,mixed>> $lines @param array{km:float,source:string}|null $dist */
    private static function draft(string $kind, string $start, string $end, int $days, string $fulfilment, bool $driver, ?string $address, ?float $lat, ?float $lng, ?array $dist, array $lines, array $in): array
    {
        $subtotal = round(array_sum(array_column($lines, 'amount')), 2);
        $deposit = round(array_sum(array_column($lines, 'deposit')), 2);
        $notes = sanitize_textarea_field(wp_unslash((string) ($in['notes'] ?? '')));
        return [
            'kind' => $kind, 'start' => $start, 'end' => $end, 'days' => $days,
            'fulfilment' => $fulfilment, 'needs_driver' => $driver,
            'site_address' => $address, 'site_lat' => $lat, 'site_lng' => $lng,
            'distance_km' => $dist['km'] ?? null, 'distance_source' => $dist['source'] ?? null,
            'lines' => $lines, 'subtotal' => $subtotal, 'deposit' => $deposit, 'total' => $subtotal,
            'notes' => $notes !== '' ? mb_substr($notes, 0, 500) : null,
        ];
    }

    /** @return array<string,mixed> order row, ids as hex. No permission check: callers check access. */
    public static function row(string $orderId): array
    {
        $orderId = Uuid::assertHex($orderId, 'order');
        $o = Db::row(Db::prepare('SELECT *, ' . Db::hex('id') . ', ' . Db::hex('company_id') . ', ' . Db::hex('gov_id_doc') . ', ' . Db::hex('license_doc') . ', ' . Db::hex('agreement_version_id')
            . ' FROM ' . Db::t('orders') . ' WHERE id = UNHEX(%s)', $orderId));
        if ($o === null) {
            throw new DomainError('not_found', 'Order not found.');
        }
        return $o;
    }

    /** Customers see their company's orders (company admin) or the ones they placed (company user). */
    public static function assertCustomerAccess(Actor $actor, array $o): void
    {
        $sameCompany = $actor->companyId !== null && $actor->companyId === $o['company_id'];
        $mine = (int) $o['created_by'] === $actor->userId;
        if (!$sameCompany || (!$actor->can('cf_view_company_orders') && !$mine)) {
            throw new DomainError('not_found', 'Order not found.');
        }
    }
}
