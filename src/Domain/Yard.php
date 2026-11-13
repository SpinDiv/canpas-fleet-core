<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Files;
use Canpas\Fleet\Support\Signature;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Actor;

/**
 * What happens at the Canpas yard:
 *
 * - Loading equipment: the yard counts out each line (the booked quantity) and checks the heavy units.
 *   For customer pickup the receiver signs and the order is out; for delivery the driver then leaves.
 * - Releasing a self-drive truck: odometer, photos, and the customer's driver signs.
 * - Return inspection: trucks get the final odometer, fuel shortfall and damage; equipment is counted
 *   back in as good, damaged or missing. Damaged and missing stock leaves the available stock
 *   (stock ledger), damaged heavy units go to repair. The order becomes Returned, ready for the final bill.
 */
final class Yard
{
    /** @return list<array<string,mixed>> lines with item info, units and the counts so far */
    public static function lines(array $o): array
    {
        $assigned = Dispatch::assignments((string) $o['id'])['units'];
        $rows = Db::rows(Db::prepare(
            'SELECT ' . Db::hex('l.id', 'id') . ', ' . Db::hex('l.item_id', 'item_id') . ', l.qty, l.description, i.tracking, i.unit
             FROM ' . Db::t('order_lines') . ' l JOIN ' . Db::t('equipment_items') . ' i ON i.id = l.item_id
             WHERE l.order_id = UNHEX(%s) AND l.line_type = %s ORDER BY l.sort', $o['id'], 'item'
        ));
        $counts = [];
        foreach (Db::rows(Db::prepare('SELECT ' . Db::hex('line_id') . ', direction, qty_good, qty_damaged, qty_missing FROM ' . Db::t('order_counts') . ' WHERE order_id = UNHEX(%s)', $o['id'])) as $c) {
            $counts[$c['line_id']][$c['direction']] = $c;
        }
        foreach ($rows as &$r) {
            $r['units'] = $assigned[$r['id']] ?? [];
            $r['out'] = $counts[$r['id']]['out'] ?? null;
            $r['in'] = $counts[$r['id']]['in'] ?? null;
        }
        return $rows;
    }

    /**
     * Count equipment out of the yard.
     *
     * @param array<string,mixed> $in qty[lineId], receiver_name, signature (customer pickup), note
     */
    public static function load(Actor $actor, string $orderId, array $in, array $files): void
    {
        $actor->require('cf_count_stock');
        $o = Orders::row($orderId);
        if ($o['kind'] !== 'equipment') {
            throw new DomainError('invalid_input', 'Trucks are released, not loaded.');
        }
        if ($o['status'] !== 'confirmed' || !empty($o['loaded_at'])) {
            throw new DomainError('wrong_status', !empty($o['loaded_at']) ? 'This order is already loaded.' : 'Only confirmed orders can be loaded.');
        }
        $gaps = Dispatch::gaps($o);
        if (in_array('units', $gaps, true)) {
            throw new DomainError('not_assigned', 'The dispatcher has not assigned all heavy units yet.');
        }
        if ($o['fulfilment'] === 'delivery' && in_array('delivery run', $gaps, true)) {
            throw new DomainError('not_assigned', 'The dispatcher has not assigned the delivery truck and driver yet.');
        }
        $lines = self::lines($o);
        $qty = (array) ($in['qty'] ?? []);
        foreach ($lines as $l) {
            if ($l['tracking'] === 'serial') {
                continue;
            }
            $n = (string) ($qty[$l['id']] ?? '');
            if ($n === '' || !ctype_digit($n) || (int) $n !== (int) $l['qty']) {
                throw new DomainError('count_mismatch', 'Load exactly ' . number_format((int) $l['qty']) . ' for ' . $l['description'] . '. If the yard is short, tell the dispatcher before loading.', ['field' => 'qty[' . $l['id'] . ']']);
            }
        }
        $pickup = $o['fulfilment'] === 'pickup';
        $receiver = '';
        if ($pickup) {
            $receiver = sanitize_text_field(wp_unslash((string) ($in['receiver_name'] ?? '')));
            if (mb_strlen($receiver) < 3) {
                throw new DomainError('invalid_input', 'Enter the name of the person collecting.', ['field' => 'receiver_name']);
            }
            Signature::image((string) ($in['signature'] ?? ''));
        }
        $note = mb_substr(sanitize_text_field(wp_unslash((string) ($in['note'] ?? ''))), 0, 500);
        $photos = Files::storeMany($files['photos'] ?? null, 'yard/' . $o['company_id']);
        $sig = $pickup ? Signature::store((string) $in['signature'], 'yard/' . $o['company_id']) : null;
        try {
            Db::transaction(static function () use ($actor, $o, $lines, $pickup, $receiver, $sig, $note, $photos): void {
                $now = Db::row(Db::prepare('SELECT status, loaded_at FROM ' . Db::t('orders') . ' WHERE id = UNHEX(%s) FOR UPDATE', $o['id']));
                if ($now['status'] !== 'confirmed' || $now['loaded_at'] !== null) {
                    throw new DomainError('stale', 'This order was just updated. Reload the page.');
                }
                foreach ($lines as $l) {
                    Db::insert('order_counts', [
                        'id' => Uuid::v7(), 'order_id' => $o['id'], 'line_id' => $l['id'], 'direction' => 'out',
                        'qty_good' => (int) $l['qty'], 'counted_by' => $actor->userId, 'created_at' => Db::now(),
                    ], ['id', 'order_id', 'line_id']);
                }
                $set = ['loaded_at' => Db::now(), 'updated_at' => Db::now()];
                if ($pickup) {
                    $set += ['status' => 'on_site', 'released_at' => Db::now(), 'handover_name' => $receiver, 'handover_signature' => $sig, 'status_changed_by' => $actor->userId, 'status_changed_at' => Db::now()];
                }
                Db::update('orders', $set, ['id' => $o['id']], ['id']);
                Trips::event($actor, $o['id'], null, $pickup ? 'released' : 'loaded', null, $note !== '' ? $note : ($pickup ? 'Collected by ' . $receiver : ''), $photos);
                Audit::log($o['company_id'], $actor->userId, 'order.load', 'order', $o['id'], ['pickup' => $pickup]);
            });
        } catch (\Throwable $e) {
            foreach ($photos as $p) {
                Files::delete($p);
            }
            Files::delete($sig);
            throw $e;
        }
        if ($pickup) {
            Trips::mailCustomer($o, "Order {$o['ref']} was collected", ["{$receiver} collected order {$o['ref']} at the Canpas yard.", 'Please return everything by ' . $o['end_date'] . '.']);
        }
    }

