<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal\Screens;

use Canpas\Fleet\Domain\Billing;
use Canpas\Fleet\Domain\Companies;
use Canpas\Fleet\Domain\Payments;
use Canpas\Fleet\Domain\Settings;
use Canpas\Fleet\Portal\Layout;
use Canpas\Fleet\Portal\Pages;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Portal\Ui;
use Canpas\Fleet\Tenancy\Actor;

/**
 * The final bill on an order (staff build and issue it; customers view and pay it),
 * the staff Statements page and the customer's My statements.
 */
final class BillingScreen
{
    // ----- Final bill on the order page --------------------------------------------------------

    public static function card(Actor $actor, array $o): string
    {
        $settle = $actor->can('cf_settle_deposits');
        $staff = $actor->isStaff();
        if (in_array($o['status'], ['cancelled', 'declined', 'expired'], true)) {
            return self::refundOnly($actor, $o);
        }
        if (!in_array($o['status'], ['returned', 'closed'], true)) {
            return '';
        }
        $inv = Billing::forOrder((string) $o['id']);
        if ($inv === null) {
            if (!$staff) {
                return Ui::card('Final bill', '<p class="muted" style="margin:0">Canpas is preparing your final bill. You will get an email when it is ready.</p>');
            }
            return Ui::card('Final bill', '<p style="margin:0 0 12px">The order is back. Work out the final bill from the return: actual km, late days, fuel, damage, missing items and tolls, at the rates booked.</p>'
                . ($settle ? Ui::action('Work out the final bill', "orders/{$o['id']}/bill", 'primary') : '<p class="muted small">An admin makes the final bill.</p>'));
        }
        if ($inv['status'] === 'draft' && !$staff) {
            return Ui::card('Final bill', '<p class="muted" style="margin:0">Canpas is preparing your final bill. You will get an email when it is ready.</p>');
        }
        $full = Billing::get($actor, $inv['id']);
        $draft = $inv['status'] === 'draft';
        $edit = $draft && $settle;

        $rows = [];
        foreach ($full['lines'] as $l) {
            $qty = rtrim(rtrim(number_format((float) $l['qty'], 2), '0'), '.');
            $why = $l['source'] === 'manual' ? '<br><span class="muted small">Added by ' . Ui::e($l['by']) . ': ' . Ui::e((string) $l['reason']) . '</span>' : '';
            $rows[] = [
                '<span class="pill ' . ($l['kind'] === 'booked' ? 'info' : ($l['kind'] === 'discount' ? 'good' : 'warn')) . '">' . Ui::e(Billing::KINDS[$l['kind']] ?? $l['kind']) . '</span>',
                Ui::e($l['description']) . $why,
                Ui::e($qty),
                Ui::e(Ui::money($l['unit_price'])),
                Ui::e(Ui::money($l['amount'])),
                $edit && $l['kind'] !== 'booked' ? Ui::action('Remove', "bill-lines/{$l['id']}/remove", 'danger', '', 'Why is this line removed? (kept in the audit log)') : '',
            ];
        }
        $table = Ui::table(['', 'Description', '>Qty', '>Unit price', '>Amount', ''], $rows);

        $deposit = Payments::totals((string) $o['id'])['received'];
        $sum = '<dl class="dl totals"><dt>Total</dt><dd class="num"><b>' . Ui::e(Ui::money($full['total'])) . '</b></dd>';
        if ($draft) {
            $sum .= '<dt>Deposit received (applied when issued)</dt><dd class="num">' . Ui::e(Ui::money($deposit)) . '</dd>'
                . '<dt>Booked price</dt><dd class="num muted">' . Ui::e(Ui::money($full['booked_total'])) . '</dd>';
        } else {
            $sum .= '<dt>Deposit applied</dt><dd class="num">− ' . Ui::e(Ui::money($full['deposit_applied'])) . '</dd>'
                . '<dt>Balance due</dt><dd class="num">' . Ui::e(Ui::money($full['balance_due'])) . '</dd>'
                . ((float) $full['paid'] > 0 ? '<dt>Paid</dt><dd class="num">− ' . Ui::e(Ui::money($full['paid'])) . '</dd>' : '')
                . ((float) $full['refund_due'] > 0 ? '<dt>Deposit to refund</dt><dd class="num">' . Ui::e(Ui::money($full['refund_due'])) . '</dd>' : '')
                . ((float) $full['refunded'] > 0 ? '<dt>Refunded</dt><dd class="num">− ' . Ui::e(Ui::money($full['refunded'])) . '</dd>' : '');
        }
        $sum .= '</dl>';

        $parts = [$table, $sum];
        if ($edit) {
            $kinds = '';
            foreach (Billing::MANUAL as $k) {
                $kinds .= '<option value="' . $k . '">' . Ui::e(Billing::KINDS[$k]) . '</option>';
            }
            $parts[] = '<details class="upload"><summary>Add a charge or discount</summary><form class="form" data-endpoint="bills/' . esc_attr($inv['id']) . '/lines" novalidate><div class="form-grid">'
                . '<div class="field"><label for="bl-kind">Kind</label><select id="bl-kind" name="kind">' . $kinds . '</select></div>'
                . '<div class="field"><label for="bl-desc">Description</label><input id="bl-desc" name="description" maxlength="255" required></div>'
                . '<div class="field"><label for="bl-qty">Qty</label><input id="bl-qty" name="qty" value="1" inputmode="decimal" class="num"></div>'
                . '<div class="field"><label for="bl-price">Unit price (₱)</label><input id="bl-price" name="unit_price" inputmode="decimal" class="num" required></div>'
                . '<div class="field wide"><label for="bl-why">Reason (shown on the bill)</label><input id="bl-why" name="reason" maxlength="255" required></div>'
                . '</div><p class="form-error" role="alert" hidden></p><div class="form-actions"><button class="btn primary" type="submit">Add line</button></div></form></details>';
            $parts[] = '<div class="row-actions">' . Ui::action('Work it out again', "orders/{$o['id']}/bill", '', 'Rebuild the automatic lines from the return? Lines you added by hand stay.')
                . Ui::action('Issue bill', "bills/{$inv['id']}/issue", 'primary', 'Issue bill ' . $inv['ref'] . ' for ' . Ui::money($full['total']) . '? It is locked and emailed to the customer.') . '</div>';
        }
        if (!$draft) {
            $parts[] = '<p style="margin:0"><a class="btn" href="' . esc_url(Pages::fileUrl(['bill' => $inv['id']])) . '" target="_blank" rel="noopener">Download PDF</a>'
                . ($inv['status'] === 'settled' ? ' <span class="pill good">Settled ' . Ui::e(Ui::when($inv['settled_at'])) . '</span>' : '') . '</p>';
            $parts[] = self::payments($actor, $o);
            $outstanding = Billing::outstanding($o);
            if ($outstanding > 0) {
                $parts[] = $staff ? ($actor->can('cf_confirm_payments') ? self::moneyForm("orders/{$o['id']}/record-payment", 'Record a payment received', $outstanding, true, false) : '')
                    : self::customerPayForm($o, $outstanding);
            }
            $refund = Payments::refundable($o);
            if ($refund > 0) {
                $parts[] = $settle ? self::moneyForm("orders/{$o['id']}/refund", 'Record the refund paid to the customer', $refund, true, false)
                    : '<div class="callout info">Canpas will refund ' . Ui::e(Ui::money($refund)) . ' of your deposit.</div>';
            }
        }
        $status = $draft ? '<span class="pill warn">Draft — not yet sent</span>' : '<span class="pill ' . ($inv['status'] === 'settled' ? 'good' : 'info') . '">' . Ui::e(Billing::STATUS_LABELS[$inv['status']]) . '</span>';
        return Ui::card('Final bill ' . $inv['ref'], implode('', array_map(static fn($p) => '<div class="ops-block">' . $p . '</div>', array_filter($parts))), $status);
    }

