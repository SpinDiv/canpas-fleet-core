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
 * Service, repairs and renewals for trucks and heavy units.
 *
 * Logging a service sets the next service at the odometer + the interval in Settings (or a km the
 * user gives). Registration, insurance and inspection renewals move the expiry dates on the
 * truck or unit. Alerts list what is due, expired or in repair; costs feed the profit report.
 */
final class Maintenance
{
    public const KINDS = [
        'service' => 'Scheduled service',
        'repair' => 'Repair',
        'tyres' => 'Tyres',
        'registration' => 'LTO registration renewal',
        'insurance' => 'Insurance renewal',
        'inspection' => 'Inspection / certification',
        'other' => 'Other',
    ];

    /**
     * @param array<string,mixed> $in truck_id or unit_id, kind, done_on, odometer, cost, vendor, note, next_due_km, next_due_date, back_in_service
     */
    public static function log(Actor $actor, array $in, ?array $receipt): string
    {
        $actor->require('cf_manage_fleet');
        $kind = (string) ($in['kind'] ?? '');
        if (!isset(self::KINDS[$kind])) {
            throw new DomainError('invalid_input', 'Choose what was done.', ['field' => 'kind']);
        }
        // The form sends one picker value: "truck:<id>" or "unit:<id>".
        if (preg_match('/^(truck|unit):([0-9a-f]{32})$/', strtolower((string) ($in['asset'] ?? '')), $m)) {
            $in[$m[1] . '_id'] = $m[2];
        }
        $truckId = strtolower((string) ($in['truck_id'] ?? ''));
        $unitId = strtolower((string) ($in['unit_id'] ?? ''));
        if (($truckId === '') === ($unitId === '')) {
            throw new DomainError('invalid_input', 'Choose one truck or one heavy unit.', ['field' => 'truck_id']);
        }
        $asset = $truckId !== ''
            ? Db::row(Db::prepare('SELECT plate AS name, odometer_km, status FROM ' . Db::t('trucks') . ' WHERE id = UNHEX(%s)', Uuid::assertHex($truckId, 'truck')))
            : Db::row(Db::prepare('SELECT serial AS name, 0 AS odometer_km, status FROM ' . Db::t('equipment_units') . ' WHERE id = UNHEX(%s)', Uuid::assertHex($unitId, 'unit')));
        if ($asset === null) {
            throw new DomainError('not_found', 'Truck or unit not found.');
        }
        if ($unitId !== '' && in_array($kind, ['registration', 'insurance'], true)) {
            throw new DomainError('invalid_input', 'Registration and insurance are for trucks.', ['field' => 'kind']);
        }
        $done = self::date((string) ($in['done_on'] ?? ''), 'done_on', true);
        if ($done > Db::todayManila()->format('Y-m-d')) {
            throw new DomainError('invalid_input', 'The date cannot be in the future.', ['field' => 'done_on']);
        }
        $odo = trim(str_replace(',', '', (string) ($in['odometer'] ?? '')));
        if ($odo !== '' && (!ctype_digit($odo) || (int) $odo > 9999999)) {
            throw new DomainError('invalid_input', 'Enter the odometer in km.', ['field' => 'odometer']);
        }
        if ($kind === 'service' && $truckId !== '' && $odo === '') {
            throw new DomainError('invalid_input', 'Enter the odometer at the service.', ['field' => 'odometer']);
        }
        $cost = round((float) str_replace([',', '₱', ' '], '', (string) ($in['cost'] ?? '0')), 2);
        if ($cost < 0 || $cost > 100000000) {
            throw new DomainError('invalid_input', 'Enter the cost.', ['field' => 'cost']);
        }
        $nextKm = trim(str_replace(',', '', (string) ($in['next_due_km'] ?? '')));
        if ($nextKm !== '' && !ctype_digit($nextKm)) {
            throw new DomainError('invalid_input', 'Enter the next service km.', ['field' => 'next_due_km']);
        }
        if ($kind === 'service' && $truckId !== '' && $nextKm === '') {
            $nextKm = (string) ((int) $odo + (int) Settings::get('service_interval_km'));
        }
        $nextDate = self::date((string) ($in['next_due_date'] ?? ''), 'next_due_date', in_array($kind, ['registration', 'insurance', 'inspection'], true));
        $back = !empty($in['back_in_service']) && $in['back_in_service'] !== '0';
        $file = $receipt !== null && !empty($receipt['tmp_name']) ? Files::storeUpload($receipt, 'maintenance/' . gmdate('Y')) : null;
        $id = Uuid::v7();
        try {
            Db::transaction(static function () use ($id, $actor, $truckId, $unitId, $kind, $done, $odo, $cost, $nextKm, $nextDate, $back, $file, $in, $asset): void {
                Db::insert('maintenance', [
                    'id' => $id, 'truck_id' => $truckId ?: null, 'unit_id' => $unitId ?: null, 'kind' => $kind, 'done_on' => $done,
                    'odometer' => $odo !== '' ? (int) $odo : null, 'cost' => number_format($cost, 2, '.', ''),
                    'vendor' => mb_substr(sanitize_text_field(wp_unslash((string) ($in['vendor'] ?? ''))), 0, 120) ?: null,
                    'note' => mb_substr(sanitize_textarea_field(wp_unslash((string) ($in['note'] ?? ''))), 0, 500) ?: null,
                    'receipt_file' => $file['path'] ?? null, 'receipt_name' => $file['name'] ?? null,
                    'next_due_km' => $nextKm !== '' ? (int) $nextKm : null, 'next_due_date' => $nextDate,
                    'created_by' => $actor->userId, 'created_at' => Db::now(),
                ], ['id', 'truck_id', 'unit_id']);
                if ($truckId !== '') {
                    $set = [];
                    if ($odo !== '') {
                        Db::exec(Db::prepare('UPDATE ' . Db::t('trucks') . ' SET odometer_km = GREATEST(odometer_km, %d) WHERE id = UNHEX(%s)', (int) $odo, $truckId));
                    }
                    if ($kind === 'service') {
                        $set['next_service_km'] = $nextKm !== '' ? (int) $nextKm : null;
                        $set['next_service_date'] = $nextDate;
                    }
                    if ($kind === 'registration') {
                        $set['registration_expiry'] = $nextDate;
                    }
                    if ($kind === 'insurance') {
                        $set['insurance_expiry'] = $nextDate;
                    }
                    if ($back && $asset['status'] === 'repair') {
                        $set['status'] = 'available';
                    }
                    if ($set !== []) {
                        Db::update('trucks', $set + ['updated_at' => Db::now()], ['id' => $truckId], ['id']);
                    }
                } else {
                    $set = [];
                    if ($kind === 'inspection') {
                        $set['inspection_expiry'] = $nextDate;
                    }
                    if ($back && $asset['status'] === 'repair') {
                        $set['status'] = 'available';
                    }
                    if ($set !== []) {
                        Db::update('equipment_units', $set + ['updated_at' => Db::now()], ['id' => $unitId], ['id']);
                    }
                }
                Audit::log(null, $actor->userId, 'maintenance.log', 'maintenance', $id, ['asset' => $asset['name'], 'kind' => $kind, 'cost' => $cost, 'back_in_service' => $back]);
            });
        } catch (\Throwable $e) {
            Files::delete($file['path'] ?? null);
            throw $e;
        }
        return $id;
    }

