<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Mail;
use Canpas\Fleet\Support\Sequence;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Actor;

/**
 * The final bill, built when an order is returned.
 *
 * It starts from the booked price (rates copied onto the order at booking) and adds what the
 * return showed, always with the rates the customer booked at:
 *   - km: if the odometer differs from the quoted round trip by more than the allowed margin,
 *     the truck trip is re-priced with the actual km;
 *   - late return: extra days at the booked daily rate (trucks) or re-priced period (equipment);
 *   - fuel shortfall × the fuel price in Settings;
 *   - damaged pieces × damage fee, missing pieces × replacement cost;
 *   - tolls and parking logged by the driver, at cost.
 * Staff can add or waive lines while the bill is a draft; each change needs a reason and is audited.
 * Issuing locks the bill and applies the deposit: the customer pays the rest, or Canpas refunds
 * the difference. When both are done the order is closed.
 */
final class Billing
{
    public static string $refPrefix = 'INV';

    public const KINDS = [
        'booked' => 'Booked price',
        'km' => 'Kilometre adjustment',
        'late' => 'Late return',
        'fuel' => 'Fuel shortfall',
        'damage' => 'Damage',
        'missing' => 'Missing items',
        'tolls' => 'Tolls and parking',
        'waiting' => 'Waiting time',
        'surcharge' => 'Night or holiday surcharge',
        'cleaning' => 'Cleaning',
        'other' => 'Other charge',
        'discount' => 'Discount',
    ];

    /** Kinds staff can add by hand. */
    public const MANUAL = ['damage', 'waiting', 'surcharge', 'cleaning', 'tolls', 'other', 'discount'];

    public const STATUS_LABELS = ['draft' => 'Draft', 'issued' => 'Issued — awaiting settlement', 'settled' => 'Settled'];

    /** Build (or rebuild) the draft bill of a returned order. */
    public static function prepare(Actor $actor, string $orderId): string
    {
        $actor->require('cf_settle_deposits');
        $o = Orders::row($orderId);
        if ($o['status'] !== 'returned') {
            throw new DomainError('wrong_status', 'The final bill is made once the order has been returned.');
        }
        $lines = self::systemLines($o);
        return Db::transaction(static function () use ($actor, $o, $lines): string {
            $inv = Db::row(Db::prepare('SELECT ' . Db::hex('id') . ', status FROM ' . Db::t('invoices') . ' WHERE order_id = UNHEX(%s) FOR UPDATE', $o['id']));
            if ($inv !== null && $inv['status'] !== 'draft') {
                throw new DomainError('wrong_status', 'This bill has been issued and can no longer be changed.');
            }
            if ($inv === null) {
                $id = Uuid::v7();
                Db::insert('invoices', [
                    'id' => $id, 'ref' => Sequence::ref(self::$refPrefix), 'order_id' => $o['id'], 'company_id' => $o['company_id'],
                    'status' => 'draft', 'booked_total' => $o['total'], 'created_by' => $actor->userId, 'created_at' => Db::now(),
                ], ['id', 'order_id', 'company_id']);
            } else {
                $id = $inv['id'];
                // Rebuild the system lines; lines staff added by hand stay.
                Db::exec(Db::prepare('DELETE FROM ' . Db::t('invoice_lines') . ' WHERE invoice_id = UNHEX(%s) AND source = %s', $id, 'system'));
            }
            foreach ($lines as $i => $l) {
                self::insertLine($actor, $id, $l + ['source' => 'system', 'sort' => $i]);
            }
            self::recalc($id);
            Audit::log($o['company_id'], $actor->userId, 'invoice.prepare', 'invoice', $id, ['order' => $o['ref']]);
            return $id;
        });
    }