    /**
     * Hand a self-drive truck to the customer's driver.
     *
     * @param array<string,mixed> $in odometer, receiver_name, signature, note
     */
    public static function releaseTruck(Actor $actor, string $orderId, array $in, array $files): void
    {
        $actor->require('cf_count_stock');
        $o = Orders::row($orderId);
        if ($o['kind'] !== 'truck' || (int) $o['needs_driver'] === 1) {
            throw new DomainError('invalid_input', 'Only self-drive trucks are released at the yard; the Canpas driver records the trip.');
        }
        if ($o['status'] !== 'confirmed') {
            throw new DomainError('wrong_status', 'Only confirmed orders can be released.');
        }
        $truck = Dispatch::assignments((string) $o['id'])['trucks'][0] ?? null;
        if ($truck === null) {
            throw new DomainError('not_assigned', 'The dispatcher has not assigned a truck yet.');
        }
        $odo = Trips::odometer($in, (int) $truck['odometer_km']);
        $receiver = sanitize_text_field(wp_unslash((string) ($in['receiver_name'] ?? '')));
        if (mb_strlen($receiver) < 3) {
            throw new DomainError('invalid_input', 'Enter the name of the customer\'s driver.', ['field' => 'receiver_name']);
        }
        Signature::image((string) ($in['signature'] ?? ''));
        $note = mb_substr(sanitize_text_field(wp_unslash((string) ($in['note'] ?? ''))), 0, 500);
        $photos = Files::storeMany($files['photos'] ?? null, 'yard/' . $o['company_id']);
        if ($photos === []) {
            throw new DomainError('photos_required', 'Take at least one photo of the truck.', ['field' => 'photos']);
        }
        $sig = Signature::store((string) $in['signature'], 'yard/' . $o['company_id']);
        try {
            Db::transaction(static function () use ($actor, $o, $truck, $odo, $receiver, $sig, $note, $photos): void {
                $now = Db::value(Db::prepare('SELECT status FROM ' . Db::t('orders') . ' WHERE id = UNHEX(%s) FOR UPDATE', $o['id']));
                if ($now !== 'confirmed') {
                    throw new DomainError('stale', 'This order was just updated. Reload the page.');
                }
                Db::update('orders', [
                    'status' => 'on_site', 'released_at' => Db::now(), 'odo_out' => $odo, 'handover_name' => $receiver, 'handover_signature' => $sig,
                    'status_changed_by' => $actor->userId, 'status_changed_at' => Db::now(), 'updated_at' => Db::now(),
                ], ['id' => $o['id']], ['id']);
                Db::exec(Db::prepare('UPDATE ' . Db::t('trucks') . ' SET odometer_km = GREATEST(odometer_km, %d) WHERE id = UNHEX(%s)', $odo, $truck['id']));
                Trips::event($actor, $o['id'], null, 'released', $odo, $note !== '' ? $note : 'Released to ' . $receiver, $photos);
                Audit::log($o['company_id'], $actor->userId, 'order.release', 'order', $o['id'], ['truck' => $truck['plate'], 'odometer' => $odo]);
            });
        } catch (\Throwable $e) {
            foreach ($photos as $p) {
                Files::delete($p);
            }
            Files::delete($sig);
            throw $e;
        }
        Trips::mailCustomer($o, "Truck {$truck['plate']} released for order {$o['ref']}", ["{$receiver} received truck {$truck['plate']} at the Canpas yard.", 'Please return it by ' . $o['end_date'] . '.']);
    }

