<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal\Screens;

use Canpas\Fleet\Domain\Catalog;
use Canpas\Fleet\Domain\Companies;
use Canpas\Fleet\Portal\Layout;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Portal\Ui;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Tenancy\Actor;

/** Staff dashboard. Shows only real figures; order and revenue tiles join as those modules go live. */
final class Dashboard
{
    public static function render(Actor $actor): void
    {
        $companies = Companies::counts();
        $fleet = Catalog::fleetCounts();
        $trucks = Catalog::trucks();
        $truckTotal = count(array_filter($trucks, static fn($t) => $t['status'] !== 'retired'));
        $truckAvail = count(array_filter($trucks, static fn($t) => $t['status'] === 'available'));
        $orderCounts = \Canpas\Fleet\Domain\Orders::counts();

        $kpis = '<div class="grid k4">'
            . Ui::kpi('Waiting for approval', (string) $companies['pending'], $companies['pending'] > 0 ? 'Company sign-ups to review' : 'Nothing to review', $companies['pending'] > 0 ? 'warn' : '')
            . Ui::kpi('Active customers', (string) $companies['approved'], $companies['suspended'] > 0 ? $companies['suspended'] . ' suspended' : 'Approved companies', $companies['suspended'] > 0 ? 'bad' : '')
            . Ui::kpi('Trucks available', $truckAvail . ' of ' . $truckTotal, $fleet['repair'] > 0 ? $fleet['repair'] . ($fleet['repair'] === 1 ? ' truck or unit' : ' trucks or units') . ' in repair' : 'None in repair', $fleet['repair'] > 0 ? 'warn' : '')
            . Ui::kpi('Waiting for your check', (string) ($orderCounts['submitted'] ?? 0), ($orderCounts['requested'] ?? 0) . ' on hold with customers', ($orderCounts['submitted'] ?? 0) > 0 ? 'warn' : '')
            . '</div>';

        // Needs attention: pending sign-ups and documents expiring within 30 days.
        $alerts = [];
        if ($actor->can('cf_manage_companies')) {
            foreach (Companies::list($actor, 'pending') as $c) {
                $alerts[] = ['warn', 'Company waiting for approval: ' . $c['name'], 'Requested ' . Ui::when($c['created_at']), Router::url($actor->can('cf_approve_companies') ? 'approvals' : 'companies')];
            }
        }
        if ($actor->can('cf_manage_orders')) {
            foreach (\Canpas\Fleet\Domain\Orders::list($actor, 'submitted', 20) as $o) {
                $alerts[] = ['warn', 'Order ready for checking: ' . $o['ref'], $o['company_name'] . ' · starts ' . Ui::date($o['start_date']), add_query_arg('id', $o['id'], Router::url('orders'))];
            }
        }
        $docs = \Canpas\Fleet\Domain\Documents::counts()['pending'];
        if ($docs > 0 && $actor->can('cf_view_documents')) {
            $alerts[] = ['', $docs . ' document' . ($docs === 1 ? '' : 's') . ' to check', 'IDs and licences uploaded by customers', Router::url('documents')];
        }
        $pays = \Canpas\Fleet\Domain\Payments::counts()['pending'];
        if ($pays > 0 && $actor->can('cf_confirm_payments')) {
            $alerts[] = ['', $pays . ' payment' . ($pays === 1 ? '' : 's') . ' to check', 'Proofs of payment waiting for the bank check', Router::url('payments')];
        }
        $soon = Db::todayManila()->modify('+30 days')->format('Y-m-d');
        foreach ($trucks as $t) {
            if ($t['status'] === 'retired') {
                continue;
            }
            foreach (['registration_expiry' => 'Registration', 'insurance_expiry' => 'Insurance'] as $col => $what) {
                if ($t[$col] && $t[$col] <= $soon) {
                    $alerts[] = [$t[$col] < Db::todayManila()->format('Y-m-d') ? 'bad' : 'warn', "{$what} " . ($t[$col] < Db::todayManila()->format('Y-m-d') ? 'expired' : 'due') . ': ' . $t['plate'], Ui::date($t[$col]) . ' · ' . $t['type_name'], Router::url('trucks')];
                }
            }
        }
        foreach (Catalog::units() as $u) {
            if ($u['status'] !== 'retired' && $u['inspection_expiry'] && $u['inspection_expiry'] <= $soon) {
                $alerts[] = ['warn', 'Inspection due: ' . $u['serial'], Ui::date($u['inspection_expiry']) . ' · ' . $u['item_name'], add_query_arg('tab', 'units', Router::url('heavy-equipment'))];
            }
        }
        $alertHtml = '';
        foreach (array_slice($alerts, 0, 8) as [$tone, $title, $meta, $href]) {
            $alertHtml .= '<a class="alert-row ' . $tone . '" href="' . esc_url($href) . '"><span class="dot"></span><span><b>' . Ui::e($title) . '</b><br><span class="muted small">' . Ui::e($meta) . '</span></span></a>';
        }
        if ($alertHtml === '') {
            $alertHtml = '<p class="empty">Nothing needs attention.</p>';
        }

        $fleetRows = [];
        foreach (array_slice(array_values(array_filter($trucks, static fn($t) => $t['status'] !== 'retired')), 0, 8) as $t) {
            $fleetRows[] = ['<span class="mono">' . Ui::e($t['plate']) . '</span>', Ui::e($t['type_name']), Ui::pill($t['status'])];
        }

        $body = $kpis . '<div class="split">'
            . Ui::card('Needs attention', $alertHtml)
            . Ui::card('Fleet status', Ui::table(['Plate', 'Type', 'Status'], $fleetRows, 'No trucks added yet.'), '<a href="' . esc_url(Router::url('trucks')) . '" class="small">View all</a>')
            . '</div>'
            . '<p class="muted small" style="margin-top:16px">Deliveries, returns, utilisation and revenue join this dashboard as operations (milestone 4) and billing (milestone 5) go live.</p>';

        $first = explode(' ', trim(wp_get_current_user()->display_name))[0] ?? '';
        $actions = $actor->can('cf_manage_fleet') ? '<a class="btn" href="' . esc_url(Router::url('trucks')) . '">Trucks</a>' : '';
        Layout::page($actor, 'dashboard', 'Good ' . self::partOfDay() . ', ' . $first, $body, Db::todayManila()->format('l, j F Y'), $actions);
    }

    private static function partOfDay(): string
    {
        $h = (int) (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Manila')))->format('G');
        return $h < 12 ? 'morning' : ($h < 18 ? 'afternoon' : 'evening');
    }
}