    /**
     * Staff add a line by hand (extra charge or discount). A reason is required.
     *
     * @param array<string,mixed> $in kind, description, qty, unit_price, reason
     */
    public static function addLine(Actor $actor, string $invoiceId, array $in): void
    {
        $actor->require('cf_settle_deposits');
        $inv = self::row($invoiceId);
        self::assertDraft($inv);
        $kind = (string) ($in['kind'] ?? '');
        if (!in_array($kind, self::MANUAL, true)) {
            throw new DomainError('invalid_input', 'Choose the kind of charge.', ['field' => 'kind']);
        }
        $desc = sanitize_text_field(wp_unslash((string) ($in['description'] ?? '')));
        if (mb_strlen($desc) < 3) {
            throw new DomainError('invalid_input', 'Describe the charge.', ['field' => 'description']);
        }
        $qty = (float) str_replace(',', '', (string) ($in['qty'] ?? '1'));
        $price = round((float) str_replace([',', '₱', ' '], '', (string) ($in['unit_price'] ?? '')), 2);
        if ($qty <= 0 || $qty > 100000 || $price <= 0 || $price > 10000000) {
            throw new DomainError('invalid_input', 'Enter a quantity and a price above zero.', ['field' => 'unit_price']);
        }
        $reason = self::reason($in);
        $amount = round($qty * $price, 2) * ($kind === 'discount' ? -1 : 1);
        Db::transaction(static function () use ($actor, $inv, $kind, $desc, $qty, $price, $amount, $reason): void {
            $sort = (int) Db::value(Db::prepare('SELECT COALESCE(MAX(sort), 0) + 1 FROM ' . Db::t('invoice_lines') . ' WHERE invoice_id = UNHEX(%s)', $inv['id']));
            self::insertLine($actor, $inv['id'], ['kind' => $kind, 'description' => $desc, 'qty' => $qty, 'unit_price' => $kind === 'discount' ? -$price : $price, 'amount' => $amount, 'source' => 'manual', 'reason' => $reason, 'sort' => $sort]);
            self::recalc($inv['id']);
            Audit::log($inv['company_id'], $actor->userId, 'invoice.add_line', 'invoice', $inv['id'], ['kind' => $kind, 'amount' => $amount, 'reason' => $reason]);
        });
    }

    /** Staff remove (waive) a line. The booked price cannot be removed. */
    public static function removeLine(Actor $actor, string $lineId, string $reason): void
    {
        $actor->require('cf_settle_deposits');
        $lineId = Uuid::assertHex($lineId, 'line');
        $l = Db::row(Db::prepare('SELECT ' . Db::hex('invoice_id') . ', kind, description, amount FROM ' . Db::t('invoice_lines') . ' WHERE id = UNHEX(%s)', $lineId));
        if ($l === null) {
            throw new DomainError('not_found', 'Line not found.');
        }
        $inv = self::row($l['invoice_id']);
        self::assertDraft($inv);
        if ($l['kind'] === 'booked') {
            throw new DomainError('invalid_input', 'The booked price stays on the bill. Add a discount instead.');
        }
        $reason = self::reason(['reason' => $reason]);
        Db::transaction(static function () use ($actor, $inv, $l, $lineId, $reason): void {
            Db::exec(Db::prepare('DELETE FROM ' . Db::t('invoice_lines') . ' WHERE id = UNHEX(%s)', $lineId));
            self::recalc($inv['id']);
            Audit::log($inv['company_id'], $actor->userId, 'invoice.remove_line', 'invoice', $inv['id'], ['line' => $l['description'], 'amount' => (float) $l['amount'], 'reason' => $reason]);
        });
    }

