<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal\Screens;

use Canpas\Fleet\Domain\Catalog;
use Canpas\Fleet\Domain\Orders;
use Canpas\Fleet\Domain\Pricing;
use Canpas\Fleet\Domain\Stock;
use Canpas\Fleet\Portal\Layout;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Portal\Ui;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Actor;

/** Booking (customers), order lists and order detail (customers and staff). */
final class OrdersScreen
{
    // ----- Customer: book a truck --------------------------------------------------------------

    public static function bookTruck(Actor $actor): void
    {
        $types = Catalog::truckTypes(true);
        if ($types === []) {
            Layout::page($actor, 'book-truck', 'Book a truck', Ui::card('', '<p class="empty">No trucks are offered yet. Please contact Canpas.</p>'), 'My account');
            return;
        }
        $opts = '';
        foreach ($types as $t) {
            $opts .= '<option value="' . esc_attr($t['id']) . '">' . Ui::e($t['name'] . ($t['capacity'] ? " ({$t['capacity']})" : '')) . '</option>';
        }
        $rates = [];
        foreach ($types as $t) {
            $rates[] = [
                '<b>' . Ui::e($t['name']) . '</b>' . ($t['capacity'] ? '<br><span class="muted small">' . Ui::e($t['capacity']) . '</span>' : ''),
                Ui::e(Ui::money($t['starting_fee'])),
                Ui::e(Ui::money($t['rate_per_km'])),
                Ui::e(Ui::money($t['driver_flat_fee'])) . '<br><span class="muted small">first ' . (int) $t['driver_flat_km'] . ' km, then ' . Ui::e(Ui::money($t['driver_rate_per_km'])) . '/km</span>',
            ];
        }
        $form = '<form class="form" data-endpoint="orders/truck" data-after="reload" novalidate><div class="form-grid">'
            . '<div class="field"><label for="f-type">Truck type</label><select id="f-type" name="truck_type_id" required>' . $opts . '</select></div>'
            . self::dateFields()
            . '<div class="field check"><label><input type="checkbox" name="needs_driver" value="1" checked> Canpas driver drives the truck</label></div>'
            . self::siteFields(true)
            . '<div class="field wide"><label for="f-notes">Notes for Canpas <span class="opt">(optional)</span></label><textarea id="f-notes" name="notes" rows="2" maxlength="500"></textarea></div>'
            . '</div>'
            . '<div class="quote-box" hidden aria-live="polite"></div>'
            . '<p class="form-error" role="alert" hidden></p>'
            . '<div class="form-actions"><button type="button" class="btn" data-quote="quote/truck">Get price</button><button type="submit" class="btn primary" data-needs-quote disabled>Book and hold</button></div>'
            . '</form>';
        $body = '<div class="split">' . Ui::card('Your booking', $form)
            . Ui::card('Rates', Ui::table(['Truck', '>Starting fee', '>Per km', 'Canpas driver'], $rates)
                . '<p class="muted small" style="margin-top:12px">Distance is the round trip from the Canpas yard to your site and back.</p>') . '</div>';
        Layout::page($actor, 'book-truck', 'Book a truck', $body, 'My account');
    }

    // ----- Customer: rent equipment ------------------------------------------------------------

