<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal\Screens;

use Canpas\Fleet\Domain\Catalog;
use Canpas\Fleet\Portal\Layout;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Portal\Ui;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Actor;

/** Trucks, heavy equipment and the formwork / scaffolding / tools catalog, with their rates. */
final class CatalogScreen
{
    public static function trucks(Actor $actor): void
    {
        $tab = ($_GET['tab'] ?? '') === 'types' ? 'types' : 'trucks';
        $edit = self::editId();
        $body = Ui::tabs(['trucks' => 'Trucks', 'types' => 'Truck types & rates'], $tab);

        if ($tab === 'types') {
            $canEdit = $actor->can('cf_manage_rates');
            $editing = $edit ? Catalog::find('truck_types', $edit) : null;
            $rows = [];
            foreach (Catalog::truckTypes() as $t) {
                $rows[] = [
                    '<b>' . Ui::e($t['name']) . '</b>' . ($t['capacity'] ? '<br><span class="muted small">' . Ui::e($t['capacity']) . '</span>' : ''),
                    Ui::e(Ui::money($t['starting_fee'])),
                    Ui::e(Ui::money($t['rate_per_km'])),
                    Ui::e(Ui::money($t['driver_flat_fee'])) . '<br><span class="muted small">first ' . (int) $t['driver_flat_km'] . ' km, then ' . Ui::e(Ui::money($t['driver_rate_per_km'])) . '/km</span>',
                    Ui::e(self::deposit($t)),
                    (string) (int) $t['trucks'],
                    $t['active'] ? Ui::pill('active', 'Offered') : Ui::pill('retired', 'Hidden'),
                    $canEdit ? self::editLink($t['id'], 'types') : '',
                ];
            }
            $body .= self::editor($canEdit, $editing, 'Truck type', 'truck-types', Catalog::fields('truck_type'), [], ['deposit_mode' => 'percent', 'driver_flat_km' => 10, 'active' => 1]);
            $body .= Ui::card('Truck types', Ui::table(['Type', '>Starting fee', '>Per km', 'Company driver', 'Deposit', '>Trucks', 'Status', ''], $rows, 'Add the kinds of trucks Canpas rents out, with their rates.'));
            $body .= '<p class="muted small" style="margin-top:12px">Rates are copied onto each order when it is booked. Changing a rate here never changes existing orders. Every change is recorded with who made it.</p>';
        } else {
            $canEdit = $actor->can('cf_manage_fleet');
            $types = [];
            foreach (Catalog::truckTypes() as $t) {
                $types[$t['id']] = $t['name'];
            }
            $editing = $edit ? Catalog::find('trucks', $edit) : null;
            $rows = [];
            foreach (Catalog::trucks() as $k) {
                $rows[] = [
                    '<span class="mono">' . Ui::e($k['plate']) . '</span>' . ($k['label'] ? '<br><span class="muted small">' . Ui::e($k['label']) . '</span>' : ''),
                    Ui::e($k['type_name']),
                    Ui::e(number_format((int) $k['odometer_km'])) . ' km',
                    self::expiry($k['registration_expiry']),
                    self::expiry($k['insurance_expiry']),
                    Ui::pill($k['status']),
                    $canEdit ? self::editLink($k['id'], 'trucks') : '',
                ];
            }
            if ($types === []) {
                $body .= Ui::card('', '<p class="empty">Add a truck type first, then add the trucks.</p>');
            } else {
                $body .= self::editor($canEdit, $editing, 'Truck', 'trucks', Catalog::fields('truck'), ['type_id' => $types], ['status' => 'available', 'odometer_km' => 0]);
            }
            $body .= Ui::card('Trucks', Ui::table(['Plate', 'Type', '>Odometer', 'Registration', 'Insurance', 'Status', ''], $rows, 'No trucks yet.'));
        }
        Layout::page($actor, 'trucks', 'Trucks', $body, 'Fleet & stock');
    }

