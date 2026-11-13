<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Management reports for a date range (Philippine dates). Revenue counts issued final bills by
 * the day they were issued. Every report is a list of rows with fixed columns, so the screen and
 * the CSV export show exactly the same numbers.
 */
final class Reports
{
    public const LIST = [
        'revenue_month' => 'Revenue by month',
        'revenue_customer' => 'Revenue by customer',
        'trucks' => 'Trucks: km, utilisation, fuel and profit',
        'items' => 'Equipment revenue by item',
        'expenses' => 'Fuel and expenses',
        'overdue' => 'Overdue returns',
    ];

    /**
     * @return array{columns:array<string,string>,rows:list<array<string,mixed>>,note:string}
     */
    public static function run(Actor $actor, string $name, string $from, string $to): array
    {
        $actor->require('cf_view_reports');
        if (!isset(self::LIST[$name])) {
            throw new DomainError('not_found', 'Unknown report.');
        }
        foreach ([$from, $to] as $d) {
            $x = \DateTimeImmutable::createFromFormat('!Y-m-d', $d);
            if (!$x || $x->format('Y-m-d') !== $d) {
                throw new DomainError('invalid_input', 'Choose valid dates.');
            }
        }
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }
        // Issued bills in the range, using Philippine dates on UTC timestamps.
        [$utcFrom, $utcTo] = self::utcRange($from, $to);
        return match ($name) {
            'revenue_month' => self::revenueMonth($utcFrom, $utcTo),
            'revenue_customer' => self::revenueCustomer($utcFrom, $utcTo),
            'trucks' => self::trucks($from, $to, $utcFrom, $utcTo),
            'items' => self::items($utcFrom, $utcTo),
            'expenses' => self::expenses($from, $to),
            default => self::overdue(),
        };
    }

    /** Send a report as CSV and stop. */
    public static function csv(array $report, string $filename): void
    {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9_.-]/i', '', $filename) . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // Excel reads UTF-8
        fputcsv($out, array_values($report['columns']));
        foreach ($report['rows'] as $r) {
            $line = [];
            foreach (array_keys($report['columns']) as $k) {
                $v = $r[$k] ?? '';
                // Keep spreadsheet formulas from running.
                $line[] = is_string($v) && preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
            }
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }

    // ----- reports -----------------------------------------------------------------------------

    private static function revenueMonth(string $f, string $t): array
    {
        $rows = Db::rows(Db::prepare(
            "SELECT DATE_FORMAT(CONVERT_TZ(i.issued_at, '+00:00', '+08:00'), '%%Y-%%m') AS month, o.kind, COUNT(*) AS bills, SUM(i.total) AS total, SUM(i.total - i.booked_total) AS adjustments
             FROM " . Db::t('invoices') . ' i JOIN ' . Db::t('orders') . " o ON o.id = i.order_id
             WHERE i.status <> 'draft' AND i.issued_at >= %s AND i.issued_at < %s GROUP BY month, o.kind ORDER BY month, o.kind", $f, $t
        ));
        foreach ($rows as &$r) {
            $r['kind'] = $r['kind'] === 'truck' ? 'Trucks' : 'Equipment';
            $r['total'] = round((float) $r['total'], 2);
            $r['adjustments'] = round((float) $r['adjustments'], 2);
        }
        return ['columns' => ['month' => 'Month', 'kind' => 'Type', 'bills' => 'Bills', 'adjustments' => 'Change from booked price', 'total' => 'Revenue'], 'rows' => $rows,
            'note' => 'Final bills issued in the period. "Change from booked price" is km, late return, fuel, damage and other adjustments.'];
    }

    private static function revenueCustomer(string $f, string $t): array
    {
        $rows = Db::rows(Db::prepare(
            "SELECT c.name AS customer, COUNT(*) AS bills, SUM(i.total) AS total,
                SUM(GREATEST(0, i.balance_due - COALESCE((SELECT SUM(p.amount) FROM " . Db::t('payments') . " p WHERE p.order_id = i.order_id AND p.kind = 'balance' AND p.status = 'received'), 0))) AS unpaid
             FROM " . Db::t('invoices') . ' i JOIN ' . Db::t('companies') . " c ON c.id = i.company_id
             WHERE i.status <> 'draft' AND i.issued_at >= %s AND i.issued_at < %s GROUP BY c.id, c.name ORDER BY total DESC", $f, $t
        ));
        foreach ($rows as &$r) {
            $r['total'] = round((float) $r['total'], 2);
            $r['unpaid'] = round((float) $r['unpaid'], 2);
        }
        return ['columns' => ['customer' => 'Customer', 'bills' => 'Bills', 'total' => 'Revenue', 'unpaid' => 'Still unpaid'], 'rows' => $rows, 'note' => 'Final bills issued in the period, by customer.'];
    }

    private static function trucks(string $from, string $to, string $f, string $t): array
    {
        $days = (int) (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days + 1;
        $live = "'confirmed','dispatched','on_site','returning','returned','closed'";
        $rows = [];
        foreach (Db::rows('SELECT ' . Db::hex('t.id', 'id') . ', t.plate, y.name AS type_name, t.status FROM ' . Db::t('trucks') . ' t JOIN ' . Db::t('truck_types') . " y ON y.id = t.type_id WHERE t.status <> 'retired' ORDER BY t.plate") as $tr) {
            $busy = 0;
            foreach (Db::rows(Db::prepare('SELECT a.start_date, a.end_date FROM ' . Db::t('order_assets') . ' a JOIN ' . Db::t('orders') . " o ON o.id = a.order_id
                WHERE a.asset_type = 'truck' AND a.asset_id = UNHEX(%s) AND o.status IN ({$live}) AND a.start_date <= %s AND a.end_date >= %s", $tr['id'], $to, $from)) as $a) {
                $busy += (int) (new \DateTimeImmutable(max($a['start_date'], $from)))->diff(new \DateTimeImmutable(min($a['end_date'], $to)))->days + 1;
            }
            $revenue = (float) Db::value(Db::prepare('SELECT COALESCE(SUM(i.total), 0) FROM ' . Db::t('invoices') . ' i JOIN ' . Db::t('order_assets') . " a ON a.order_id = i.order_id AND a.asset_type = 'truck'
                WHERE a.asset_id = UNHEX(%s) AND i.status <> 'draft' AND i.issued_at >= %s AND i.issued_at < %s", $tr['id'], $f, $t));
            $rentalKm = (int) Db::value(Db::prepare('SELECT COALESCE(SUM(o.actual_km), 0) FROM ' . Db::t('orders') . ' o JOIN ' . Db::t('order_assets') . " a ON a.order_id = o.id AND a.asset_type = 'truck'
                WHERE a.asset_id = UNHEX(%s) AND o.returned_at >= %s AND o.returned_at < %s", $tr['id'], $f, $t));
            $runKm = (int) Db::value(Db::prepare('SELECT COALESCE(SUM(odo_back - odo_out), 0) FROM ' . Db::t('trips') . " WHERE truck_id = UNHEX(%s) AND kind <> 'rental' AND status = 'back' AND odo_back >= odo_out AND updated_at >= %s AND updated_at < %s", $tr['id'], $f, $t));
            $fuel = (float) Db::value(Db::prepare('SELECT COALESCE(SUM(amount), 0) FROM ' . Db::t('expenses') . " WHERE truck_id = UNHEX(%s) AND kind = 'fuel' AND spent_on BETWEEN %s AND %s", $tr['id'], $from, $to));
            $liters = (float) Db::value(Db::prepare('SELECT COALESCE(SUM(liters), 0) FROM ' . Db::t('expenses') . " WHERE truck_id = UNHEX(%s) AND kind = 'fuel' AND spent_on BETWEEN %s AND %s", $tr['id'], $from, $to));
            $other = (float) Db::value(Db::prepare('SELECT COALESCE(SUM(amount), 0) FROM ' . Db::t('expenses') . " WHERE truck_id = UNHEX(%s) AND kind <> 'fuel' AND spent_on BETWEEN %s AND %s", $tr['id'], $from, $to));
            $maint = (float) Db::value(Db::prepare('SELECT COALESCE(SUM(cost), 0) FROM ' . Db::t('maintenance') . ' WHERE truck_id = UNHEX(%s) AND done_on BETWEEN %s AND %s', $tr['id'], $from, $to));
            $km = $rentalKm + $runKm;
            $rows[] = [
                'truck' => $tr['plate'], 'type' => $tr['type_name'], 'days_out' => $busy, 'utilisation' => round($busy / max(1, $days) * 100) . '%',
                'km' => $km, 'revenue' => round($revenue, 2), 'fuel' => round($fuel, 2), 'liters' => round($liters, 2),
                'km_per_liter' => $liters > 0 && $km > 0 ? round($km / $liters, 1) : '', 'other' => round($other, 2), 'maintenance' => round($maint, 2),
                'profit' => round($revenue - $fuel - $other - $maint, 2),
            ];
        }
        return ['columns' => ['truck' => 'Truck', 'type' => 'Type', 'days_out' => 'Days out', 'utilisation' => 'Utilisation', 'km' => 'Km', 'revenue' => 'Revenue',
            'fuel' => 'Fuel', 'liters' => 'Litres', 'km_per_liter' => 'Km per litre', 'other' => 'Other trip costs', 'maintenance' => 'Maintenance', 'profit' => 'Revenue less costs'],
            'rows' => $rows, 'note' => "Days out are days on a confirmed rental within the {$days}-day period. Revenue is final bills for truck orders. Km includes rentals and delivery runs. Driver wages are not included."];
    }

    private static function items(string $f, string $t): array
    {
        $rows = Db::rows(Db::prepare(
            "SELECT i.name AS item, i.category, SUM(l.qty) AS pieces, COUNT(DISTINCT o.id) AS orders, SUM(l.amount) AS booked
             FROM " . Db::t('order_lines') . ' l JOIN ' . Db::t('equipment_items') . ' i ON i.id = l.item_id JOIN ' . Db::t('orders') . ' o ON o.id = l.order_id JOIN ' . Db::t('invoices') . " v ON v.order_id = o.id
             WHERE l.line_type = 'item' AND v.status <> 'draft' AND v.issued_at >= %s AND v.issued_at < %s GROUP BY i.id, i.name, i.category ORDER BY booked DESC", $f, $t
        ));
        foreach ($rows as &$r) {
            $r['category'] = Catalog::CATEGORIES[$r['category']] ?? $r['category'];
            $r['booked'] = round((float) $r['booked'], 2);
        }
        return ['columns' => ['item' => 'Item', 'category' => 'Category', 'orders' => 'Orders', 'pieces' => 'Pieces rented', 'booked' => 'Rental revenue'], 'rows' => $rows,
            'note' => 'Booked rental of each item on orders billed in the period (damage, late and delivery charges are in the revenue reports).'];
    }

    private static function expenses(string $from, string $to): array
    {
        $rows = Db::rows(Db::prepare('SELECT e.spent_on, e.kind, e.amount, e.liters, e.odometer, t.plate, o.ref, e.user_id, e.note FROM ' . Db::t('expenses') . ' e
            LEFT JOIN ' . Db::t('trucks') . ' t ON t.id = e.truck_id LEFT JOIN ' . Db::t('orders') . ' o ON o.id = e.order_id
            WHERE e.spent_on BETWEEN %s AND %s ORDER BY e.spent_on, e.created_at', $from, $to));
        foreach ($rows as &$r) {
            $u = get_userdata((int) $r['user_id']);
            $r['driver'] = $u ? $u->display_name : '';
            $r['kind'] = Expenses::KINDS[$r['kind']] ?? $r['kind'];
            $r['amount'] = round((float) $r['amount'], 2);
        }
        return ['columns' => ['spent_on' => 'Date', 'kind' => 'For', 'amount' => 'Amount', 'liters' => 'Litres', 'odometer' => 'Odometer', 'plate' => 'Truck', 'ref' => 'Order', 'driver' => 'By', 'note' => 'Note'],
            'rows' => $rows, 'note' => 'Expenses logged by drivers and the office.'];
    }

    private static function overdue(): array
    {
        $today = Db::todayManila()->format('Y-m-d');
        $rows = Db::rows(Db::prepare('SELECT o.ref, c.name AS customer, o.kind, o.end_date, o.status, o.site_address FROM ' . Db::t('orders') . ' o JOIN ' . Db::t('companies') . " c ON c.id = o.company_id
            WHERE o.status IN ('dispatched','on_site','returning') AND o.end_date < %s ORDER BY o.end_date", $today));
        foreach ($rows as &$r) {
            $r['days_late'] = (int) (new \DateTimeImmutable($r['end_date']))->diff(new \DateTimeImmutable($today))->days;
            $r['status'] = Orders::STATUS_LABELS[$r['status']] ?? $r['status'];
            $r['kind'] = $r['kind'] === 'truck' ? 'Truck' : 'Equipment';
        }
        return ['columns' => ['ref' => 'Order', 'customer' => 'Customer', 'kind' => 'Type', 'end_date' => 'Due back', 'days_late' => 'Days late', 'status' => 'Status', 'site_address' => 'Site'],
            'rows' => $rows, 'note' => 'Orders still out after their end date, as of today.'];
    }

    /** @return array{0:string,1:string} UTC bounds for Philippine dates [from, to] */
    private static function utcRange(string $from, string $to): array
    {
        $tz = new \DateTimeZone('Asia/Manila');
        $utc = new \DateTimeZone('UTC');
        return [
            (new \DateTimeImmutable($from . ' 00:00:00', $tz))->setTimezone($utc)->format('Y-m-d H:i:s'),
            (new \DateTimeImmutable($to . ' 00:00:00', $tz))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s'),
        ];
    }
}