    private static function refundOnly(Actor $actor, array $o): string
    {
        $refund = Payments::refundable($o);
        if ($refund <= 0) {
            return '';
        }
        $body = $actor->can('cf_settle_deposits')
            ? '<p style="margin:0 0 12px">This order was ' . Ui::e($o['status']) . ' with ' . Ui::e(Ui::money($refund)) . ' of deposit received. Record the refund (and any amount kept under the agreement, with the reason).</p>'
              . self::moneyForm("orders/{$o['id']}/refund", 'Record the refund', $refund, true, true)
            : '<p class="muted" style="margin:0">Canpas will contact you about returning your deposit of ' . Ui::e(Ui::money($refund)) . '.</p>';
        return Ui::card('Deposit refund', $body);
    }

    /** Payments on the order after the bill: balance proofs and refunds. */
    private static function payments(Actor $actor, array $o): string
    {
        $rows = [];
        foreach (Payments::forOrder((string) $o['id']) as $p) {
            if ($p['kind'] === 'deposit') {
                continue;
            }
            $acts = $actor->can('cf_confirm_payments') && $p['status'] === 'pending'
                ? '<div class="row-actions">' . Ui::action('Received', "payments/{$p['id']}/receive", 'primary', 'Mark ' . Ui::money($p['amount']) . ' as received?')
                  . Ui::action('Reject', "payments/{$p['id']}/reject", 'danger', '', 'Why is this payment rejected? The customer sees this reason.') . '</div>' : '';
            $rows[] = [
                Ui::e(Ui::when($p['created_at'])),
                Ui::e(Payments::KINDS[$p['kind']] ?? $p['kind']) . '<br><span class="muted small">' . Ui::e(Payments::METHODS[$p['method']] ?? $p['method']) . ' ' . Ui::e($p['reference']) . '</span>'
                    . ($p['status_reason'] ? '<br><span class="muted small">' . Ui::e($p['status_reason']) . '</span>' : ''),
                $p['proof_file'] ? '<a href="' . esc_url(Pages::fileUrl(['payment' => $p['id']])) . '" target="_blank" rel="noopener">Proof</a>' : '—',
                Ui::pill($p['status'] === 'received' ? 'active' : ($p['status'] === 'pending' ? 'pending' : 'declined'), Payments::STATUS_LABELS[$p['status']] ?? $p['status']),
                Ui::e(Ui::money($p['amount'])),
                $acts,
            ];
        }
        return $rows === [] ? '' : '<h3 class="sub">Payments and refunds</h3>' . Ui::table(['When', 'What', 'Proof', 'Status', '>Amount', ''], $rows);
    }