    public static function heavy(Actor $actor): void
    {
        $tab = ($_GET['tab'] ?? '') === 'units' ? 'units' : 'items';
        $edit = self::editId();
        $body = Ui::tabs(['items' => 'Equipment & rates', 'units' => 'Units by serial'], $tab);
        if ($tab === 'items') {
            $canEdit = $actor->can('cf_manage_rates');
            $editing = $edit ? Catalog::find('equipment_items', $edit) : null;
            $body .= self::editor($canEdit, $editing, 'Heavy equipment', 'items', self::itemFields(true), [], ['category' => 'heavy', 'unit' => 'unit', 'min_days' => 1, 'deposit_mode' => 'percent', 'active' => 1], ['category' => 'heavy']);
            $body .= Ui::card('Heavy equipment', self::itemTable(Catalog::items('heavy'), $canEdit, 'items', true));
        } else {
            $canEdit = $actor->can('cf_manage_fleet');
            $items = [];
            foreach (Catalog::items('heavy') as $i) {
                $items[$i['id']] = $i['name'];
            }
            $editing = $edit ? Catalog::find('equipment_units', $edit) : null;
            $rows = [];
            foreach (Catalog::units() as $u) {
                $rows[] = [
                    '<span class="mono">' . Ui::e($u['serial']) . '</span>',
                    Ui::e($u['item_name']),
                    Ui::e(number_format((float) $u['hour_meter'], 1)) . ' h',
                    self::expiry($u['inspection_expiry']),
                    Ui::pill($u['status']),
                    $canEdit ? self::editLink($u['id'], 'units') : '',
                ];
            }
            $body .= $items === []
                ? Ui::card('', '<p class="empty">Add the heavy equipment with its rates first, then add each unit by serial number.</p>')
                : self::editor($canEdit, $editing, 'Unit', 'units', Catalog::fields('unit'), ['item_id' => $items], ['status' => 'available']);
            $body .= Ui::card('Units', Ui::table(['Serial', 'Equipment', '>Hour meter', 'Inspection', 'Status', ''], $rows, 'No units yet.'));
        }
        Layout::page($actor, 'heavy-equipment', 'Heavy equipment', $body, 'Fleet & stock');
    }

    public static function stock(Actor $actor): void
    {
        $cat = sanitize_key((string) ($_GET['cat'] ?? ''));
        $cat = in_array($cat, ['formwork', 'scaffolding', 'tools'], true) ? $cat : '';
        $canEdit = $actor->can('cf_manage_rates');
        $edit = self::editId();
        $editing = $edit ? Catalog::find('equipment_items', $edit) : null;
        $body = Ui::tabs(['' => 'All', 'formwork' => 'Formwork', 'scaffolding' => 'Scaffolding', 'tools' => 'Small tools & accessories'], $cat, 'cat');
        $body .= self::editor($canEdit, $editing, 'Item', 'items', self::itemFields(false), [], ['category' => $cat ?: 'scaffolding', 'unit' => 'pc', 'min_days' => 7, 'deposit_mode' => 'percent', 'active' => 1]);
        $body .= Ui::card('Items and rates', self::itemTable(Catalog::items($cat !== '' ? $cat : 'nonheavy'), $canEdit, null, false));
        $body .= '<p class="muted small" style="margin-top:12px">Quantities owned, out on rent and available by date arrive with ordering in milestone 2. Customers are charged the cheapest mix of months, weeks and days, never less than the minimum rental.</p>';
        Layout::page($actor, 'stock', 'Formwork & scaffolding', $body, 'Fleet & stock');
    }

    // ----- helpers -----------------------------------------------------------------------------