    /**
     * Return inspection of a truck.
     *
     * @param array<string,mixed> $in odometer, fuel_short_liters, damage_note, to_repair
     */
    public static function returnTruck(Actor $actor, string $orderId, array $in, array $files): void
    {
        $actor->require('cf_count_stock');
        $o = Orders::row($orderId);
        if ($o['kind'] !== 'truck') {
            throw new DomainError('invalid_input', 'This is not a truck order.');
        }
        $a = Dispatch::assignments((string) $o['id']);
        $truck = $a['trucks'][0] ?? null;
        if ((int) $o['needs_driver'] === 1) {
            $trip = $a['trips']['rental'] ?? null;
            if ($trip === null || $trip['status'] !== 'back') {
                throw new DomainError('trip_not_back', 'The driver has not recorded "Back at yard" yet.');
            }
        } elseif (!in_array($o['status'], ['on_site', 'returning'], true)) {
            throw new DomainError('wrong_status', 'This truck is not out.');
        }
        if ($truck === null || $o['odo_out'] === null) {
            throw new DomainError('wrong_status', 'This truck was never released.');
        }
        $odo = Trips::odometer($in, (int) $o['odo_out']);
        $fuel = self::decimal($in['fuel_short_liters'] ?? '', 'fuel_short_liters', 1000);
        $damage = mb_substr(sanitize_textarea_field(wp_unslash((string) ($in['damage_note'] ?? ''))), 0, 500);
        $repair = !empty($in['to_repair']) && $in['to_repair'] !== '0';
        $photos = Files::storeMany($files['photos'] ?? null, 'yard/' . $o['company_id']);
        if ($photos === []) {
            throw new DomainError('photos_required', 'Take at least one photo of the truck.', ['field' => 'photos']);
        }
        try {
            Db::transaction(static function () use ($actor, $o, $truck, $odo, $fuel, $damage, $repair, $photos): void {
                $now = Db::value(Db::prepare('SELECT status FROM ' . Db::t('orders') . ' WHERE id = UNHEX(%s) FOR UPDATE', $o['id']));
                if (!in_array($now, ['on_site', 'returning'], true)) {
                    throw new DomainError('stale', 'This order was just updated. Reload the page.');
                }
                Db::update('orders', [
                    'status' => 'returned', 'odo_in' => $odo, 'actual_km' => $odo - (int) $o['odo_out'], 'fuel_short_liters' => $fuel,
                    'damage_note' => $damage !== '' ? $damage : null, 'returned_at' => Db::now(), 'returned_by' => $actor->userId,
                    'status_changed_by' => $actor->userId, 'status_changed_at' => Db::now(), 'updated_at' => Db::now(),
                ], ['id' => $o['id']], ['id']);
                Db::exec(Db::prepare('UPDATE ' . Db::t('trucks') . ' SET odometer_km = GREATEST(odometer_km, %d)' . ($repair ? ", status = 'repair'" : '') . ' WHERE id = UNHEX(%s)', $odo, $truck['id']));
                $note = trim(($fuel ? 'Fuel short ' . $fuel . ' L. ' : '') . $damage);
                Trips::event($actor, $o['id'], null, 'returned', $odo, $note, $photos);
                Audit::log($o['company_id'], $actor->userId, 'order.return', 'order', $o['id'], ['odometer' => $odo, 'km' => $odo - (int) $o['odo_out'], 'fuel_short' => $fuel, 'repair' => $repair]);
            });
        } catch (\Throwable $e) {
            foreach ($photos as $p) {
                Files::delete($p);
            }
            throw $e;
        }
        Trips::mailCustomer($o, "Order {$o['ref']} returned", [
            "Canpas has checked in truck {$truck['plate']} for order {$o['ref']}: " . number_format($odo - (int) $o['odo_out']) . ' km driven.',
            'Your final bill follows once Canpas has closed the order.',
        ]);
    }