    /** Issue the bill: lock it, apply the deposit, email the customer. */
    public static function issue(Actor $actor, string $invoiceId): void
    {
        $actor->require('cf_settle_deposits');
        $inv = self::row($invoiceId);
        self::assertDraft($inv);
        $o = Orders::row($inv['order_id']);
        if (Payments::totals((string) $o['id'])['pending'] > 0) {
            throw new DomainError('pending_payment', 'A deposit payment is still waiting for a check. Mark it received or rejected first.');
        }
        Db::transaction(static function () use ($actor, $inv, $o): void {
            self::recalc($inv['id']);
            $total = (float) Db::value(Db::prepare('SELECT total FROM ' . Db::t('invoices') . ' WHERE id = UNHEX(%s) FOR UPDATE', $inv['id']));
            $deposit = Payments::totals((string) $o['id'])['received'];
            $applied = round(min($deposit, max(0.0, $total)), 2);
            Db::update('invoices', [
                'status' => 'issued', 'deposit_received' => number_format($deposit, 2, '.', ''), 'deposit_applied' => number_format($applied, 2, '.', ''),
                'balance_due' => number_format(max(0.0, $total - $applied), 2, '.', ''), 'refund_due' => number_format(max(0.0, $deposit - $applied), 2, '.', ''),
                'issued_by' => $actor->userId, 'issued_at' => Db::now(), 'updated_at' => Db::now(),
            ], ['id' => $inv['id']], ['id']);
            Audit::log($o['company_id'], $actor->userId, 'invoice.issue', 'invoice', $inv['id'], ['total' => $total, 'deposit_applied' => $applied]);
        });
        $inv = self::row($invoiceId);
        $u = get_userdata((int) $o['created_by']);
        if ($u) {
            $lines = ["Your final bill {$inv['ref']} for order {$o['ref']} is ready.", 'Total: ₱' . number_format((float) $inv['total'], 2) . '. Deposit applied: ₱' . number_format((float) $inv['deposit_applied'], 2) . '.'];
            $lines[] = (float) $inv['balance_due'] > 0 ? 'Balance to pay: ₱' . number_format((float) $inv['balance_due'], 2) . '. Pay by bank transfer or GCash and upload the proof on the order.'
                : ((float) $inv['refund_due'] > 0 ? 'Canpas will refund ₱' . number_format((float) $inv['refund_due'], 2) . ' of your deposit.' : 'Nothing more is due.');
            Mail::send($u->user_email, "Final bill {$inv['ref']} for order {$o['ref']}", $lines, ['label' => 'Open your order', 'href' => add_query_arg('id', $o['id'], Router::url('my-orders'))]);
        }
        self::settleIfDone($actor, $o['id']);
    }

    /** Close the order once the balance is paid and any refund made. */
    public static function settleIfDone(Actor $actor, string $orderId): bool
    {
        $inv = self::forOrder($orderId);
        if ($inv === null || $inv['status'] !== 'issued') {
            return false;
        }
        if (self::outstanding(Orders::row($orderId)) > 0.004 || Payments::refundable(Orders::row($orderId)) > 0.004) {
            return false;
        }
        Db::transaction(static function () use ($actor, $inv, $orderId): void {
            Db::update('invoices', ['status' => 'settled', 'settled_at' => Db::now(), 'updated_at' => Db::now()], ['id' => $inv['id']], ['id']);
            Db::update('orders', ['status' => 'closed', 'closed_at' => Db::now(), 'status_changed_by' => $actor->userId, 'status_changed_at' => Db::now(), 'updated_at' => Db::now()], ['id' => $orderId], ['id']);
            Audit::log($inv['company_id'], $actor->userId, 'order.close', 'order', $orderId, ['invoice' => $inv['ref']]);
        });
        return true;
    }

    /** Balance the customer still has to pay on an issued bill (pending proofs not counted). */
    public static function outstanding(array $o): float
    {
        $inv = self::forOrder((string) $o['id']);
        if ($inv === null || $inv['status'] !== 'issued') {
            return 0.0;
        }
        return max(0.0, round((float) $inv['balance_due'] - Payments::totals((string) $o['id'], 'balance')['received'], 2));
    }

    // ----- Reading -----------------------------------------------------------------------------