    public static function rent(Actor $actor): void
    {
        $items = Catalog::items(null, true);
        if ($items === []) {
            Layout::page($actor, 'rent', 'Rent equipment', Ui::card('', '<p class="empty">No equipment is offered yet. Please contact Canpas.</p>'), 'My account');
            return;
        }
        $today = Db::todayManila()->format('Y-m-d');
        $groups = [];
        foreach ($items as $i) {
            $groups[$i['category']][] = $i;
        }
        $tables = '';
        foreach (Catalog::CATEGORIES as $cat => $label) {
            if (empty($groups[$cat])) {
                continue;
            }
            $rows = [];
            foreach ($groups[$cat] as $i) {
                $free = Orders::itemFree($i['id'], $today, $today);
                $rows[] = [
                    '<label for="q-' . esc_attr($i['id']) . '"><b>' . Ui::e($i['name']) . '</b></label><br><span class="muted small">per ' . Ui::e(strtolower(Ui::label($i['unit']))) . ' · minimum ' . (int) $i['min_days'] . ' day' . ((int) $i['min_days'] === 1 ? '' : 's') . '</span>',
                    Ui::e(Ui::money($i['rate_day'])),
                    Ui::e(Ui::money($i['rate_week'])),
                    Ui::e(Ui::money($i['rate_month'])),
                    $free > 0 ? number_format($free) . ' free today' : '<span class="muted">Check dates</span>',
                    '<input id="q-' . esc_attr($i['id']) . '" class="qty num" name="qty[' . esc_attr($i['id']) . ']" type="number" min="0" max="100000" step="1" inputmode="numeric" placeholder="0">',
                ];
            }
            $tables .= '<h3 class="cat">' . Ui::e($label) . '</h3>' . Ui::table(['Item', '>Day', '>Week', '>Month', 'Stock', 'Quantity'], $rows);
        }
        $form = '<form class="form" data-endpoint="orders/equipment" data-after="reload" novalidate>'
            . '<div class="form-grid">' . self::dateFields()
            . '<div class="field"><label for="f-ful">Delivery</label><select id="f-ful" name="fulfilment"><option value="delivery">Canpas delivers and collects</option><option value="pickup">We pick up and return at the yard</option></select></div>'
            . self::siteFields(false)
            . '</div>'
            . '<div class="items">' . $tables . '</div>'
            . '<div class="form-grid"><div class="field wide"><label for="f-notes">Notes for Canpas <span class="opt">(optional)</span></label><textarea id="f-notes" name="notes" rows="2" maxlength="500"></textarea></div></div>'
            . '<div class="quote-box" hidden aria-live="polite"></div>'
            . '<p class="form-error" role="alert" hidden></p>'
            . '<div class="form-actions"><button type="button" class="btn" data-quote="quote/equipment">Get price</button><button type="submit" class="btn primary" data-needs-quote disabled>Place order and hold</button></div>'
            . '</form>';
        $note = '<p class="muted small" style="margin-top:12px">Each piece is charged the cheapest mix of months (30 days), weeks and days for your rental, never less than its minimum. Delivery is priced as a truck trip with a Canpas driver.</p>';
        Layout::page($actor, 'rent', 'Rent equipment', Ui::card('', $form . $note), 'My account');
    }

    // ----- Lists and detail --------------------------------------------------------------------

    public static function myOrders(Actor $actor): void
    {
        $id = self::idParam();
        if ($id !== null) {
            self::detail($actor, $id, 'my-orders');
            return;
        }
        $rows = [];
        foreach (Orders::list($actor) as $o) {
            $rows[] = self::listRow($o, 'my-orders', false);
        }
        $actions = '<a class="btn" href="' . esc_url(Router::url('book-truck')) . '">Book a truck</a><a class="btn primary" href="' . esc_url(Router::url('rent')) . '">Rent equipment</a>';
        Layout::page($actor, 'my-orders', 'My orders', Ui::card('', Ui::table(['Order', 'What', 'Dates', 'Status', '>Total'], $rows, 'No orders yet.')), 'My account', $actions);
    }

    public static function staffOrders(Actor $actor): void
    {
        $id = self::idParam();
        if ($id !== null) {
            self::detail($actor, $id, 'orders');
            return;
        }
        $status = sanitize_key((string) ($_GET['status'] ?? ''));
        $status = isset(Orders::STATUS_LABELS[$status]) ? $status : '';
        $counts = Orders::counts();
        $tabs = ['' => 'All (' . array_sum($counts) . ')'];
        foreach (['submitted', 'requested', 'needs_fix', 'confirmed', 'declined', 'expired', 'cancelled'] as $s) {
            $label = ['requested' => 'On hold with customer', 'needs_fix' => 'Sent back to customer'][$s] ?? Orders::STATUS_LABELS[$s];
            $tabs[$s] = $label . ' (' . ($counts[$s] ?? 0) . ')';
        }
        $rows = [];
        foreach (Orders::list($actor, $status) as $o) {
            $rows[] = self::listRow($o, 'orders', true);
        }
        Layout::page($actor, 'orders', 'Orders', Ui::tabs($tabs, $status, 'status') . Ui::card('', Ui::table(['Order', 'Company', 'What', 'Dates', 'Status', '>Total'], $rows, 'No orders here.')), 'Operations');
    }