    /**
     * Count equipment back in.
     *
     * @param array<string,mixed> $in good[lineId], damaged[lineId], missing[lineId], unit[unitId]=ok|repair|missing, note
     */
    public static function returnEquipment(Actor $actor, string $orderId, array $in, array $files): void
    {
        $actor->require('cf_count_stock');
        $o = Orders::row($orderId);
        if ($o['kind'] !== 'equipment' || empty($o['loaded_at'])) {
            throw new DomainError('wrong_status', 'This order was never loaded.');
        }
        if ($o['fulfilment'] === 'delivery') {
            $trip = Dispatch::assignments((string) $o['id'])['trips']['pickup'] ?? null;
            if ($trip === null || $trip['status'] !== 'back') {
                throw new DomainError('trip_not_back', 'The pickup driver has not recorded "Back at yard" yet.');
            }
        } elseif (!in_array($o['status'], ['on_site', 'returning'], true)) {
            throw new DomainError('wrong_status', 'This order is not out.');
        }
        $lines = self::lines($o);
        $counts = [];
        $unitState = [];
        $int = static function ($v, string $field): int {
            $v = trim((string) $v);
            if ($v === '') {
                return 0;
            }
            if (!ctype_digit($v)) {
                throw new DomainError('invalid_input', 'Enter whole numbers.', ['field' => $field]);
            }
            return (int) $v;
        };
        foreach ($lines as $l) {
            $out = (int) ($l['out']['qty_good'] ?? $l['qty']);
            if ($l['tracking'] === 'serial') {
                $c = ['good' => 0, 'damaged' => 0, 'missing' => 0];
                foreach ($l['units'] as $u) {
                    $s = (string) ($in['unit'][$u['id']] ?? '');
                    if (!in_array($s, ['ok', 'repair', 'missing'], true)) {
                        throw new DomainError('invalid_input', 'Choose the condition of unit ' . $u['serial'] . '.', ['field' => 'unit[' . $u['id'] . ']']);
                    }
                    $unitState[$u['id']] = $s;
                    $c[$s === 'ok' ? 'good' : ($s === 'repair' ? 'damaged' : 'missing')]++;
                }
            } else {
                $c = [
                    'good' => $int($in['good'][$l['id']] ?? '', 'good[' . $l['id'] . ']'),
                    'damaged' => $int($in['damaged'][$l['id']] ?? '', 'damaged[' . $l['id'] . ']'),
                    'missing' => $int($in['missing'][$l['id']] ?? '', 'missing[' . $l['id'] . ']'),
                ];
                if ($c['good'] + $c['damaged'] + $c['missing'] !== $out) {
                    throw new DomainError('count_mismatch', $l['description'] . ': good + damaged + missing must add up to the ' . number_format($out) . ' that went out.', ['field' => 'good[' . $l['id'] . ']']);
                }
            }
            $counts[$l['id']] = $c + ['line' => $l];
        }
        $note = mb_substr(sanitize_textarea_field(wp_unslash((string) ($in['note'] ?? ''))), 0, 500);
        $photos = Files::storeMany($files['photos'] ?? null, 'yard/' . $o['company_id']);
        try {
            Db::transaction(static function () use ($actor, $o, $counts, $unitState, $note, $photos): void {
                $now = Db::value(Db::prepare('SELECT status FROM ' . Db::t('orders') . ' WHERE id = UNHEX(%s) FOR UPDATE', $o['id']));
                if (!in_array($now, ['on_site', 'returning'], true)) {
                    throw new DomainError('stale', 'This order was just updated. Reload the page.');
                }
                $summary = [];
                foreach ($counts as $lineId => $c) {
                    Db::insert('order_counts', [
                        'id' => Uuid::v7(), 'order_id' => $o['id'], 'line_id' => $lineId, 'direction' => 'in',
                        'qty_good' => $c['good'], 'qty_damaged' => $c['damaged'], 'qty_missing' => $c['missing'], 'counted_by' => $actor->userId, 'created_at' => Db::now(),
                    ], ['id', 'order_id', 'line_id']);
                    if ($c['line']['tracking'] === 'qty') {
                        foreach (['damaged' => 'damaged', 'missing' => 'write_off'] as $k => $reason) {
                            if ($c[$k] > 0) {
                                Stock::record($actor, $c['line']['item_id'], -$c[$k], $reason, 'Return of ' . $o['ref']);
                            }
                        }
                    }
                    if ($c['damaged'] || $c['missing']) {
                        $summary[] = $c['line']['description'] . ': ' . ($c['damaged'] ? $c['damaged'] . ' damaged' : '') . ($c['damaged'] && $c['missing'] ? ', ' : '') . ($c['missing'] ? $c['missing'] . ' missing' : '');
                    }
                }
                foreach ($unitState as $unitId => $s) {
                    if ($s !== 'ok') {
                        Db::exec(Db::prepare('UPDATE ' . Db::t('equipment_units') . ' SET status = %s, notes = %s, updated_at = %s WHERE id = UNHEX(%s)',
                            $s === 'repair' ? 'repair' : 'retired', ($s === 'repair' ? 'Damaged on ' : 'Missing after ') . $o['ref'], Db::now(), $unitId));
                    }
                }
                $damage = trim(implode('; ', $summary) . ($note !== '' ? ($summary ? '. ' : '') . $note : ''));
                Db::update('orders', [
                    'status' => 'returned', 'damage_note' => $damage !== '' ? mb_substr($damage, 0, 500) : null, 'returned_at' => Db::now(), 'returned_by' => $actor->userId,
                    'status_changed_by' => $actor->userId, 'status_changed_at' => Db::now(), 'updated_at' => Db::now(),
                ], ['id' => $o['id']], ['id']);
                Trips::event($actor, $o['id'], null, 'returned', null, $damage, $photos);
                Audit::log($o['company_id'], $actor->userId, 'order.return', 'order', $o['id'], ['issues' => $summary]);
            });
        } catch (\Throwable $e) {
            foreach ($photos as $p) {
                Files::delete($p);
            }
            throw $e;
        }
        Trips::mailCustomer($o, "Order {$o['ref']} returned", [
            "Canpas has counted back the equipment for order {$o['ref']}.",
            'Your final bill follows once Canpas has closed the order.',
        ]);
    }