    /** @return array<string,mixed>|null */
    public static function forOrder(string $orderId): ?array
    {
        $r = Db::row(Db::prepare('SELECT *, ' . Db::hex('id') . ', ' . Db::hex('order_id') . ', ' . Db::hex('company_id') . ' FROM ' . Db::t('invoices') . ' WHERE order_id = UNHEX(%s)', $orderId));
        return $r;
    }

    /** @return list<array<string,mixed>> */
    public static function lines(string $invoiceId): array
    {
        $rows = Db::rows(Db::prepare('SELECT *, ' . Db::hex('id') . ' FROM ' . Db::t('invoice_lines') . ' WHERE invoice_id = UNHEX(%s) ORDER BY sort, created_at', $invoiceId));
        foreach ($rows as &$r) {
            $u = $r['source'] === 'manual' ? get_userdata((int) $r['created_by']) : null;
            $r['by'] = $u ? $u->display_name : '';
        }
        return $rows;
    }

    /** Bill with lines, after an access check (staff, or the customer of the order). @return array<string,mixed> */
    public static function get(Actor $actor, string $invoiceId): array
    {
        $inv = self::row($invoiceId);
        $o = Orders::row($inv['order_id']);
        if (!$actor->can('cf_settle_deposits') && !$actor->can('cf_manage_orders')) {
            Orders::assertCustomerAccess($actor, $o);
            if ($inv['status'] === 'draft') {
                throw new DomainError('not_found', 'Bill not found.');
            }
        }
        $inv['lines'] = self::lines($inv['id']);
        $inv['order'] = $o;
        $inv['paid'] = Payments::totals((string) $o['id'], 'balance')['received'];
        $inv['refunded'] = Payments::totals((string) $o['id'], 'refund')['received'];
        $inv['company_name'] = (string) Db::value(Db::prepare('SELECT name FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $o['company_id']));
        return $inv;
    }

    /**
     * Staff list by stage.
     *
     * @return list<array<string,mixed>>
     */
    public static function list(Actor $actor, string $stage): array
    {
        $actor->require('cf_settle_deposits');
        $base = 'SELECT o.ref AS order_ref, ' . Db::hex('o.id', 'order_id') . ', o.status AS order_status, o.kind, o.end_date, o.returned_at, o.total AS booked, c.name AS company_name,
                i.ref, ' . Db::hex('i.id', 'id') . ', i.status, i.total, i.deposit_applied, i.balance_due, i.refund_due, i.issued_at, i.settled_at
            FROM ' . Db::t('orders') . ' o JOIN ' . Db::t('companies') . ' c ON c.id = o.company_id LEFT JOIN ' . Db::t('invoices') . ' i ON i.order_id = o.id';
        $rows = match ($stage) {
            'to_bill' => Db::rows($base . " WHERE o.status = 'returned' AND (i.id IS NULL OR i.status = 'draft') ORDER BY o.returned_at"),
            'open' => Db::rows($base . " WHERE i.status = 'issued' ORDER BY i.issued_at"),
            'refunds' => Db::rows($base . " WHERE o.status IN ('cancelled', 'declined', 'expired') AND EXISTS (SELECT 1 FROM " . Db::t('payments') . " p WHERE p.order_id = o.id AND p.kind = 'deposit' AND p.status = 'received') ORDER BY o.updated_at DESC LIMIT 200"),
            default => Db::rows($base . " WHERE i.status = 'settled' ORDER BY i.settled_at DESC LIMIT 200"),
        };
        foreach ($rows as &$r) {
            $o = ['id' => $r['order_id'], 'status' => $r['order_status']];
            $r['still_due'] = $r['status'] === 'issued' ? self::outstanding($o) : 0.0;
            $r['refund_left'] = in_array($r['order_status'], ['returned', 'closed', 'cancelled', 'declined', 'expired'], true) ? Payments::refundable($o) : 0.0;
        }
        if ($stage === 'refunds') {
            $rows = array_values(array_filter($rows, static fn($r) => $r['refund_left'] > 0));
        }
        return $rows;
    }

    /**
     * Account statement for a company: every issued bill and payment in date order with a running balance.
     *
     * @return array{rows:list<array<string,mixed>>,billed:float,paid:float,deposits:float,refunded:float,balance:float}
     */
    public static function statement(Actor $actor, string $companyId): array
    {
        if (!$actor->can('cf_settle_deposits')) {
            $actor->require('cf_view_statements');
            $companyId = (string) $actor->companyId;
        }
        $companyId = Uuid::assertHex($companyId, 'company');
        $events = [];
        foreach (Db::rows(Db::prepare('SELECT i.ref, ' . Db::hex('i.id', 'id') . ', i.total, i.issued_at, o.ref AS order_ref, ' . Db::hex('o.id', 'order_id') . ' FROM ' . Db::t('invoices') . ' i JOIN ' . Db::t('orders') . ' o ON o.id = i.order_id
            WHERE i.company_id = UNHEX(%s) AND i.status <> %s', $companyId, 'draft')) as $i) {
            $events[] = ['at' => $i['issued_at'], 'what' => 'Bill ' . $i['ref'] . ' (order ' . $i['order_ref'] . ')', 'charge' => (float) $i['total'], 'credit' => 0.0, 'invoice' => $i['id'], 'order' => $i['order_id']];
        }
        foreach (Db::rows(Db::prepare('SELECT p.kind, p.amount, p.method, p.reference, p.received_at, o.ref AS order_ref, ' . Db::hex('o.id', 'order_id') . ' FROM ' . Db::t('payments') . ' p JOIN ' . Db::t('orders') . ' o ON o.id = p.order_id
            WHERE p.company_id = UNHEX(%s) AND p.status = %s', $companyId, 'received')) as $p) {
            $a = (float) $p['amount'];
            $events[] = ['at' => $p['received_at'], 'what' => Payments::KINDS[$p['kind']] . ' · ' . (Payments::METHODS[$p['method']] ?? $p['method']) . ' ' . $p['reference'] . ' (order ' . $p['order_ref'] . ')',
                'charge' => $p['kind'] === 'refund' ? $a : 0.0, 'credit' => $p['kind'] === 'refund' ? 0.0 : $a, 'invoice' => null, 'order' => $p['order_id'], 'kind' => $p['kind']];
        }
        usort($events, static fn($a, $b) => strcmp((string) $a['at'], (string) $b['at']));
        $bal = 0.0;
        $sum = ['billed' => 0.0, 'paid' => 0.0, 'deposits' => 0.0, 'refunded' => 0.0];
        foreach ($events as &$e) {
            $bal = round($bal + $e['charge'] - $e['credit'], 2);
            $e['balance'] = $bal;
            if ($e['invoice']) {
                $sum['billed'] += $e['charge'];
            } elseif (($e['kind'] ?? '') === 'refund') {
                $sum['refunded'] += $e['charge'];
            } elseif (($e['kind'] ?? '') === 'deposit') {
                $sum['deposits'] += $e['credit'];
            } else {
                $sum['paid'] += $e['credit'];
            }
        }
        return ['rows' => $events, 'balance' => $bal] + array_map(static fn($v) => round($v, 2), $sum);
    }

    // ----- How the bill is worked out ---------------------------------------------------------

    /** @return list<array<string,mixed>> */
    public static function systemLines(array $o): array
    {
        $out = [];
        $lines = Db::rows(Db::prepare('SELECT ' . Db::hex('id') . ', line_type, description, qty, charged_days, unit_price, amount, rates_json, breakdown_json FROM ' . Db::t('order_lines') . ' WHERE order_id = UNHEX(%s) ORDER BY sort', $o['id']));
        foreach ($lines as $l) {
            $out[] = ['kind' => 'booked', 'description' => $l['description'] . ($l['line_type'] === 'item' ? ' · ' . (int) $l['charged_days'] . ' days' : ''), 'qty' => (float) $l['qty'], 'unit_price' => (float) $l['unit_price'], 'amount' => (float) $l['amount']];
        }
        $late = self::lateDays($o);

        if ($o['kind'] === 'truck') {
            $l = $lines[0];
            $rates = (array) json_decode((string) $l['rates_json'], true);
            $quoted = (float) $o['distance_km'];
            $actual = $o['actual_km'] !== null ? (int) $o['actual_km'] : null;
            $tol = (int) Settings::get('km_tolerance_pct');
            if ($actual !== null && $quoted > 0 && abs($actual - $quoted) / $quoted * 100 > $tol) {
                $repriced = Pricing::truckTrip($rates, $actual, (int) $o['needs_driver'] === 1, (int) $o['days'], (string) Settings::get('driver_km_basis'))['total'];
                $diff = round($repriced - (float) $l['amount'], 2);
                if (abs($diff) >= 0.01) {
                    $pct = round(($actual - $quoted) / $quoted * 100);
                    $out[] = ['kind' => 'km', 'description' => sprintf('Actual %s km vs quoted %s km (%s%d%%, more than the %d%% allowed)', number_format($actual), number_format($quoted, 1), $pct > 0 ? '+' : '', $pct, $tol), 'qty' => 1, 'unit_price' => $diff, 'amount' => $diff];
                }
            }
            if ($late > 0 && (float) ($rates['daily_rate'] ?? 0) > 0) {
                $out[] = ['kind' => 'late', 'description' => "Returned {$late} day" . ($late === 1 ? '' : 's') . ' late', 'qty' => $late, 'unit_price' => (float) $rates['daily_rate'], 'amount' => round($late * (float) $rates['daily_rate'], 2)];
            }
            $liters = (float) ($o['fuel_short_liters'] ?? 0);
            $price = (float) Settings::get('fuel_price_per_liter');
            if ($liters > 0 && $price > 0) {
                $out[] = ['kind' => 'fuel', 'description' => 'Fuel short at return', 'qty' => $liters, 'unit_price' => $price, 'amount' => round($liters * $price, 2)];
            }
        } else {
            $counts = [];
            foreach (Db::rows(Db::prepare('SELECT ' . Db::hex('line_id') . ', qty_damaged, qty_missing FROM ' . Db::t('order_counts') . ' WHERE order_id = UNHEX(%s) AND direction = %s', $o['id'], 'in')) as $c) {
                $counts[$c['line_id']] = $c;
            }
            foreach ($lines as $l) {
                if ($l['line_type'] !== 'item') {
                    continue;
                }
                $rates = (array) json_decode((string) $l['rates_json'], true);
                if ($late > 0) {
                    $e = Pricing::equipment($rates, (int) $l['qty'], (int) $o['days'] + $late);
                    $diff = round($e['amount'] - (float) $l['amount'], 2);
                    if ($diff > 0) {
                        $out[] = ['kind' => 'late', 'description' => $l['description'] . " · returned {$late} day" . ($late === 1 ? '' : 's') . ' late (' . Pricing::periodLabel($e) . ')', 'qty' => 1, 'unit_price' => $diff, 'amount' => $diff];
                    }
                }
                $c = $counts[$l['id']] ?? null;
                if ($c && (int) $c['qty_damaged'] > 0 && (float) ($rates['damage_fee'] ?? 0) > 0) {
                    $out[] = ['kind' => 'damage', 'description' => $l['description'] . ' · damaged', 'qty' => (int) $c['qty_damaged'], 'unit_price' => (float) $rates['damage_fee'], 'amount' => round((int) $c['qty_damaged'] * (float) $rates['damage_fee'], 2)];
                }
                if ($c && (int) $c['qty_missing'] > 0 && (float) ($rates['replacement_cost'] ?? 0) > 0) {
                    $out[] = ['kind' => 'missing', 'description' => $l['description'] . ' · missing', 'qty' => (int) $c['qty_missing'], 'unit_price' => (float) $rates['replacement_cost'], 'amount' => round((int) $c['qty_missing'] * (float) $rates['replacement_cost'], 2)];
                }
            }
        }
        $tolls = (float) Db::value(Db::prepare('SELECT COALESCE(SUM(amount), 0) FROM ' . Db::t('expenses') . ' WHERE order_id = UNHEX(%s) AND kind IN (%s, %s)', $o['id'], 'toll', 'parking'));
        if ($tolls > 0) {
            $out[] = ['kind' => 'tolls', 'description' => 'Tolls and parking (at cost, receipts on file)', 'qty' => 1, 'unit_price' => round($tolls, 2), 'amount' => round($tolls, 2)];
        }
        return $out;
    }

    /** Whole days between the booked end date and the day it came back (Philippine time). */
    public static function lateDays(array $o): int
    {
        if (empty($o['returned_at'])) {
            return 0;
        }
        $back = (new \DateTimeImmutable((string) $o['returned_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Manila'))->format('Y-m-d');
        $diff = (int) (new \DateTimeImmutable((string) $o['end_date']))->diff(new \DateTimeImmutable($back))->format('%r%a');
        return max(0, $diff);
    }

    /** The bill as a PDF (rebuilt each time from the stored lines). */
    public static function pdf(Actor $actor, string $invoiceId): string
    {
        $inv = self::get($actor, $invoiceId);
        $o = $inv['order'];
        Documents::logView($actor, 'invoice', $inv['id']);
        $company = Db::row(Db::prepare('SELECT name, address, tin FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $o['company_id'])) ?? [];
        $money = static fn($v): string => 'PHP ' . number_format((float) $v, 2);
        $pdf = new \Canpas\Fleet\Support\Pdf('Bill ' . $inv['ref'], 'Canpas · Bill ' . $inv['ref'] . ' · Order ' . $o['ref'] . ' · This is a billing statement, not an official receipt.');
        $pdf->letterhead('FINAL BILL', 'Bill ' . $inv['ref'] . ' · Order ' . $o['ref'],
            $inv['issued_at'] ? 'Issued ' . (new \DateTimeImmutable((string) $inv['issued_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Manila'))->format('j F Y') : 'DRAFT',
            array_values(array_filter([(string) ($company['name'] ?? ''), (string) ($company['address'] ?? ''), !empty($company['tin']) ? 'TIN ' . $company['tin'] : ''])));
        $pdf->paragraph('Rental: ' . (new \DateTimeImmutable((string) $o['start_date']))->format('j M Y') . ' to ' . (new \DateTimeImmutable((string) $o['end_date']))->format('j M Y')
            . ($o['returned_at'] ? ' · returned ' . (new \DateTimeImmutable((string) $o['returned_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Manila'))->format('j M Y') : '')
            . ($o['actual_km'] !== null ? ' · ' . number_format((int) $o['actual_km']) . ' km driven (quoted ' . number_format((float) $o['distance_km'], 1) . ')' : ''), 9.5, false, 8);
        $pdf->rule();
        $pdf->row([['Description', 0, 300, 'L'], ['Qty', 300, 40, 'R'], ['Unit price', 345, 70, 'R'], ['Amount', 420, 63, 'R']], 9, true);
        foreach ($inv['lines'] as $l) {
            $qty = rtrim(rtrim(number_format((float) $l['qty'], 2), '0'), '.');
            $pdf->row([[(string) $l['description'], 0, 300, 'L'], [$qty, 300, 40, 'R'], [number_format((float) $l['unit_price'], 2), 345, 70, 'R'], [number_format((float) $l['amount'], 2), 420, 63, 'R']], 9);
            if ($l['source'] === 'manual' && $l['reason']) {
                $pdf->row([['  Reason: ' . $l['reason'], 0, 400, 'L']], 8);
            }
        }
        $pdf->rule();
        $sum = [['Total', $inv['total']], ['Deposit applied', -(float) $inv['deposit_applied']]];
        if ($inv['status'] !== 'draft') {
            $sum[] = ['Balance due', $inv['balance_due']];
            if ((float) $inv['paid'] > 0) {
                $sum[] = ['Paid', -(float) $inv['paid']];
            }
            if ((float) $inv['refund_due'] > 0) {
                $sum[] = ['Deposit to refund', $inv['refund_due']];
            }
            if ((float) $inv['refunded'] > 0) {
                $sum[] = ['Refunded', -(float) $inv['refunded']];
            }
        }
        foreach ($sum as $i => [$label, $v]) {
            $pdf->row([[$label, 250, 160, 'R'], [$money($v), 415, 68, 'R']], 10, $i === 0);
        }
        $pdf->space(14);
        $pdf->paragraph($inv['status'] === 'settled' ? 'Settled ' . (new \DateTimeImmutable((string) $inv['settled_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Manila'))->format('j F Y') . '. Thank you.'
            : (trim((string) Settings::get('payment_instructions')) ?: 'Please contact Canpas for payment details.'), 9, false, 4);
        $pdf->paragraph('Prices use the rates on the day of booking. Every change after booking is listed with its reason.', 8, false, 0);
        return $pdf->output();
    }

    // ----- internals ---------------------------------------------------------------------------

    private static function insertLine(Actor $actor, string $invoiceId, array $l): void
    {
        Db::insert('invoice_lines', [
            'id' => Uuid::v7(), 'invoice_id' => $invoiceId, 'kind' => $l['kind'], 'description' => mb_substr((string) $l['description'], 0, 255),
            'qty' => number_format((float) $l['qty'], 2, '.', ''), 'unit_price' => number_format((float) $l['unit_price'], 2, '.', ''), 'amount' => number_format((float) $l['amount'], 2, '.', ''),
            'source' => $l['source'], 'reason' => $l['reason'] ?? null, 'created_by' => $actor->userId, 'created_at' => Db::now(), 'sort' => (int) $l['sort'],
        ], ['id', 'invoice_id']);
    }

    private static function recalc(string $invoiceId): void
    {
        Db::exec(Db::prepare(
            'UPDATE ' . Db::t('invoices') . ' SET total = GREATEST(0, (SELECT COALESCE(SUM(amount), 0) FROM ' . Db::t('invoice_lines') . ' WHERE invoice_id = UNHEX(%s))), updated_at = %s WHERE id = UNHEX(%s)',
            $invoiceId, Db::now(), $invoiceId
        ));
    }

    private static function reason(array $in): string
    {
        $r = sanitize_text_field(wp_unslash((string) ($in['reason'] ?? '')));
        if (mb_strlen($r) < 5) {
            throw new DomainError('reason_required', 'Say why (at least 5 characters). Every price change after booking keeps a reason.', ['field' => 'reason']);
        }
        return mb_substr($r, 0, 255);
    }

    private static function assertDraft(array $inv): void
    {
        if ($inv['status'] !== 'draft') {
            throw new DomainError('wrong_status', 'This bill has been issued and can no longer be changed.');
        }
    }

    /** @return array<string,mixed> */
    private static function row(string $invoiceId): array
    {
        $invoiceId = Uuid::assertHex($invoiceId, 'bill');
        $r = Db::row(Db::prepare('SELECT *, ' . Db::hex('id') . ', ' . Db::hex('order_id') . ', ' . Db::hex('company_id') . ' FROM ' . Db::t('invoices') . ' WHERE id = UNHEX(%s)', $invoiceId));
        if ($r === null) {
            throw new DomainError('not_found', 'Bill not found.');
        }
        return $r;
    }
}