    private static function detail(Actor $actor, string $id, string $page): void
    {
        try {
            $o = Orders::get($actor, $id);
        } catch (DomainError $e) {
            Layout::message('Order not found', $e->getMessage(), 404, ['label' => 'Back to orders', 'href' => Router::url($page)]);
            return;
        }
        if ($page === 'my-orders' && !empty($_GET['sign'])) {
            RequirementsScreen::signPage($actor, $o);
            return;
        }
        $site = $o['fulfilment'] === 'pickup'
            ? 'Customer picks up at the Canpas yard'
            : Ui::e((string) $o['site_address']) . ($o['distance_km'] !== null ? '<br><span class="muted small">' . Ui::e(number_format((float) $o['distance_km'], 1)) . ' km round trip' . ($o['distance_source'] === 'estimate' ? ' (estimate)' : '') . '</span>' : '');
        $dl = '<dl class="dl">'
            . '<dt>Status</dt><dd>' . self::statusPill($o['status']) . ($o['status_reason'] ? ' <span class="muted small">' . Ui::e($o['status_reason']) . '</span>' : '') . '</dd>'
            . ($o['status'] === 'confirmed' ? '<dt>Confirmed</dt><dd>' . Ui::e(Ui::when($o['confirmed_at'])) . '</dd>' : '')
            . ($o['hold_active'] ? '<dt>Held until</dt><dd><b>' . Ui::e(Ui::when($o['hold_expires_at'])) . '</b></dd>' : '')
            . '<dt>Company</dt><dd>' . Ui::e($o['company_name']) . '</dd>'
            . '<dt>Placed by</dt><dd>' . Ui::e($o['created_by_name']) . ' · ' . Ui::e(Ui::when($o['created_at'])) . '</dd>'
            . '<dt>Dates</dt><dd>' . Ui::e(Ui::date($o['start_date'])) . ' – ' . Ui::e(Ui::date($o['end_date'])) . ' <span class="muted">(' . (int) $o['days'] . ' day' . ((int) $o['days'] === 1 ? '' : 's') . ')</span></dd>'
            . '<dt>' . ($o['kind'] === 'truck' ? 'Site' : 'Delivery') . '</dt><dd>' . $site . '</dd>'
            . ($o['notes'] ? '<dt>Notes</dt><dd>' . Ui::e($o['notes']) . '</dd>' : '')
            . '</dl>';

        $lines = [];
        foreach ($o['lines'] as $l) {
            $lines[] = ['line_type' => $l['line_type'], 'description' => $l['description'], 'qty' => (int) $l['qty'], 'charged_days' => (int) $l['charged_days'],
                'unit_price' => (float) $l['unit_price'], 'amount' => (float) $l['amount'], 'deposit' => (float) $l['deposit'],
                'breakdown' => json_decode((string) $l['breakdown_json'], true) ?: []];
        }
        $draft = ['lines' => $lines, 'subtotal' => (float) $o['subtotal'], 'deposit' => (float) $o['deposit_amount'],
            'distance_km' => $o['distance_km'] !== null ? (float) $o['distance_km'] : null, 'distance_source' => $o['distance_source'], 'kind' => $o['kind']];

        $actions = '';
        $staff = $actor->can('cf_manage_orders');
        if ($staff) {
            if ($o['status'] === 'submitted') {
                $actions .= Ui::action('Send back', "orders/{$o['id']}/send-back", '', '', 'What should the customer fix? They see this note.')
                    . Ui::action('Confirm order', "orders/{$o['id']}/confirm", 'primary', "Confirm order {$o['ref']}? The customer is emailed.");
            }
            if (in_array($o['status'], ['requested', 'submitted', 'needs_fix'], true)) {
                $actions = Ui::action('Decline', "orders/{$o['id']}/decline", 'danger', '', 'Why is this order declined? The customer sees this reason.') . $actions;
            }
        } elseif (in_array($o['status'], ['requested', 'needs_fix'], true)) {
            $actions = Ui::action('Cancel order', "orders/{$o['id']}/cancel", 'danger', "Cancel order {$o['ref']}? The trucks and items are released.");
        }
        $showReq = !in_array($o['status'], ['cancelled', 'expired'], true) || !empty($o['gov_id_doc']) || !empty($o['signed_at']);
        $body = '<div class="split">' . Ui::card('Order', $dl) . Ui::card('Price', self::breakdown($draft)) . '</div>'
            . ($showReq ? RequirementsScreen::card($actor, $o) : '');
        Layout::page($actor, $page, $o['ref'], $body, $page === 'orders' ? 'Operations / Orders' : 'My account / My orders', $actions);
    }