    /** @return list<array<string,mixed>> recent records */
    public static function records(Actor $actor, int $limit = 100): array
    {
        $actor->require('cf_assign_fleet');
        return Db::rows('SELECT m.*, ' . Db::hex('m.id', 'id') . ', COALESCE(t.plate, u.serial) AS asset FROM ' . Db::t('maintenance') . ' m
            LEFT JOIN ' . Db::t('trucks') . ' t ON t.id = m.truck_id LEFT JOIN ' . Db::t('equipment_units') . ' u ON u.id = m.unit_id
            ORDER BY m.done_on DESC, m.created_at DESC LIMIT ' . max(1, min(500, $limit)));
    }

    /**
     * What needs attention: expired or soon-expiring registration, insurance and inspection,
     * services due by km or date, and anything in repair.
     *
     * @return list<array{tone:string,asset:string,what:string,detail:string}>
     */
    public static function alerts(): array
    {
        $today = Db::todayManila()->format('Y-m-d');
        $soon = Db::todayManila()->modify('+30 days')->format('Y-m-d');
        $out = [];
        $date = static fn(?string $d, string $label, string $asset) => $d === null || $d > $soon ? null
            : ['tone' => $d < $today ? 'bad' : 'warn', 'asset' => $asset, 'what' => $label . ($d < $today ? ' expired' : ' expires soon'), 'detail' => (new \DateTimeImmutable($d))->format('j M Y')];
        foreach (Db::rows("SELECT plate, odometer_km, next_service_km, next_service_date, registration_expiry, insurance_expiry, status FROM " . Db::t('trucks') . " WHERE status <> 'retired' ORDER BY plate") as $t) {
            foreach ([$date($t['registration_expiry'], 'Registration', $t['plate']), $date($t['insurance_expiry'], 'Insurance', $t['plate'])] as $a) {
                if ($a) {
                    $out[] = $a;
                }
            }
            if ($t['registration_expiry'] === null || $t['insurance_expiry'] === null) {
                $out[] = ['tone' => 'warn', 'asset' => $t['plate'], 'what' => 'Registration or insurance date missing', 'detail' => 'Add it on the Trucks page'];
            }
            if ($t['next_service_km'] !== null && (int) $t['next_service_km'] - (int) $t['odometer_km'] <= 500) {
                $left = (int) $t['next_service_km'] - (int) $t['odometer_km'];
                $out[] = ['tone' => $left < 0 ? 'bad' : 'warn', 'asset' => $t['plate'], 'what' => $left < 0 ? 'Service overdue' : 'Service due soon',
                    'detail' => 'at ' . number_format((int) $t['next_service_km']) . ' km, now ' . number_format((int) $t['odometer_km']) . ' km'];
            } elseif ($t['next_service_date'] !== null && $t['next_service_date'] <= $soon) {
                $out[] = ['tone' => $t['next_service_date'] < $today ? 'bad' : 'warn', 'asset' => $t['plate'], 'what' => 'Service due', 'detail' => (new \DateTimeImmutable($t['next_service_date']))->format('j M Y')];
            }
            if ($t['status'] === 'repair') {
                $out[] = ['tone' => 'warn', 'asset' => $t['plate'], 'what' => 'In repair', 'detail' => 'Log the repair and mark it back in service'];
            }
        }
        foreach (Db::rows("SELECT u.serial, u.inspection_expiry, u.status, i.name FROM " . Db::t('equipment_units') . " u JOIN " . Db::t('equipment_items') . " i ON i.id = u.item_id WHERE u.status <> 'retired' ORDER BY u.serial") as $u) {
            $a = $date($u['inspection_expiry'], 'Inspection', $u['serial']);
            if ($a) {
                $out[] = $a;
            }
            if ($u['status'] === 'repair') {
                $out[] = ['tone' => 'warn', 'asset' => $u['serial'], 'what' => 'In repair', 'detail' => $u['name']];
            }
        }
        usort($out, static fn($a, $b) => [$a['tone'] === 'bad' ? 0 : 1, $a['asset']] <=> [$b['tone'] === 'bad' ? 0 : 1, $b['asset']]);
        return $out;
    }

    /** @return array{path:string,name:string} */
    public static function receipt(Actor $actor, string $id): array
    {
        $actor->require('cf_assign_fleet');
        $r = Db::row(Db::prepare('SELECT receipt_file, receipt_name FROM ' . Db::t('maintenance') . ' WHERE id = UNHEX(%s)', Uuid::assertHex($id, 'record')));
        if ($r === null || empty($r['receipt_file'])) {
            throw new DomainError('not_found', 'Receipt not found.');
        }
        Documents::logView($actor, 'maintenance', $id);
        return ['path' => (string) $r['receipt_file'], 'name' => (string) ($r['receipt_name'] ?: 'receipt')];
    }

    private static function date(string $raw, string $field, bool $required): ?string
    {
        if ($raw === '') {
            if ($required) {
                throw new DomainError('invalid_input', 'Enter the date.', ['field' => $field]);
            }
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if (!$d || $d->format('Y-m-d') !== $raw) {
            throw new DomainError('invalid_input', 'Enter a valid date.', ['field' => $field]);
        }
        return $raw;
    }
}
