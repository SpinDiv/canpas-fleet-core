<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DbError;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Actor;

/**
 * What Canpas rents out and at what rates: truck types and trucks, equipment items
 * (formwork, scaffolding, tools by quantity; heavy equipment by serial unit) and heavy units.
 *
 * Rates are edited here (cf_manage_rates); physical trucks and units here (cf_manage_fleet).
 * Every write is audited. Rates are copied onto orders at booking, so edits never change existing orders.
 */
final class Catalog
{
    public const CATEGORIES = ['formwork' => 'Formwork', 'scaffolding' => 'Scaffolding', 'tools' => 'Small tools & accessories', 'heavy' => 'Heavy equipment'];
    public const UNIT_STATUSES = ['available' => 'Available', 'repair' => 'In repair', 'retired' => 'Retired'];
    public const UNITS = ['pc' => 'piece', 'set' => 'set', 'm2' => 'm²', 'm' => 'metre', 'unit' => 'unit'];

    /** field => [label, type, required, min, max] */
    private const TRUCK_TYPE_FIELDS = [
        'name' => ['Name', 'text', true, 2, 100],
        'capacity' => ['Capacity', 'text', false, 0, 100],
        'starting_fee' => ['Starting fee', 'money', true, 0, 1000000],
        'rate_per_km' => ['Rate per km', 'money', true, 0, 10000],
        'driver_flat_fee' => ['Driver flat fee', 'money', true, 0, 100000],
        'driver_flat_km' => ['Km included in driver flat fee', 'int', true, 0, 500],
        'driver_rate_per_km' => ['Driver rate per km after that', 'money', true, 0, 10000],
        'daily_rate' => ['Daily rate', 'money', false, 0, 1000000],
        'free_km' => ['Free km included', 'int', false, 0, 500],
        'deposit_mode' => ['Deposit type', 'enum:percent|fixed', true, null, null],
        'deposit_value' => ['Deposit', 'money', true, 0, 1000000],
        'active' => ['Offered to customers', 'bool', false, null, null],
    ];

    private const TRUCK_FIELDS = [
        'type_id' => ['Truck type', 'id', true, null, null],
        'plate' => ['Plate number', 'plate', true, 3, 16],
        'label' => ['Description', 'text', false, 0, 100],
        'odometer_km' => ['Odometer (km)', 'int', true, 0, 9999999],
        'registration_expiry' => ['Registration expiry', 'date', false, null, null],
        'insurance_expiry' => ['Insurance expiry', 'date', false, null, null],
        'status' => ['Status', 'enum:available|repair|retired', true, null, null],
        'notes' => ['Notes', 'text', false, 0, 255],
    ];

    private const ITEM_FIELDS = [
        'category' => ['Category', 'enum:formwork|scaffolding|tools|heavy', true, null, null],
        'name' => ['Name', 'text', true, 2, 150],
        'unit' => ['Unit', 'enum:pc|set|m2|m|unit', true, null, null],
        'rate_day' => ['Rate per day', 'money', true, 0, 1000000],
        'rate_week' => ['Rate per week', 'money', true, 0, 1000000],
        'rate_month' => ['Rate per month', 'money', true, 0, 10000000],
        'min_days' => ['Minimum rental (days)', 'int', true, 1, 365],
        'deposit_mode' => ['Deposit type', 'enum:percent|fixed', true, null, null],
        'deposit_value' => ['Deposit', 'money', true, 0, 10000000],
        'replacement_cost' => ['Replacement cost per piece', 'money', true, 0, 100000000],
        'damage_fee' => ['Damage fee per piece', 'money', true, 0, 100000000],
        'weight_kg' => ['Weight per piece (kg)', 'decimal', false, 0, 100000],
        'active' => ['Offered to customers', 'bool', false, null, null],
    ];

    private const UNIT_FIELDS = [
        'item_id' => ['Equipment', 'id', true, null, null],
        'serial' => ['Serial / asset no.', 'plate', true, 2, 64],
        'hour_meter' => ['Hour meter', 'decimal', false, 0, 1000000],
        'inspection_expiry' => ['Inspection expiry', 'date', false, null, null],
        'status' => ['Status', 'enum:available|repair|retired', true, null, null],
        'notes' => ['Notes', 'text', false, 0, 255],
    ];

    // ----- Truck types -------------------------------------------------------------------------

