<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal\Screens;

use Canpas\Fleet\Domain\Dispatch;
use Canpas\Fleet\Domain\Drivers;
use Canpas\Fleet\Domain\Expenses;
use Canpas\Fleet\Domain\Orders;
use Canpas\Fleet\Domain\Trips;
use Canpas\Fleet\Domain\Yard;
use Canpas\Fleet\Portal\Layout;
use Canpas\Fleet\Portal\Pages;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Portal\Ui;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Office screens for running the fleet: dispatch board, calendar, drivers, and the
 * operations card on each order (assigning, yard actions, the trip timeline with photos).
 */
final class OpsScreen
{
    // ----- Dispatch board ----------------------------------------------------------------------

    public static function dispatch(Actor $actor): void
    {
        $b = Dispatch::board($actor);
        $kpis = '<div class="grid kpis">'
            . Ui::kpi('To assign', (string) count($b['assign']), 'Confirmed, next 14 days', $b['assign'] ? 'warn' : '')
            . Ui::kpi('Going out', (string) count($b['out']), 'Next 7 days')
            . Ui::kpi('Coming back', (string) count($b['back']), 'Next 7 days')
            . Ui::kpi('Overdue', (string) count($b['overdue']), 'Past their end date', $b['overdue'] ? 'bad' : '') . '</div>';

        $row = static function (array $o, string $dateCol) use ($actor): array {
            $a = Dispatch::assignments((string) $o['id']);
            $what = [];
            foreach ($a['trucks'] as $t) {
                $what[] = '<span class="mono">' . Ui::e($t['plate']) . '</span>';
            }
            foreach ($a['trips'] as $k => $tr) {
                $what[] = Ui::e(Dispatch::TRIP_KINDS[$k]) . ': ' . Ui::e($tr['driver_name'] ?: '—') . ($tr['status'] !== 'planned' ? ' · ' . Ui::e(Trips::LABELS[$tr['status']] ?? $tr['status']) : '');
            }
            $ready = $o['gaps'] !== []
                ? '<span class="pill warn">To assign: ' . Ui::e(implode(', ', $o['gaps'])) . '</span>'
                : ($o['status'] === 'confirmed' ? '<span class="pill good">Ready</span>' : OrdersScreen::statusPill($o['status']));
            return [
                '<b>' . Ui::e(Ui::date($o[$dateCol])) . '</b>',
                '<a class="mono" href="' . esc_url(add_query_arg('id', $o['id'], Router::url('orders'))) . '"><b>' . Ui::e($o['ref']) . '</b></a><br><span class="muted small">' . Ui::e($o['company_name']) . '</span>',
                Ui::e((string) $o['summary']) . '<br><span class="muted small">' . Ui::e($o['fulfilment'] === 'pickup' ? 'Customer pickup at yard' : (string) $o['site_address']) . '</span>',
                $what ? implode('<br>', $what) : '<span class="muted">—</span>',
                $ready,
            ];
        };
        $head = ['Date', 'Order', 'What and where', 'Assigned', 'Status'];
        $body = $kpis
            . ($b['overdue'] ? Ui::card('Overdue returns', Ui::table($head, array_map(static fn($o) => $row($o, 'end_date'), $b['overdue']))) : '')
            . Ui::card('To assign', Ui::table($head, array_map(static fn($o) => $row($o, 'start_date'), $b['assign']), 'Every confirmed order in the next 14 days has its trucks, units and drivers.'))
            . '<div class="split">' . Ui::card('Going out — next 7 days', Ui::table($head, array_map(static fn($o) => $row($o, 'start_date'), $b['out']), 'Nothing going out.'))
            . Ui::card('Coming back — next 7 days', Ui::table($head, array_map(static fn($o) => $row($o, 'end_date'), $b['back']), 'Nothing due back.')) . '</div>';
        Layout::page($actor, 'dispatch', 'Dispatch board', $body, 'Operations', '<a class="btn" href="' . esc_url(Router::url('calendar')) . '">Calendar</a>');
    }

    // ----- Calendar ----------------------------------------------------------------------------