    /** Price breakdown table for a quote or a saved order. Escapes everything. */
    public static function breakdown(array $d): string
    {
        $rows = [];
        foreach ($d['lines'] as $l) {
            $b = $l['breakdown'] ?? [];
            if ($l['line_type'] === 'item') {
                $what = '<b>' . Ui::e($l['description']) . '</b><br><span class="muted small">' . Ui::e($b['period'] ?? Pricing::periodLabel($b + ['months' => 0, 'weeks' => 0, 'days' => 0]))
                    . ' (' . (int) $l['charged_days'] . ' days charged) at ' . Ui::e(Ui::money($l['unit_price'])) . ' each</span>';
                $qty = number_format((int) $l['qty']);
            } else {
                $parts = [];
                if (isset($b['starting_fee'])) {
                    $parts[] = 'start ' . Ui::money($b['starting_fee']);
                    $parts[] = number_format((float) ($b['billable_km'] ?? $b['km'] ?? 0), 1) . ' km × rate = ' . Ui::money($b['distance'] ?? 0);
                    if (($b['driver'] ?? 0) > 0) {
                        $parts[] = 'driver ' . Ui::money($b['driver']);
                    }
                    if (($b['daily'] ?? 0) > 0) {
                        $parts[] = 'daily ' . Ui::money($b['daily']);
                    }
                }
                $what = '<b>' . Ui::e($l['description']) . '</b><br><span class="muted small">' . Ui::e(implode(' + ', $parts)) . ($l['line_type'] === 'delivery' ? Ui::e(' per trip') : '') . '</span>';
                $qty = $l['line_type'] === 'delivery' ? (int) $l['qty'] . ' trip' . ((int) $l['qty'] === 1 ? '' : 's') : '1';
            }
            $rows[] = [$what, $qty, Ui::e(Ui::money($l['amount']))];
        }
        $dist = '';
        if (!empty($d['distance_km'])) {
            $dist = '<p class="muted small">Round trip from the Canpas yard: ' . Ui::e(number_format((float) $d['distance_km'], 1)) . ' km'
                . (($d['distance_source'] ?? '') === 'estimate' ? ' — <b>estimate</b>; the final bill uses the actual kilometres' : '') . '.</p>';
        }
        return Ui::table(['Item', '>Qty', '>Amount'], $rows)
            . '<dl class="totals"><dt>Total</dt><dd class="num">' . Ui::e(Ui::money($d['subtotal'])) . '</dd>'
            . '<dt>Deposit due before release</dt><dd class="num">' . Ui::e(Ui::money($d['deposit'])) . '</dd></dl>' . $dist;
    }

    // ----- Stock page section (staff) -----------------------------------------------------------