    /** Orders the yard should load or release soon. @return list<array<string,mixed>> */
    public static function toLoad(Actor $actor): array
    {
        $actor->require('cf_count_stock');
        $until = Db::todayManila()->modify('+7 days')->format('Y-m-d');
        return array_values(array_filter(self::open('confirmed', $until, 'start_date'), static fn(array $o): bool =>
            ($o['kind'] === 'equipment' && $o['loaded_at'] === null) || ($o['kind'] === 'truck' && (int) $o['needs_driver'] === 0)));
    }

    /** Orders that are out, by return date (overdue first). @return list<array<string,mixed>> */
    public static function toReturn(Actor $actor): array
    {
        $actor->require('cf_count_stock');
        return self::open(['dispatched', 'on_site', 'returning'], null, 'end_date');
    }

    // ----- internals ---------------------------------------------------------------------------

    /** @param string|list<string> $status @return list<array<string,mixed>> */
    private static function open(string|array $status, ?string $until, string $order): array
    {
        $status = (array) $status;
        $where = 'o.status IN (' . implode(',', array_fill(0, count($status), '%s')) . ')' . ($until ? ' AND o.start_date <= %s' : '');
        return Db::rows(Db::prepare(
            'SELECT o.*, ' . Db::hex('o.id', 'id') . ', ' . Db::hex('o.company_id', 'company_id') . ', c.name AS company_name,
                (SELECT GROUP_CONCAT(CONCAT(l.qty, \' × \', l.description) ORDER BY l.sort SEPARATOR \', \') FROM ' . Db::t('order_lines') . ' l WHERE l.order_id = o.id AND l.line_type <> \'delivery\') AS summary,
                (SELECT tr.status FROM ' . Db::t('trips') . ' tr WHERE tr.order_id = o.id AND tr.kind IN (\'rental\', \'pickup\') LIMIT 1) AS trip_status
             FROM ' . Db::t('orders') . ' o JOIN ' . Db::t('companies') . ' c ON c.id = o.company_id WHERE ' . $where . ' ORDER BY o.' . $order . ', o.ref',
            ...array_merge($status, $until ? [$until] : [])
        ));
    }

    private static function decimal(mixed $raw, string $field, float $max): ?float
    {
        $raw = trim(str_replace(',', '', (string) $raw));
        if ($raw === '') {
            return null;
        }
        if (!is_numeric($raw) || (float) $raw < 0 || (float) $raw > $max) {
            throw new DomainError('invalid_input', 'Enter a number.', ['field' => $field]);
        }
        return round((float) $raw, 2);
    }
}
