<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal\Screens;

use Canpas\Fleet\Domain\Maintenance;
use Canpas\Fleet\Domain\Reports;
use Canpas\Fleet\Portal\Layout;
use Canpas\Fleet\Portal\Pages;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Portal\Ui;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Tenancy\Actor;

/** Maintenance and Reports. */
final class ManageScreen
{
    public static function maintenance(Actor $actor): void
    {
        $alerts = Maintenance::alerts();
        $alertRows = [];
        foreach ($alerts as $a) {
            $alertRows[] = ['<span class="mono"><b>' . Ui::e($a['asset']) . '</b></span>', '<span class="pill ' . $a['tone'] . '">' . Ui::e($a['what']) . '</span>', Ui::e($a['detail'])];
        }
        $trucks = Db::rows("SELECT " . Db::hex('t.id', 'id') . ", t.plate, t.odometer_km, t.next_service_km, t.next_service_date, t.registration_expiry, t.insurance_expiry, t.status, y.name AS type_name
            FROM " . Db::t('trucks') . ' t JOIN ' . Db::t('truck_types') . " y ON y.id = t.type_id WHERE t.status <> 'retired' ORDER BY t.plate");
        $units = Db::rows("SELECT " . Db::hex('u.id', 'id') . ", u.serial, u.hour_meter, u.inspection_expiry, u.status, i.name FROM " . Db::t('equipment_units') . ' u JOIN ' . Db::t('equipment_items') . " i ON i.id = u.item_id WHERE u.status <> 'retired' ORDER BY u.serial");
        $tRows = [];
        foreach ($trucks as $t) {
            $left = $t['next_service_km'] !== null ? (int) $t['next_service_km'] - (int) $t['odometer_km'] : null;
            $tRows[] = [
                '<span class="mono"><b>' . Ui::e($t['plate']) . '</b></span><br><span class="muted small">' . Ui::e($t['type_name']) . '</span>',
                Ui::e(number_format((int) $t['odometer_km'])),
                $t['next_service_km'] !== null ? Ui::e(number_format((int) $t['next_service_km'])) . '<br><span class="small ' . ($left < 0 ? 'bad-text' : 'muted') . '">' . ($left < 0 ? number_format(-$left) . ' km overdue' : number_format($left) . ' km to go') . '</span>' : '<span class="muted">not set</span>',
                Ui::e(Ui::date($t['registration_expiry'])),
                Ui::e(Ui::date($t['insurance_expiry'])),
                Ui::pill($t['status']),
            ];
        }
        $uRows = [];
        foreach ($units as $u) {
            $uRows[] = ['<span class="mono"><b>' . Ui::e($u['serial']) . '</b></span><br><span class="muted small">' . Ui::e($u['name']) . '</span>', Ui::e(number_format((float) $u['hour_meter'], 1)), Ui::e(Ui::date($u['inspection_expiry'])), Ui::pill($u['status'])];
        }
        $recRows = [];
        foreach (Maintenance::records($actor, 50) as $r) {
            $recRows[] = [
                Ui::e(Ui::date($r['done_on'])),
                '<span class="mono">' . Ui::e((string) $r['asset']) . '</span>',
                Ui::e(Maintenance::KINDS[$r['kind']] ?? $r['kind']) . ($r['vendor'] ? '<br><span class="muted small">' . Ui::e($r['vendor']) . '</span>' : ''),
                $r['odometer'] !== null ? Ui::e(number_format((int) $r['odometer'])) : '—',
                Ui::e((string) ($r['note'] ?? '')) . ($r['receipt_file'] ? ' <a href="' . esc_url(Pages::fileUrl(['maint' => $r['id']])) . '" target="_blank" rel="noopener">Receipt</a>' : ''),
                Ui::e(Ui::money($r['cost'])),
            ];
        }
        $form = '';
        if ($actor->can('cf_manage_fleet')) {
            $assets = '<optgroup label="Trucks">';
            foreach ($trucks as $t) {
                $assets .= '<option value="truck:' . esc_attr($t['id']) . '">' . Ui::e($t['plate']) . '</option>';
            }
            $assets .= '</optgroup><optgroup label="Heavy units">';
            foreach ($units as $u) {
                $assets .= '<option value="unit:' . esc_attr($u['id']) . '">' . Ui::e($u['serial'] . ' · ' . $u['name']) . '</option>';
            }
            $assets .= '</optgroup>';
            $kinds = '';
            foreach (Maintenance::KINDS as $k => $l) {
                $kinds .= '<option value="' . $k . '">' . Ui::e($l) . '</option>';
            }
            $form = '<details class="card adder"><summary><span class="btn primary">+ Log maintenance</span></summary><div style="margin-top:16px"><form class="form" data-endpoint="maintenance" novalidate><div class="form-grid">'
                . '<div class="field"><label for="mt-asset">Truck or unit</label><select id="mt-asset" name="asset">' . $assets . '</select></div>'
                . '<div class="field"><label for="mt-kind">What was done</label><select id="mt-kind" name="kind">' . $kinds . '</select></div>'
                . '<div class="field"><label for="mt-date">Date</label><input id="mt-date" name="done_on" type="date" value="' . esc_attr(Db::todayManila()->format('Y-m-d')) . '" required></div>'
                . '<div class="field"><label for="mt-odo">Odometer <span class="opt">(trucks; needed for a service)</span></label><input id="mt-odo" name="odometer" inputmode="numeric" class="num"></div>'
                . '<div class="field"><label for="mt-cost">Cost (₱)</label><input id="mt-cost" name="cost" inputmode="decimal" class="num"></div>'
                . '<div class="field"><label for="mt-vendor">Shop or supplier <span class="opt">(optional)</span></label><input id="mt-vendor" name="vendor" maxlength="120"></div>'
                . '<div class="field"><label for="mt-nkm">Next service at (km) <span class="opt">(blank = odometer + interval)</span></label><input id="mt-nkm" name="next_due_km" inputmode="numeric" class="num"></div>'
                . '<div class="field"><label for="mt-ndate">Next due / new expiry date <span class="opt">(registration, insurance, inspection)</span></label><input id="mt-ndate" name="next_due_date" type="date"></div>'
                . '<div class="field"><label for="mt-receipt">Receipt <span class="opt">(optional)</span></label><input id="mt-receipt" name="receipt" type="file" accept="image/*,application/pdf"></div>'
                . '<div class="field wide"><label for="mt-note">Note <span class="opt">(optional)</span></label><input id="mt-note" name="note" maxlength="500"></div>'
                . '<div class="field check"><label><input type="checkbox" name="back_in_service" value="1"> Back in service (if it was in repair)</label></div>'
                . '</div><p class="form-error" role="alert" hidden></p><div class="form-actions"><button class="btn primary" type="submit">Save</button></div></form></div></details>';
        }
        $body = ($alerts ? Ui::card('Needs attention', Ui::table(['Truck / unit', 'What', 'Detail'], $alertRows)) : Ui::card('', '<p class="empty">Nothing due. All registrations, insurance, services and inspections are in order.</p>'))
            . $form
            . Ui::card('Trucks', Ui::table(['Truck', '>Odometer', '>Next service', 'Registration', 'Insurance', 'Status'], $tRows, 'No trucks yet.'))
            . ($uRows ? Ui::card('Heavy units', Ui::table(['Unit', '>Hour meter', 'Inspection', 'Status'], $uRows)) : '')
            . Ui::card('Recent records', Ui::table(['Date', 'Truck / unit', 'What', '>Odometer', 'Note', '>Cost'], $recRows, 'No maintenance logged yet.'));
        Layout::page($actor, 'maintenance', 'Maintenance', $body, 'Fleet & stock');
    }

    public static function reports(Actor $actor): void
    {
        $name = sanitize_key((string) ($_GET['report'] ?? 'revenue_month'));
        $name = isset(Reports::LIST[$name]) ? $name : 'revenue_month';
        $from = (string) ($_GET['from'] ?? Db::todayManila()->modify('first day of this month')->format('Y-m-d'));
        $to = (string) ($_GET['to'] ?? Db::todayManila()->format('Y-m-d'));
        try {
            $report = Reports::run($actor, $name, $from, $to);
        } catch (DomainError $e) {
            $from = Db::todayManila()->modify('first day of this month')->format('Y-m-d');
            $to = Db::todayManila()->format('Y-m-d');
            $report = Reports::run($actor, $name, $from, $to);
        }
        if (!empty($_GET['export'])) {
            Reports::csv($report, "canpas-{$name}-{$from}-to-{$to}.csv");
        }
        $tabs = Ui::tabs(Reports::LIST, $name, 'report');
        // Keep the dates when switching tabs.
        $tabs = preg_replace_callback('/href="([^"]+)"/', static fn($m) => 'href="' . esc_url(add_query_arg(['from' => $from, 'to' => $to], html_entity_decode($m[1]))) . '"', $tabs) ?? $tabs;
        $money = ['total', 'adjustments', 'unpaid', 'revenue', 'fuel', 'other', 'maintenance', 'profit', 'booked', 'amount'];
        $rows = [];
        foreach ($report['rows'] as $r) {
            $cells = [];
            foreach (array_keys($report['columns']) as $k) {
                $v = $r[$k] ?? '';
                $cells[] = in_array($k, $money, true) && $v !== '' ? Ui::e(Ui::money($v)) : Ui::e(is_float($v) ? number_format($v, 2) : (is_int($v) ? number_format($v) : (string) $v));
            }
            $rows[] = $cells;
        }
        $heads = [];
        foreach ($report['columns'] as $k => $label) {
            $heads[] = (in_array($k, $money, true) || in_array($k, ['bills', 'km', 'days_out', 'liters', 'km_per_liter', 'orders', 'pieces', 'days_late', 'odometer'], true) ? '>' : '') . $label;
        }
        $range = $name === 'overdue' ? '' : '<form class="inline-form" method="get" action="' . esc_url(Router::url('reports')) . '"><input type="hidden" name="report" value="' . esc_attr($name) . '">'
            . '<div class="field"><label for="rp-from">From</label><input id="rp-from" type="date" name="from" value="' . esc_attr($from) . '"></div>'
            . '<div class="field"><label for="rp-to">To</label><input id="rp-to" type="date" name="to" value="' . esc_attr($to) . '"></div><button class="btn" type="submit">Show</button></form>';
        $csv = '<a class="btn" href="' . esc_url(add_query_arg(['report' => $name, 'from' => $from, 'to' => $to, 'export' => 1], Router::url('reports'))) . '">Export CSV</a>';
        Layout::page($actor, 'reports', Reports::LIST[$name], $tabs . Ui::card('', $range . Ui::table($heads, $rows, 'No data for this period.') . '<p class="muted small" style="margin-top:12px">' . Ui::e($report['note']) . '</p>'), 'Finance', $csv);
    }
}