    private static function customerPayForm(array $o, float $due): string
    {
        $methods = '';
        foreach (Payments::METHODS as $k => $l) {
            if ($k !== 'cash') {
                $methods .= '<option value="' . $k . '">' . Ui::e($l) . '</option>';
            }
        }
        $instructions = trim((string) Settings::get('payment_instructions'));
        return '<details class="upload" open><summary>Pay the balance of ' . Ui::e(Ui::money($due)) . '</summary>'
            . ($instructions !== '' ? '<div class="pre small">' . Ui::e($instructions) . '</div>' : '')
            . '<form class="form" data-endpoint="orders/' . esc_attr($o['id']) . '/payments" novalidate><div class="form-grid">'
            . '<div class="field"><label for="bp-amount">Amount paid (₱)</label><input id="bp-amount" name="amount" inputmode="decimal" class="num" value="' . esc_attr(number_format($due, 2, '.', '')) . '" required></div>'
            . '<div class="field"><label for="bp-method">Paid by</label><select id="bp-method" name="method">' . $methods . '</select></div>'
            . '<div class="field"><label for="bp-ref">Reference number</label><input id="bp-ref" name="reference" maxlength="80" required></div>'
            . '<div class="field"><label for="bp-proof">Receipt or screenshot</label><input id="bp-proof" name="proof" type="file" accept="image/*,application/pdf" required></div>'
            . '</div><p class="form-error" role="alert" hidden></p><div class="form-actions"><button class="btn primary" type="submit">Send proof</button></div></form></details>';
    }

