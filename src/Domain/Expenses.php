<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Files;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Fuel and trip costs logged by drivers with a receipt photo, per truck and optionally per order.
 * Drivers see their own; the office sees all. They feed the fuel and profit reports in milestone 5.
 */
final class Expenses
{
    public const KINDS = ['fuel' => 'Fuel', 'toll' => 'Toll', 'parking' => 'Parking', 'repair' => 'Repair on the road', 'other' => 'Other'];

    /**
     * @param array<string,mixed> $in kind, amount, liters, odometer, truck_id, order_id, spent_on, note
     */
    public static function add(Actor $actor, array $in, ?array $receipt): string
    {
        if (!$actor->can('cf_drive') && !$actor->can('cf_dispatch')) {
            throw new DomainError('forbidden', 'You do not have permission to do this.');
        }
        $kind = (string) ($in['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) {
            throw new DomainError('invalid_input', 'Choose what the expense is for.', ['field' => 'kind']);
        }
        $amount = round((float) str_replace([',', '₱', ' '], '', (string) ($in['amount'] ?? '')), 2);
        if ($amount <= 0 || $amount > 1000000) {
            throw new DomainError('invalid_input', 'Enter the amount paid.', ['field' => 'amount']);
        }
        $liters = trim((string) ($in['liters'] ?? ''));
        if ($liters !== '' && (!is_numeric($liters) || (float) $liters <= 0 || (float) $liters > 2000)) {
            throw new DomainError('invalid_input', 'Enter the litres as a number.', ['field' => 'liters']);
        }
        $odo = trim(str_replace(',', '', (string) ($in['odometer'] ?? '')));
        if ($odo !== '' && (!ctype_digit($odo) || (int) $odo > 9999999)) {
            throw new DomainError('invalid_input', 'Enter the odometer in km.', ['field' => 'odometer']);
        }
        $day = (string) ($in['spent_on'] ?? '');
        $today = Db::todayManila()->format('Y-m-d');
        if ($day === '') {
            $day = $today;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $day);
        if (!$d || $d->format('Y-m-d') !== $day || $day > $today || $day < Db::todayManila()->modify('-60 days')->format('Y-m-d')) {
            throw new DomainError('invalid_input', 'Enter the date of the expense (within the last 60 days).', ['field' => 'spent_on']);
        }
        $truckId = strtolower((string) ($in['truck_id'] ?? ''));
        if ($truckId !== '') {
            $truckId = Uuid::assertHex($truckId, 'truck');
            if (Db::value(Db::prepare('SELECT plate FROM ' . Db::t('trucks') . ' WHERE id = UNHEX(%s)', $truckId)) === null) {
                throw new DomainError('not_found', 'Truck not found.', ['field' => 'truck_id']);
            }
        } elseif ($kind === 'fuel') {
            throw new DomainError('invalid_input', 'Choose the truck that was fuelled.', ['field' => 'truck_id']);
        }
        $orderId = strtolower((string) ($in['order_id'] ?? ''));
        if ($orderId !== '') {
            $orderId = Uuid::assertHex($orderId, 'order');
            if (!$actor->can('cf_dispatch')) {
                $mine = (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('trips') . ' WHERE order_id = UNHEX(%s) AND driver_user_id = %d', $orderId, $actor->userId));
                if ($mine === 0) {
                    throw new DomainError('not_found', 'Order not found.', ['field' => 'order_id']);
                }
            }
        }
        if ($receipt === null || empty($receipt['tmp_name'])) {
            throw new DomainError('file_missing', 'Take a photo of the receipt.', ['field' => 'receipt']);
        }
        $file = Files::storeUpload($receipt, 'expenses/' . gmdate('Y-m'));
        $id = Uuid::v7();
        try {
            Db::transaction(static function () use ($id, $actor, $kind, $amount, $liters, $odo, $truckId, $orderId, $file, $day, $in): void {
                Db::insert('expenses', [
                    'id' => $id, 'user_id' => $actor->userId, 'truck_id' => $truckId !== '' ? $truckId : null, 'order_id' => $orderId !== '' ? $orderId : null,
                    'kind' => $kind, 'amount' => number_format($amount, 2, '.', ''), 'liters' => $liters !== '' ? number_format((float) $liters, 2, '.', '') : null,
                    'odometer' => $odo !== '' ? (int) $odo : null, 'receipt_file' => $file['path'], 'receipt_name' => $file['name'],
                    'note' => mb_substr(sanitize_text_field(wp_unslash((string) ($in['note'] ?? ''))), 0, 255) ?: null,
                    'spent_on' => $day, 'created_at' => Db::now(),
                ], ['id', 'truck_id', 'order_id']);
                Audit::log(null, $actor->userId, 'expense.add', 'expense', $id, ['kind' => $kind, 'amount' => $amount]);
            });
        } catch (\Throwable $e) {
            Files::delete($file['path']);
            throw $e;
        }
        return $id;
    }

    /** Drivers: their own. Office: all, or for one order. @return list<array<string,mixed>> */
    public static function list(Actor $actor, ?string $orderId = null): array
    {
        $where = [];
        if ($actor->can('cf_dispatch')) {
            // all
        } elseif ($actor->can('cf_drive')) {
            $where[] = Db::prepare('e.user_id = %d', $actor->userId);
        } else {
            throw new DomainError('forbidden', 'You do not have permission to do this.');
        }
        if ($orderId !== null) {
            $where[] = Db::prepare('e.order_id = UNHEX(%s)', $orderId);
        }
        $rows = Db::rows('SELECT e.*, ' . Db::hex('e.id', 'id') . ', t.plate, o.ref FROM ' . Db::t('expenses') . ' e
            LEFT JOIN ' . Db::t('trucks') . ' t ON t.id = e.truck_id LEFT JOIN ' . Db::t('orders') . ' o ON o.id = e.order_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY e.spent_on DESC, e.created_at DESC LIMIT 200');
        foreach ($rows as &$r) {
            $u = get_userdata((int) $r['user_id']);
            $r['user_name'] = $u ? $u->display_name : '';
        }
        return $rows;
    }

    /** @return array{path:string,name:string} */
    public static function receipt(Actor $actor, string $id): array
    {
        $id = Uuid::assertHex($id, 'expense');
        $e = Db::row(Db::prepare('SELECT user_id, receipt_file, receipt_name FROM ' . Db::t('expenses') . ' WHERE id = UNHEX(%s)', $id));
        if ($e === null || (!$actor->can('cf_dispatch') && (int) $e['user_id'] !== $actor->userId) || empty($e['receipt_file'])) {
            throw new DomainError('not_found', 'Receipt not found.');
        }
        Documents::logView($actor, 'expense', $id);
        return ['path' => (string) $e['receipt_file'], 'name' => (string) ($e['receipt_name'] ?: 'receipt')];
    }
}