    /** @param array<string, mixed> $in */
    public static function saveTruckType(Actor $actor, array $in, ?string $id = null): string
    {
        $actor->require('cf_manage_rates');
        $data = self::validate(self::TRUCK_TYPE_FIELDS, $in, $id !== null);
        if (($data['deposit_mode'] ?? null) === 'percent' && ($data['deposit_value'] ?? 0) > 100) {
            throw new DomainError('invalid_input', 'A percentage deposit cannot be more than 100.', ['field' => 'deposit_value']);
        }
        return self::upsert($actor, 'truck_types', 'truck_type', $data, $id, 'A truck type with this name already exists.');
    }

    /** @return list<array<string, mixed>> */
    public static function truckTypes(bool $activeOnly = false): array
    {
        return Db::rows('SELECT t.*, ' . Db::hex('t.id', 'id') . ', (SELECT COUNT(*) FROM ' . Db::t('trucks') . ' k WHERE k.type_id = t.id AND k.status <> \'retired\') AS trucks
            FROM ' . Db::t('truck_types') . ' t' . ($activeOnly ? ' WHERE t.active = 1' : '') . ' ORDER BY t.active DESC, t.name');
    }

    // ----- Trucks ------------------------------------------------------------------------------

    /** @param array<string, mixed> $in */
    public static function saveTruck(Actor $actor, array $in, ?string $id = null): string
    {
        $actor->require('cf_manage_fleet');
        $data = self::validate(self::TRUCK_FIELDS, $in, $id !== null);
        if (isset($data['type_id']) && !Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('truck_types') . ' WHERE id = UNHEX(%s)', $data['type_id']))) {
            throw new DomainError('invalid_input', 'Choose a truck type.', ['field' => 'type_id']);
        }
        return self::upsert($actor, 'trucks', 'truck', $data, $id, 'A truck with this plate number already exists.', ['type_id']);
    }

    /** @return list<array<string, mixed>> */
    public static function trucks(): array
    {
        return Db::rows('SELECT ' . Db::hex('k.id', 'id') . ', ' . Db::hex('k.type_id', 'type_id') . ', k.plate, k.label, k.odometer_km, k.registration_expiry, k.insurance_expiry, k.status, k.notes, t.name AS type_name
            FROM ' . Db::t('trucks') . ' k JOIN ' . Db::t('truck_types') . ' t ON t.id = k.type_id
            ORDER BY FIELD(k.status, \'available\', \'repair\', \'retired\'), k.plate');
    }

    // ----- Equipment items ---------------------------------------------------------------------

    /** @param array<string, mixed> $in */
    public static function saveItem(Actor $actor, array $in, ?string $id = null): string
    {
        $actor->require('cf_manage_rates');
        $data = self::validate(self::ITEM_FIELDS, $in, $id !== null);
        if (isset($data['category'])) {
            // Heavy equipment is tracked per serial unit; everything else by quantity.
            $data['tracking'] = $data['category'] === 'heavy' ? 'serial' : 'qty';
            if ($id !== null) {
                $old = Db::value(Db::prepare('SELECT category FROM ' . Db::t('equipment_items') . ' WHERE id = UNHEX(%s)', $id));
                $units = (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('equipment_units') . ' WHERE item_id = UNHEX(%s)', $id));
                if ($old === 'heavy' && $data['category'] !== 'heavy' && $units > 0) {
                    throw new DomainError('has_units', 'This item has serial units; it must stay heavy equipment.', ['field' => 'category']);
                }
            }
        }
        if (($data['deposit_mode'] ?? null) === 'percent' && ($data['deposit_value'] ?? 0) > 100) {
            throw new DomainError('invalid_input', 'A percentage deposit cannot be more than 100.', ['field' => 'deposit_value']);
        }
        foreach ([['rate_week', 'rate_day', 7, 'week'], ['rate_month', 'rate_week', 30 / 7, 'month']] as [$long, $short, $mult, $word]) {
            if (isset($data[$long], $data[$short]) && $data[$long] > 0 && $data[$short] > 0 && $data[$long] > $data[$short] * $mult) {
                throw new DomainError('invalid_input', "The {$word} rate is higher than paying the shorter rate for the whole {$word}.", ['field' => $long]);
            }
        }
        return self::upsert($actor, 'equipment_items', 'equipment_item', $data, $id, 'An item with this name already exists.');
    }

    /** @return list<array<string, mixed>> */
    public static function items(?string $category = null, bool $activeOnly = false): array
    {
        $where = [];
        if ($category === 'nonheavy') {
            $where[] = "i.category <> 'heavy'";
        } elseif ($category !== null) {
            if (!isset(self::CATEGORIES[$category])) {
                throw new DomainError('invalid_input', 'Unknown category.');
            }
            $where[] = Db::prepare('i.category = %s', $category);
        }
        if ($activeOnly) {
            $where[] = 'i.active = 1';
        }
        return Db::rows('SELECT ' . Db::hex('i.id', 'id') . ', i.category, i.name, i.unit, i.tracking, i.rate_day, i.rate_week, i.rate_month, i.min_days, i.deposit_mode, i.deposit_value, i.replacement_cost, i.damage_fee, i.weight_kg, i.active,
                (SELECT COUNT(*) FROM ' . Db::t('equipment_units') . ' u WHERE u.item_id = i.id AND u.status <> \'retired\') AS units
            FROM ' . Db::t('equipment_items') . ' i' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . '
            ORDER BY i.active DESC, FIELD(i.category, \'formwork\', \'scaffolding\', \'tools\', \'heavy\'), i.name');
    }

    // ----- Heavy units -------------------------------------------------------------------------

    /** @param array<string, mixed> $in */
    public static function saveUnit(Actor $actor, array $in, ?string $id = null): string
    {
        $actor->require('cf_manage_fleet');
        $data = self::validate(self::UNIT_FIELDS, $in, $id !== null);
        if (isset($data['item_id'])) {
            $tracking = Db::value(Db::prepare('SELECT tracking FROM ' . Db::t('equipment_items') . ' WHERE id = UNHEX(%s)', $data['item_id']));
            if ($tracking !== 'serial') {
                throw new DomainError('not_serial', 'Serial units can only be added to heavy equipment items.', ['field' => 'item_id']);
            }
        }
        return self::upsert($actor, 'equipment_units', 'equipment_unit', $data, $id, 'A unit with this serial already exists.', ['item_id']);
    }

    /** @return list<array<string, mixed>> */
    public static function units(): array
    {
        return Db::rows('SELECT ' . Db::hex('u.id', 'id') . ', ' . Db::hex('u.item_id', 'item_id') . ', u.serial, u.hour_meter, u.inspection_expiry, u.status, u.notes, i.name AS item_name
            FROM ' . Db::t('equipment_units') . ' u JOIN ' . Db::t('equipment_items') . ' i ON i.id = u.item_id
            ORDER BY FIELD(u.status, \'available\', \'repair\', \'retired\'), i.name, u.serial');
    }

    /** One row of any catalog table, for edit forms. */
    public static function find(string $table, string $id): ?array
    {
        if (!in_array($table, ['truck_types', 'trucks', 'equipment_items', 'equipment_units'], true)) {
            return null;
        }
        $bin = $table === 'trucks' ? ', ' . Db::hex('type_id') : ($table === 'equipment_units' ? ', ' . Db::hex('item_id') : '');
        $r = Db::row(Db::prepare('SELECT *, ' . Db::hex('id') . $bin . ' FROM ' . Db::t($table) . ' WHERE id = UNHEX(%s)', Uuid::assertHex($id)));
        return $r;
    }

    /** @return array<string, int> */
    public static function fleetCounts(): array
    {
        $out = ['available' => 0, 'repair' => 0, 'retired' => 0];
        foreach (Db::rows('SELECT status, COUNT(*) AS n FROM ' . Db::t('trucks') . ' GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        foreach (Db::rows('SELECT status, COUNT(*) AS n FROM ' . Db::t('equipment_units') . ' GROUP BY status') as $r) {
            $out[$r['status']] = ($out[$r['status']] ?? 0) + (int) $r['n'];
        }
        return $out;
    }

    /** Field definitions for building forms. */
    public static function fields(string $kind): array
    {
        return match ($kind) {
            'truck_type' => self::TRUCK_TYPE_FIELDS,
            'truck' => self::TRUCK_FIELDS,
            'item' => self::ITEM_FIELDS,
            'unit' => self::UNIT_FIELDS,
            default => [],
        };
    }

    // ----- Internals ---------------------------------------------------------------------------

    /**
     * Validate input against a field map. On update ($partial) only fields present are checked.
     *
     * @param array<string, array{0:string,1:string,2:bool,3:?float,4:?float}> $fields
     * @param array<string, mixed> $in
     * @return array<string, mixed>
     */
    private static function validate(array $fields, array $in, bool $partial): array
    {
        $out = [];
        foreach ($fields as $key => [$label, $type, $required, $min, $max]) {
            if ($type === 'bool') {
                if (array_key_exists($key, $in) || !$partial) {
                    $out[$key] = !empty($in[$key]) && $in[$key] !== '0' && $in[$key] !== 'false' ? 1 : 0;
                }
                continue;
            }
            if (!array_key_exists($key, $in)) {
                if ($partial) {
                    continue;
                }
                $in[$key] = '';
            }
            $v = is_string($in[$key]) ? trim(wp_unslash($in[$key])) : $in[$key];
            if ($v === '' || $v === null) {
                if ($required) {
                    throw new DomainError('invalid_input', "{$label} is required.", ['field' => $key]);
                }
                $out[$key] = in_array($type, ['money', 'int', 'decimal'], true) ? 0 : null;
                continue;
            }
            $out[$key] = self::cast($key, $label, $type, $v, $min, $max);
        }
        return $out;
    }

    private static function cast(string $key, string $label, string $type, mixed $v, ?float $min, ?float $max): mixed
    {
        $err = static fn(string $msg) => new DomainError('invalid_input', $msg, ['field' => $key]);
        if (str_starts_with($type, 'enum:')) {
            if (!in_array((string) $v, explode('|', substr($type, 5)), true)) {
                throw $err("{$label}: choose one of the options.");
            }
            return (string) $v;
        }
        switch ($type) {
            case 'money':
            case 'decimal':
            case 'int':
                $s = str_replace([',', '₱', ' '], '', (string) $v);
                if (!is_numeric($s)) {
                    throw $err("{$label} must be a number.");
                }
                $n = $type === 'int' ? (int) round((float) $s) : round((float) $s, 2);
                if (($min !== null && $n < $min) || ($max !== null && $n > $max)) {
                    throw $err("{$label} must be between " . number_format((float) $min) . ' and ' . number_format((float) $max) . '.');
                }
                return $n;
            case 'date':
                $d = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $v);
                if (!$d || $d->format('Y-m-d') !== (string) $v) {
                    throw $err("{$label} must be a date (YYYY-MM-DD).");
                }
                return (string) $v;
            case 'id':
                if (!Uuid::isHex(strtolower((string) $v))) {
                    throw $err("Choose a {$label}.");
                }
                return strtolower((string) $v);
            case 'plate':
                $s = strtoupper(preg_replace('/\s+/', ' ', sanitize_text_field((string) $v)) ?? '');
                if (!preg_match('/^[A-Z0-9][A-Z0-9 \-\/]*$/', $s) || strlen($s) < (int) $min || strlen($s) > (int) $max) {
                    throw $err("{$label} may use letters, numbers, spaces and dashes only.");
                }
                return $s;
            default:
                $s = sanitize_text_field((string) $v);
                if (mb_strlen($s) < (int) $min || mb_strlen($s) > (int) $max) {
                    throw $err("{$label} must be {$min}–{$max} characters.");
                }
                return $s;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<string> $binary hex id columns
     */
    private static function upsert(Actor $actor, string $table, string $objectType, array $data, ?string $id, string $duplicateMessage, array $binary = []): string
    {
        try {
            return Db::transaction(static function () use ($actor, $table, $objectType, $data, $id, $binary): string {
                if ($id === null) {
                    $newId = Uuid::v7();
                    Db::insert($table, ['id' => $newId] + $data + ['created_at' => Db::now()], array_merge(['id'], $binary));
                    Audit::log(null, $actor->userId, "{$objectType}.create", $objectType, $newId, self::auditMeta($data));
                    return $newId;
                }
                $id = Uuid::assertHex($id);
                if (!Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t($table) . ' WHERE id = UNHEX(%s)', $id))) {
                    throw new DomainError('not_found', 'Not found.');
                }
                $sets = [];
                $args = [];
                foreach ($data as $col => $val) {
                    if ($val === null) {
                        $sets[] = "`{$col}` = NULL";
                        continue;
                    }
                    $sets[] = in_array($col, $binary, true) ? "`{$col}` = UNHEX(%s)" : "`{$col}` = %s";
                    $args[] = (string) $val;
                }
                $sets[] = '`updated_at` = %s';
                $args[] = Db::now();
                $args[] = $id;
                Db::exec(Db::prepare('UPDATE ' . Db::t($table) . ' SET ' . implode(', ', $sets) . ' WHERE id = UNHEX(%s)', ...$args));
                Audit::log(null, $actor->userId, "{$objectType}.update", $objectType, $id, self::auditMeta($data));
                return $id;
            });
        } catch (DbError $e) {
            if ($e->isDuplicateKey()) {
                throw new DomainError('duplicate', $duplicateMessage);
            }
            throw $e;
        }
    }

    /** Rates and statuses go into the audit log so every price change is traceable. @return array<string, scalar|null> */
    private static function auditMeta(array $data): array
    {
        return array_filter($data, static fn($v, $k) => is_scalar($v) && !in_array($k, ['notes', 'label'], true), ARRAY_FILTER_USE_BOTH);
    }
}