    /** Stock levels and the adjust form, shown on the Formwork & scaffolding page. */
    public static function stockSection(Actor $actor): string
    {
        $today = Db::todayManila()->format('Y-m-d');
        $onHand = Stock::onHandAll();
        $rows = [];
        $options = [];
        foreach (Catalog::items('nonheavy') as $i) {
            $have = $onHand[$i['id']] ?? 0;
            $out = Orders::peakBooked('item_id', $i['id'], $today, $today, 'item');
            $rows[] = ['<b>' . Ui::e($i['name']) . '</b>', number_format($have), number_format($out), number_format(max(0, $have - $out))];
            $options[$i['id']] = $i['name'];
        }
        $html = Ui::card('Stock today', Ui::table(['Item', '>On hand', '>Reserved today', '>Free today'], $rows, 'No items yet.'));
        if ($actor->can('cf_manage_stock') && $options !== []) {
            $fields = [
                'item_id' => ['Item', 'id', true, null, null],
                'qty' => ['Quantity (+ add, − remove)', 'int', true, null, null],
                'reason' => ['Reason', 'enum:' . implode('|', array_keys(Stock::REASONS)), true, null, null],
                'note' => ['Note', 'text', false, null, null],
            ];
            $form = Ui::form('stock', $fields, ['reason' => 'purchase'], ['item_id' => $options], 'Update stock');
            foreach (Stock::REASONS as $k => $label) {
                $form = str_replace('>' . Ui::e(Ui::label($k)) . '<', '>' . Ui::e($label) . '<', $form);
            }
            $html .= '<details class="card adder"><summary><span class="btn primary">+ Add or remove stock</span></summary><div style="margin-top:16px">' . $form . '</div></details>';
        }
        return $html;
    }

    // ----- helpers -----------------------------------------------------------------------------

    private static function listRow(array $o, string $page, bool $withCompany): array
    {
        $row = ['<a href="' . esc_url(add_query_arg('id', $o['id'], Router::url($page))) . '" class="mono"><b>' . Ui::e($o['ref']) . '</b></a>'];
        if ($withCompany) {
            $row[] = Ui::e($o['company_name']);
        }
        $row[] = Ui::e(mb_strimwidth((string) $o['summary'], 0, 70, '…'));
        $row[] = Ui::e(Ui::date($o['start_date'])) . ' <span class="muted small">· ' . (int) $o['days'] . ' d</span>';
        $row[] = self::statusPill($o['status']);
        $row[] = Ui::e(Ui::money($o['total']));
        return $row;
    }

    public static function statusPill(string $status): string
    {
        $tone = match ($status) {
            'requested', 'needs_fix' => 'pending',
            'confirmed', 'dispatched', 'on_site', 'returned', 'closed' => 'approved',
            'cancelled', 'declined', 'expired' => 'retired',
            default => 'info',
        };
        return Ui::pill($tone, Orders::STATUS_LABELS[$status] ?? $status);
    }

    private static function dateFields(): string
    {
        $today = Db::todayManila()->format('Y-m-d');
        return '<div class="field"><label for="f-start">Start date</label><input id="f-start" name="start_date" type="date" min="' . esc_attr($today) . '" required></div>'
            . '<div class="field"><label for="f-days">Number of days</label><input id="f-days" name="days" type="number" min="1" max="365" step="1" value="1" inputmode="numeric" class="num" required></div>';
    }

    private static function siteFields(bool $required): string
    {
        $opt = $required ? '' : ' <span class="opt">(for delivery)</span>';
        return '<div class="field wide"><label for="f-site">Site address' . $opt . '</label><input id="f-site" name="site_address" maxlength="255" autocomplete="street-address" placeholder="Building, street, barangay, city"></div>'
            . '<div class="field wide"><label for="f-coords">Site location on the map' . $opt . '</label><input id="f-coords" name="site_coords" inputmode="decimal" placeholder="e.g. 14.4296, 120.9367">'
            . '<small class="muted">In Google Maps, right-click your site and click the two numbers at the top to copy them, then paste here.</small></div>';
    }

    private static function idParam(): ?string
    {
        $id = strtolower(sanitize_key((string) ($_GET['id'] ?? '')));
        return Uuid::isHex($id) ? $id : null;
    }
}