    private static function moneyForm(string $endpoint, string $title, float $max, bool $open, bool $reasonRequired): string
    {
        static $n = 0;
        $n++;
        $methods = '';
        foreach (Payments::METHODS as $k => $l) {
            $methods .= '<option value="' . $k . '">' . Ui::e($l) . '</option>';
        }
        return '<details class="upload"' . ($open ? ' open' : '') . '><summary>' . Ui::e($title) . ' (' . Ui::e(Ui::money($max)) . ')</summary>'
            . '<form class="form" data-endpoint="' . esc_attr($endpoint) . '" novalidate><div class="form-grid">'
            . '<div class="field"><label for="mf-a' . $n . '">Amount (₱)</label><input id="mf-a' . $n . '" name="amount" inputmode="decimal" class="num" value="' . esc_attr(number_format($max, 2, '.', '')) . '" required></div>'
            . '<div class="field"><label for="mf-m' . $n . '">How</label><select id="mf-m' . $n . '" name="method">' . $methods . '</select></div>'
            . '<div class="field"><label for="mf-r' . $n . '">Reference <span class="opt">(not needed for cash)</span></label><input id="mf-r' . $n . '" name="reference" maxlength="80"></div>'
            . (str_contains($endpoint, 'refund') ? '<div class="field wide"><label for="mf-w' . $n . '">Reason' . ($reasonRequired ? '' : ' <span class="opt">(optional)</span>') . '</label><input id="mf-w' . $n . '" name="reason" maxlength="255"' . ($reasonRequired ? ' required' : '') . '></div>' : '')
            . '</div><p class="form-error" role="alert" hidden></p><div class="form-actions"><button class="btn primary" type="submit">Record</button></div></form></details>';
    }

    // ----- Staff: statements -------------------------------------------------------------------

    public static function statements(Actor $actor): void
    {
        $company = strtolower(sanitize_key((string) ($_GET['company'] ?? '')));
        if ($company !== '') {
            self::companyStatement($actor, $company);
            return;
        }
        $stage = sanitize_key((string) ($_GET['stage'] ?? 'to_bill'));
        $stages = ['to_bill' => 'To bill', 'open' => 'Awaiting settlement', 'refunds' => 'Cancelled — deposit to refund', 'settled' => 'Settled'];
        $stage = isset($stages[$stage]) ? $stage : 'to_bill';
        $tabs = [];
        foreach ($stages as $k => $l) {
            $tabs[$k] = $l . ($k !== 'settled' ? ' (' . count(Billing::list($actor, $k)) . ')' : '');
        }
        $rows = [];
        foreach (Billing::list($actor, $stage) as $r) {
            $rows[] = [
                '<a class="mono" href="' . esc_url(add_query_arg('id', $r['order_id'], Router::url('orders'))) . '"><b>' . Ui::e($r['order_ref']) . '</b></a>' . ($r['ref'] ? '<br><span class="muted small mono">' . Ui::e($r['ref']) . '</span>' : ''),
                Ui::e($r['company_name']),
                Ui::e($stage === 'to_bill' ? Ui::when($r['returned_at']) : ($stage === 'settled' ? Ui::when($r['settled_at']) : ($r['issued_at'] ? Ui::when($r['issued_at']) : '—'))),
                $r['ref'] ? ($r['status'] === 'draft' ? '<span class="pill warn">Draft</span>' : Ui::e(Ui::money($r['total']))) : '<span class="muted">not started</span>',
                $r['still_due'] > 0 ? '<b>' . Ui::e(Ui::money($r['still_due'])) . '</b> to collect' : '',
                $r['refund_left'] > 0 ? '<b>' . Ui::e(Ui::money($r['refund_left'])) . '</b> to refund' : '',
            ];
        }
        $companies = '';
        foreach (Companies::list($actor) as $c) {
            if ($c['status'] === 'declined' || $c['status'] === 'pending') {
                continue;
            }
            $companies .= '<option value="' . esc_attr($c['id']) . '">' . Ui::e($c['name']) . '</option>';
        }
        $picker = '<form class="inline-form" method="get" action="' . esc_url(Router::url('statements')) . '"><div class="field"><label for="st-co">Company statement</label><select id="st-co" name="company">' . $companies . '</select></div><button class="btn" type="submit">Open</button></form>';
        Layout::page($actor, 'statements', 'Statements', Ui::tabs($tabs, $stage, 'stage')
            . Ui::card('', Ui::table(['Order / bill', 'Company', $stage === 'to_bill' ? 'Returned' : ($stage === 'settled' ? 'Settled' : 'Issued'), '>Bill total', 'Balance', 'Refund'], $rows, 'Nothing here.'))
            . Ui::card('', $picker), 'Finance');
    }