    public static function calendar(Actor $actor): void
    {
        $from = (string) ($_GET['from'] ?? '');
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);
        $start = $d && $d->format('Y-m-d') === $from ? $d : Db::todayManila();
        $days = 14;
        $rows = Dispatch::calendar($actor, $start->format('Y-m-d'), $days);
        $today = Db::todayManila()->format('Y-m-d');
        $head = '<th class="cal-name">Truck / unit</th>';
        for ($i = 0; $i < $days; $i++) {
            $day = $start->modify("+{$i} days");
            $head .= '<th class="' . ($day->format('Y-m-d') === $today ? 'today' : '') . ($day->format('N') === '7' ? ' sun' : '') . '">' . $day->format('D') . '<br><b>' . $day->format('j') . '</b></th>';
        }
        $body = '';
        $lastKind = '';
        foreach ($rows as $r) {
            if ($r['kind'] !== $lastKind) {
                $body .= '<tr class="cal-group"><td colspan="' . ($days + 1) . '">' . ($r['kind'] === 'truck' ? 'Trucks' : 'Heavy equipment') . '</td></tr>';
                $lastKind = $r['kind'];
            }
            $body .= '<tr><th class="cal-name"><span class="mono">' . Ui::e($r['label']) . '</span><small>' . Ui::e($r['sub']) . '</small></th>';
            for ($i = 0; $i < $days; $i++) {
                $key = $start->modify("+{$i} days")->format('Y-m-d');
                $cells = '';
                foreach ($r['days'][$key] ?? [] as $c) {
                    $cells .= '<a class="chip ' . esc_attr($c['what']) . '" href="' . esc_url(add_query_arg('id', $c['id'], Router::url('orders'))) . '" title="' . esc_attr(Dispatch::TRIP_KINDS[$c['what']] ?? 'Rental') . '">'
                        . Ui::e(preg_replace('/^[A-Z]+-\d{4}-/', '', $c['ref'])) . ($c['what'] !== 'rental' ? ' ' . ($c['what'] === 'delivery' ? '↗' : '↙') : '') . '</a>';
                }
                $body .= '<td class="' . ($key === $today ? 'today' : '') . '">' . $cells . '</td>';
            }
            $body .= '</tr>';
        }
        $nav = '<div class="row-actions"><a class="btn" href="' . esc_url(add_query_arg('from', $start->modify('-14 days')->format('Y-m-d'), Router::url('calendar'))) . '">← Earlier</a>'
            . '<a class="btn" href="' . esc_url(Router::url('calendar')) . '">Today</a>'
            . '<a class="btn" href="' . esc_url(add_query_arg('from', $start->modify('+14 days')->format('Y-m-d'), Router::url('calendar'))) . '">Later →</a></div>';
        $legend = '<p class="muted small cal-legend"><span class="chip rental">0001</span> on rental (order number) <span class="chip delivery">0002 ↗</span> delivery run <span class="chip pickup">0003 ↙</span> pickup run. A truck or unit cannot be on two rentals the same day.</p>';
        $grid = $rows === [] ? '<p class="empty">No trucks or heavy units yet.</p>'
            : '<div class="cal-wrap"><table class="cal"><thead><tr>' . $head . '</tr></thead><tbody>' . $body . '</tbody></table></div>';
        Layout::page($actor, 'calendar', 'Calendar · ' . $start->format('j M') . ' – ' . $start->modify('+' . ($days - 1) . ' days')->format('j M Y'), Ui::card('', $grid . $legend), 'Operations', $nav);
    }

    // ----- Drivers -----------------------------------------------------------------------------

    public static function drivers(Actor $actor): void
    {
        $canEdit = $actor->can('cf_manage_staff');
        $soon = Db::todayManila()->modify('+30 days')->format('Y-m-d');
        $today = Db::todayManila()->format('Y-m-d');
        $rows = [];
        foreach (Drivers::list($actor) as $d) {
            $exp = $d['license_expiry'];
            $pill = $exp === null ? '<span class="pill warn">No licence on file</span>'
                : ($exp < $today ? '<span class="pill bad">Expired ' . Ui::e(Ui::date($exp)) . '</span>'
                : ($exp <= $soon ? '<span class="pill warn">Expires ' . Ui::e(Ui::date($exp)) . '</span>' : Ui::e(Ui::date($exp))));
            $edit = '';
            if ($canEdit) {
                $edit = '<details class="upload"><summary>Edit</summary><form class="form" data-endpoint="drivers/' . (int) $d['user_id'] . '" novalidate><div class="form-grid">'
                    . self::input('phone', 'Phone', (string) $d['phone'], 'tel')
                    . self::input('license_number', 'Licence number', (string) $d['license_number'])
                    . self::input('license_expiry', 'Licence expiry', (string) $d['license_expiry'], 'date')
                    . '<div class="field check"><label><input type="checkbox" name="active" value="1"' . ($d['active'] ? ' checked' : '') . '> Can be given trips</label></div>'
                    . '</div><p class="form-error" role="alert" hidden></p><div class="form-actions"><button class="btn primary" type="submit">Save</button></div></form></details>';
            }
            $rows[] = [
                '<b>' . Ui::e($d['name']) . '</b><br><span class="muted small">' . Ui::e($d['email']) . '</span>',
                Ui::e((string) ($d['phone'] ?: '—')),
                '<span class="mono">' . Ui::e((string) ($d['license_number'] ?: '—')) . '</span>',
                $pill,
                number_format((int) $d['open_trips']),
                $d['active'] ? Ui::pill('active', 'Active') : Ui::pill('inactive', 'Inactive'),
                $edit,
            ];
        }
        $body = Ui::card('', Ui::table(['Driver', 'Phone', 'Licence', 'Licence expiry', '>Open trips', 'Status', ''], $rows, 'No drivers yet.'));
        if ($canEdit) {
            $body .= '<details class="card adder"><summary><span class="btn primary">+ Add a driver</span></summary><div style="margin-top:16px"><form class="form" data-endpoint="drivers" novalidate><div class="form-grid">'
                . self::input('name', 'Full name', '') . self::input('email', 'Email (for signing in)', '', 'email') . self::input('phone', 'Phone', '', 'tel')
                . self::input('license_number', 'Licence number', '') . self::input('license_expiry', 'Licence expiry', '', 'date')
                . '</div><p class="form-error" role="alert" hidden></p><div class="form-actions"><button class="btn primary" type="submit">Add driver</button><span class="muted small">They get an email to set their password, and sign in on their phone.</span></div></form></div></details>';
        }
        Layout::page($actor, 'drivers', 'Drivers', $body, 'Fleet & stock');
    }

    // ----- Operations card on an order ---------------------------------------------------------

    public static function card(Actor $actor, array $o): string
    {
        if (in_array($o['status'], ['requested', 'submitted', 'needs_fix', 'cancelled', 'declined', 'expired'], true)) {
            return '';
        }
        $staff = $actor->isStaff();
        $a = Dispatch::assignments((string) $o['id']);
        $parts = [];

        // What is assigned (everyone) and the assign forms (dispatcher, confirmed orders).
        $canAssign = $staff && $actor->can('cf_assign_fleet') && $o['status'] === 'confirmed';
        if ($o['kind'] === 'truck') {
            $truck = $a['trucks'][0] ?? null;
            $trip = $a['trips']['rental'] ?? null;
            $info = '<p style="margin:0">Truck: ' . ($truck ? '<b class="mono">' . Ui::e($truck['plate']) . '</b>' . ($truck['label'] ? ' · ' . Ui::e($truck['label']) : '') : '<span class="muted">not assigned yet</span>')
                . ((int) $o['needs_driver'] ? ' · Driver: ' . ($trip ? '<b>' . Ui::e($trip['driver_name']) . '</b>' : '<span class="muted">not assigned yet</span>') : ' · Self-drive') . '</p>';
            $parts[] = $info;
            if ($canAssign) {
                $trucks = Dispatch::freeTrucks(self::bookedType($o), (string) $o['start_date'], (string) $o['end_date'], (string) $o['id'], true);
                $form = '<form class="form inline-form" data-endpoint="orders/' . esc_attr($o['id']) . '/assign-truck" novalidate>'
                    . self::select('truck_id', 'Truck', array_column(array_map(static fn($t) => ['id' => $t['id'], 'l' => $t['plate'] . ($t['label'] ? ' · ' . $t['label'] : '') . ' · ' . number_format((int) $t['odometer_km']) . ' km'], $trucks), 'l', 'id'), $truck['id'] ?? '');
                if ((int) $o['needs_driver']) {
                    $form .= self::select('driver_user_id', 'Driver', self::driverOptions(Dispatch::freeDrivers((string) $o['start_date'], (string) $o['end_date'], (string) $o['id'], false)), (string) ($trip['driver_user_id'] ?? ''));
                }
                $form .= '<button class="btn primary" type="submit">' . ($truck ? 'Change' : 'Assign') . '</button><p class="form-error" role="alert" hidden></p></form>';
                $parts[] = $trucks === [] && !$truck ? '<p class="small bad-text">No truck of this type is free for these dates.</p>' : $form;
            }
        } else {
            foreach (Yard::lines($o) as $l) {
                if ($l['tracking'] !== 'serial') {
                    continue;
                }
                $serials = array_column($l['units'], 'serial');
                $parts[] = '<p style="margin:0">' . Ui::e($l['description']) . ': ' . ($serials ? '<b class="mono">' . Ui::e(implode(', ', $serials)) . '</b>' : '<span class="muted">units not assigned yet</span>') . '</p>';
                if ($canAssign) {
                    $free = Dispatch::freeUnits((string) $l['item_id'], (string) $o['start_date'], (string) $o['end_date'], (string) $o['id']);
                    $chosen = array_column($l['units'], 'id');
                    $boxes = '';
                    foreach ($free as $u) {
                        $boxes .= '<label class="unit-pick"><input type="checkbox" name="units[]" value="' . esc_attr($u['id']) . '"' . (in_array($u['id'], $chosen, true) ? ' checked' : '') . '> <span class="mono">' . Ui::e($u['serial']) . '</span></label>';
                    }
                    $parts[] = $free === [] ? '<p class="small bad-text">No unit of this item is free for these dates.</p>'
                        : '<form class="form inline-form" data-endpoint="orders/' . esc_attr($o['id']) . '/assign-units" novalidate><input type="hidden" name="line_id" value="' . esc_attr($l['id']) . '">'
                        . '<div class="field"><span class="label">Choose ' . (int) $l['qty'] . '</span><div class="unit-list">' . $boxes . '</div></div><button class="btn primary" type="submit">Assign units</button><p class="form-error" role="alert" hidden></p></form>';
                }
            }
            if ($o['fulfilment'] === 'delivery') {
                foreach (['delivery' => $o['start_date'], 'pickup' => $o['end_date']] as $kind => $date) {
                    $trip = $a['trips'][$kind] ?? null;
                    $parts[] = '<p style="margin:0">' . Ui::e(Dispatch::TRIP_KINDS[$kind]) . ' on ' . Ui::e(Ui::date($date)) . ': '
                        . ($trip ? '<b class="mono">' . Ui::e((string) $trip['plate']) . '</b> · <b>' . Ui::e($trip['driver_name']) . '</b> · ' . Ui::e(Trips::LABELS[$trip['status']] ?? $trip['status']) : '<span class="muted">not assigned yet</span>') . '</p>';
                    $open = $kind === 'delivery' ? $o['status'] === 'confirmed' : in_array($o['status'], ['confirmed', 'dispatched', 'on_site'], true);
                    if ($staff && $actor->can('cf_assign_fleet') && $open && ($trip === null || $trip['status'] === 'planned')) {
                        $trucks = Dispatch::freeTrucks(null, (string) $date, (string) $date, (string) $o['id'], false);
                        $parts[] = '<form class="form inline-form" data-endpoint="orders/' . esc_attr($o['id']) . '/assign-run" novalidate><input type="hidden" name="kind" value="' . $kind . '">'
                            . self::select('truck_id', 'Truck', array_column(array_map(static fn($t) => ['id' => $t['id'], 'l' => $t['plate'] . ' · ' . $t['type_name']], $trucks), 'l', 'id'), (string) ($trip['truck_id'] ?? ''), $kind)
                            . self::select('driver_user_id', 'Driver', self::driverOptions(Dispatch::freeDrivers((string) $date, (string) $date, (string) $o['id'], true)), (string) ($trip['driver_user_id'] ?? ''), $kind)
                            . '<button class="btn primary" type="submit">' . ($trip ? 'Change' : 'Assign') . '</button><p class="form-error" role="alert" hidden></p></form>';
                    }
                }
            }
        }

        // Yard actions.
        $yard = '';
        if ($actor->can('cf_count_stock')) {
            $canLoad = $o['status'] === 'confirmed' && ($o['kind'] === 'equipment' ? empty($o['loaded_at']) : !(int) $o['needs_driver']);
            if ($canLoad) {
                $yard .= '<a class="btn" href="' . esc_url(add_query_arg('id', $o['id'], Router::url('loadings'))) . '">' . ($o['kind'] === 'truck' ? 'Release at the yard' : 'Load at the yard') . '</a>';
            }
            if (in_array($o['status'], ['dispatched', 'on_site', 'returning'], true)) {
                $yard .= '<a class="btn" href="' . esc_url(add_query_arg('id', $o['id'], Router::url('returns'))) . '">Return inspection</a>';
            }
        }
        if ($yard !== '') {
            $parts[] = '<div class="row-actions">' . $yard . '</div>';
        }

        // Return summary.
        if ($o['returned_at']) {
            $sum = [];
            if ($o['actual_km'] !== null) {
                $sum[] = 'Odometer ' . number_format((int) $o['odo_out']) . ' → ' . number_format((int) $o['odo_in']) . ' km = <b>' . number_format((int) $o['actual_km']) . ' km</b> (quoted ' . Ui::e(number_format((float) $o['distance_km'], 1)) . ' km)';
            }
            if ($o['fuel_short_liters'] !== null && (float) $o['fuel_short_liters'] > 0) {
                $sum[] = 'Fuel short ' . Ui::e(rtrim(rtrim((string) $o['fuel_short_liters'], '0'), '.')) . ' L';
            }
            if ($o['damage_note']) {
                $sum[] = 'Issues: ' . Ui::e($o['damage_note']);
            }
            $parts[] = '<div class="callout ' . ($o['damage_note'] ? '' : 'info') . '"><b>Returned ' . Ui::e(Ui::when($o['returned_at'])) . '.</b> ' . implode(' · ', $sum) . '</div>';
        }

        $parts[] = self::timeline($o);
        if ($staff && $actor->can('cf_dispatch')) {
            $exp = Expenses::list($actor, (string) $o['id']);
            if ($exp !== []) {
                $parts[] = '<h3 class="sub">Trip expenses</h3>' . self::expenseTable($exp);
            }
        }
        return Ui::card('Operations', implode('', array_map(static fn($p) => '<div class="ops-block">' . $p . '</div>', $parts)));
    }

    /** Steps on the road and in the yard, with photos and signatures. */
    public static function timeline(array $o): string
    {
        $events = Trips::timeline((string) $o['id']);
        if ($events === []) {
            return '<p class="muted small" style="margin:0">Nothing has happened on the road yet.</p>';
        }
        $trips = Dispatch::assignments((string) $o['id'])['trips'];
        $items = '';
        foreach ($events as $e) {
            $label = Trips::LABELS[$e['step']] ?? $e['step'];
            $kind = $e['trip_kind'] && $e['trip_kind'] !== 'rental' ? Dispatch::TRIP_KINDS[$e['trip_kind']] . ': ' : '';
            $photos = '';
            foreach ($e['photo_list'] as $i => $_) {
                $url = Pages::fileUrl(['photo' => $e['id'], 'n' => $i]);
                $photos .= '<a href="' . esc_url($url) . '" target="_blank" rel="noopener"><img src="' . esc_url($url) . '" alt="Photo ' . ($i + 1) . ' — ' . esc_attr($label) . '" loading="lazy"></a>';
            }
            $extra = [];
            if ($e['odometer'] !== null) {
                $extra[] = 'odometer ' . number_format((int) $e['odometer']) . ' km';
            }
            if ($e['step'] === 'delivered' && $e['trip_kind'] && isset($trips[$e['trip_kind']]['receiver_name'])) {
                $tr = $trips[$e['trip_kind']];
                $extra[] = 'received by <b>' . Ui::e($tr['receiver_name']) . '</b> (<a href="' . esc_url(Pages::fileUrl(['sig' => $tr['id']])) . '" target="_blank" rel="noopener">signature</a>)';
            }
            if ($e['step'] === 'released' && $o['handover_name']) {
                $extra[] = 'received by <b>' . Ui::e($o['handover_name']) . '</b>' . ($o['handover_signature'] ? ' (<a href="' . esc_url(Pages::fileUrl(['sig' => $o['id']])) . '" target="_blank" rel="noopener">signature</a>)' : '');
            }
            $items .= '<li><div class="tl-head"><b>' . Ui::e($kind . $label) . '</b><span class="muted small">' . Ui::e(Ui::when($e['created_at'])) . ' · ' . Ui::e($e['user_name']) . '</span></div>'
                . ($extra ? '<div class="small">' . implode(' · ', $extra) . '</div>' : '')
                . ($e['note'] ? '<div class="small muted">' . Ui::e($e['note']) . '</div>' : '')
                . ($photos ? '<div class="thumbs">' . $photos . '</div>' : '') . '</li>';
        }
        return '<h3 class="sub">Timeline</h3><ol class="timeline">' . $items . '</ol>';
    }

    /** @param list<array<string,mixed>> $rows */
    public static function expenseTable(array $rows, bool $showDriver = true): string
    {
        $out = [];
        foreach ($rows as $e) {
            $out[] = [
                Ui::e(Ui::date($e['spent_on'])),
                Ui::e(Expenses::KINDS[$e['kind']] ?? $e['kind']) . ($e['liters'] ? ' · ' . Ui::e(rtrim(rtrim((string) $e['liters'], '0'), '.')) . ' L' : ''),
                $e['plate'] ? '<span class="mono">' . Ui::e($e['plate']) . '</span>' : '—',
                $showDriver ? Ui::e($e['user_name']) : Ui::e((string) ($e['ref'] ?? '—')),
                '<a href="' . esc_url(Pages::fileUrl(['expense' => $e['id']])) . '" target="_blank" rel="noopener">Receipt</a>',
                Ui::e(Ui::money($e['amount'])),
            ];
        }
        return Ui::table(['Date', 'For', 'Truck', $showDriver ? 'By' : 'Order', 'Receipt', '>Amount'], $out);
    }

    // ----- helpers -----------------------------------------------------------------------------

    private static function bookedType(array $o): ?string
    {
        $t = Db::value(Db::prepare('SELECT LOWER(HEX(truck_type_id)) FROM ' . Db::t('order_lines') . ' WHERE order_id = UNHEX(%s) AND line_type = %s LIMIT 1', $o['id'], 'truck'));
        return $t === null ? null : (string) $t;
    }

    /** @param list<array<string,mixed>> $drivers @return array<string,string> id => label */
    private static function driverOptions(array $drivers): array
    {
        $out = [];
        foreach ($drivers as $d) {
            $out[(string) $d['user_id']] = $d['name'];
        }
        return $out;
    }

    /** @param array<string,string> $options value => label */
    private static function select(string $name, string $label, array $options, string $selected, string $suffix = ''): string
    {
        $id = 'ops-' . $name . ($suffix !== '' ? '-' . $suffix : '');
        $opts = '<option value="">Choose…</option>';
        foreach ($options as $v => $l) {
            $opts .= '<option value="' . esc_attr((string) $v) . '"' . ((string) $v === $selected ? ' selected' : '') . '>' . Ui::e($l) . '</option>';
        }
        return '<div class="field"><label for="' . $id . '">' . Ui::e($label) . '</label><select id="' . $id . '" name="' . esc_attr($name) . '">' . $opts . '</select></div>';
    }

    private static function input(string $name, string $label, string $value, string $type = 'text'): string
    {
        static $n = 0;
        $id = 'in-' . $name . '-' . (++$n);
        return '<div class="field"><label for="' . $id . '">' . Ui::e($label) . '</label><input id="' . $id . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" value="' . esc_attr($value) . '"></div>';
    }
}