    /** @param list<array<string,mixed>> $items */
    private static function itemTable(array $items, bool $canEdit, ?string $tab, bool $heavy): string
    {
        $rows = [];
        foreach ($items as $i) {
            $row = [
                '<b>' . Ui::e($i['name']) . '</b><br><span class="muted small">' . Ui::e(Ui::label($i['category'])) . ' · per ' . Ui::e(strtolower(Ui::label($i['unit']))) . '</span>',
                Ui::e(Ui::money($i['rate_day'])),
                Ui::e(Ui::money($i['rate_week'])),
                Ui::e(Ui::money($i['rate_month'])),
                (string) (int) $i['min_days'] . ' d',
                Ui::e(self::deposit($i)),
                Ui::e(Ui::money($i['replacement_cost'])) . '<br><span class="muted small">damage ' . Ui::e(Ui::money($i['damage_fee'])) . '</span>',
            ];
            if ($heavy) {
                $row[] = (string) (int) $i['units'];
            }
            $row[] = $i['active'] ? Ui::pill('active', 'Offered') : Ui::pill('retired', 'Hidden');
            $row[] = $canEdit ? self::editLink($i['id'], $tab) : '';
            $rows[] = $row;
        }
        $head = ['Item', '>Day', '>Week', '>Month', '>Min.', 'Deposit', '>Replacement'];
        if ($heavy) {
            $head[] = '>Units';
        }
        array_push($head, 'Status', '');
        return Ui::table($head, $rows, 'No items yet.');
    }

    /** @return array<string, array> */
    private static function itemFields(bool $heavy): array
    {
        $f = Catalog::fields('item');
        if ($heavy) {
            unset($f['category'], $f['weight_kg']);
        } else {
            $f['category'][1] = 'enum:formwork|scaffolding|tools';
        }
        return $f;
    }

    /**
     * Add/edit form card. When $editing is set the form updates that row.
     *
     * @param array<string,array> $fields
     * @param array<string,array<string,string>> $options
     * @param array<string,mixed> $defaults
     * @param array<string,mixed> $fixed hidden values always sent (e.g. category=heavy)
     */
    private static function editor(bool $canEdit, ?array $editing, string $noun, string $endpoint, array $fields, array $options, array $defaults, array $fixed = []): string
    {
        if (!$canEdit) {
            return '';
        }
        $hidden = '';
        foreach ($fixed as $k => $v) {
            $hidden .= '<input type="hidden" name="' . esc_attr($k) . '" value="' . esc_attr((string) $v) . '">';
        }
        if ($editing) {
            $form = Ui::form($endpoint . '/' . $editing['id'], $fields, $editing, $options, 'Save changes', false, 'redirect:' . remove_query_arg('edit'));
            $form = str_replace('<div class="form-grid">', $hidden . '<div class="form-grid">', $form);
            $cancel = '<a class="btn" href="' . esc_url(remove_query_arg('edit')) . '">Cancel</a>';
            return Ui::card('Edit ' . strtolower($noun), $form, $cancel);
        }
        $form = Ui::form($endpoint, $fields, $defaults, $options, 'Add ' . strtolower($noun));
        $form = str_replace('<div class="form-grid">', $hidden . '<div class="form-grid">', $form);
        return '<details class="card adder"><summary><span class="btn primary">+ Add ' . Ui::e(strtolower($noun)) . '</span></summary><div style="margin-top:16px">' . $form . '</div></details>';
    }

    private static function editLink(string $id, ?string $tab): string
    {
        $args = ['edit' => $id];
        if ($tab !== null) {
            $args['tab'] = $tab;
        }
        return '<div class="row-actions"><a class="btn" href="' . esc_url(add_query_arg($args)) . '">Edit</a></div>';
    }

    private static function editId(): ?string
    {
        $id = strtolower(sanitize_key((string) ($_GET['edit'] ?? '')));
        return Uuid::isHex($id) ? $id : null;
    }

    private static function deposit(array $row): string
    {
        return $row['deposit_mode'] === 'percent'
            ? rtrim(rtrim(number_format((float) $row['deposit_value'], 2), '0'), '.') . '% of order'
            : Ui::money($row['deposit_value']);
    }

    /** Expiry date with a warning when within 30 days or past. */
    private static function expiry(?string $date): string
    {
        if (!$date) {
            return '<span class="muted">—</span>';
        }
        $days = (int) floor((strtotime($date) - Db::todayManila()->getTimestamp()) / DAY_IN_SECONDS);
        $text = Ui::e(Ui::date($date));
        if ($days < 0) {
            return $text . ' ' . Ui::pill('suspended', 'Expired');
        }
        if ($days <= 30) {
            return $text . ' ' . Ui::pill('pending', "{$days} days");
        }
        return $text;
    }
}