    private static function companyStatement(Actor $actor, string $companyId): void
    {
        try {
            $c = Companies::get($actor, $companyId);
            $st = Billing::statement($actor, $companyId);
        } catch (\Canpas\Fleet\Support\DomainError $e) {
            Layout::message('Statement not available', $e->getMessage(), 404, ['label' => 'Back to statements', 'href' => Router::url('statements')]);
            return;
        }
        Layout::page($actor, 'statements', 'Statement · ' . $c['name'], self::statementBody($st), 'Finance / Statements');
    }

    // ----- Customer: my statements -------------------------------------------------------------

    public static function myStatements(Actor $actor): void
    {
        $st = Billing::statement($actor, (string) $actor->companyId);
        Layout::page($actor, 'my-statements', 'Statements', self::statementBody($st), 'My account');
    }

    /** @param array<string,mixed> $st */
    private static function statementBody(array $st): string
    {
        $kpis = '<div class="grid kpis">'
            . Ui::kpi('Billed', Ui::money($st['billed']), 'Final bills issued')
            . Ui::kpi('Paid', Ui::money($st['paid'] + $st['deposits']), 'Deposits ' . Ui::money($st['deposits']) . ' + payments ' . Ui::money($st['paid']))
            . Ui::kpi('Refunded', Ui::money($st['refunded']), 'Deposits returned')
            . Ui::kpi($st['balance'] > 0 ? 'Balance owed' : 'In credit', Ui::money(abs($st['balance'])), $st['balance'] > 0 ? 'To pay to Canpas' : 'Deposits not yet applied or refunded', $st['balance'] > 0 ? 'warn' : '') . '</div>';
        $rows = [];
        foreach (array_reverse($st['rows']) as $e) {
            $rows[] = [
                Ui::e(Ui::when($e['at'])),
                Ui::e($e['what']) . ($e['invoice'] ? ' · <a href="' . esc_url(Pages::fileUrl(['bill' => $e['invoice']])) . '" target="_blank" rel="noopener">PDF</a>' : ''),
                $e['charge'] > 0 ? Ui::e(Ui::money($e['charge'])) : '',
                $e['credit'] > 0 ? Ui::e(Ui::money($e['credit'])) : '',
                Ui::e(Ui::money($e['balance'])),
            ];
        }
        return $kpis . Ui::card('Account history', Ui::table(['Date', 'What', '>Charge', '>Payment', '>Balance'], $rows, 'No bills or payments yet.')
            . '<p class="muted small" style="margin-top:12px">Deposits count as payments when received; they are applied to the final bill when it is issued. A negative balance means Canpas holds a deposit not yet billed or refunded. This statement is not an official receipt.</p>');
    }
}
