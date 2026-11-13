<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal\Screens;

use Canpas\Fleet\Domain\Orders;
use Canpas\Fleet\Domain\Quotes;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Portal\Ui;
use Canpas\Fleet\Support\Mail;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Special-rate quotation parts of the order page: the Canpas admin's pricing form, the
 * normal-vs-special comparison, the customer's Accept / Ask for a better price / Decline,
 * the round history, PDF links and the emails sent.
 */
final class QuotesScreen
{
    public static function isQuote(array $o): bool
    {
        return in_array($o['status'], Orders::QUOTE_STATUSES, true) || (int) ($o['quote_round'] ?? 0) > 0;
    }

    /** The quotation card for an order page ('' for ordinary bookings). */
    public static function card(Actor $actor, array $o): string
    {
        if (!self::isQuote($o)) {
            return '';
        }
        $html = '';
        $actions = '';
        $offer = Quotes::latestOffer($o['id']);
        $canPrice = $actor->can('cf_manage_rates');
        $isAdmin = Quotes::isCustomerAdmin($actor, $o);

        if ($o['status'] === 'quote_requested') {
            if ($canPrice) {
                $html .= self::priceForm($actor, $o);
                $actions .= Ui::action('Decline request', "orders/{$o['id']}/quote-decline", 'danger', '', 'Why can Canpas not offer a special rate? The customer sees this reason.');
            } elseif ($actor->isStaff()) {
                $html .= '<p class="callout info">Waiting for the Canpas admin to price this quotation. The normal price is shown above.</p>';
            } else {
                $html .= '<p class="callout info">Canpas is preparing your special-rate quotation. You will get an email as soon as it is ready.</p>';
            }
        }
        if ($offer !== null && $o['status'] !== 'quote_requested') {
            $lines = (array) json_decode((string) $offer['lines_json'], true);
            if ($o['status'] === 'quoted' && $o['quote_valid_until']) {
                $html .= '<p class="callout"><b>Valid until ' . Ui::e(Quotes::manila((string) $o['quote_valid_until'])) . '</b> (Philippine time). The items are held for you until then.</p>';
            }
            $html .= self::comparison($lines, (float) $offer['normal_total'], (float) $offer['total'], (float) $offer['deposit']);
            if ($offer['note']) {
                $html .= '<p><b>Note from Canpas:</b> ' . Ui::e((string) $offer['note']) . '</p>';
            }
            $html .= '<p><a class="btn" href="' . esc_url(add_query_arg(['quote' => $o['id'], 'round' => (int) $offer['round']], Router::url('file'))) . '" target="_blank" rel="noopener">Download quotation (PDF)</a></p>';
            if ($o['status'] === 'quoted' && $canPrice) {
                $actions .= Ui::action('Withdraw quotation', "orders/{$o['id']}/quote-decline", 'danger', '', 'Why is Canpas withdrawing this quotation? The customer sees this reason.');
            }
            if ($o['status'] === 'quoted') {
                if ($isAdmin) {
                    $accepted = array_filter(Quotes::rounds($actor, $o['id']), static fn($r) => $r['kind'] === 'accept') !== [];
                    $actions .= Ui::action('Decline', "orders/{$o['id']}/quote-decline", 'danger', '', 'Why are you declining? (optional)')
                        . ($accepted ? '' : Ui::action('Ask for a better price', "orders/{$o['id']}/quote-counter", '', '', 'What price are you hoping for? Canpas sees this note.'))
                        . Ui::action('Accept quotation', "orders/{$o['id']}/quote-accept", 'primary', "Accept quotation {$o['ref']} at " . Ui::money($offer['total']) . '? It becomes your held order.');
                } elseif (!$actor->isStaff()) {
                    $html .= '<p class="muted small">Your company admin can accept this quotation, decline it or ask for a better price.</p>';
                }
            }
        }
        $html .= self::history($actor, $o);
        $title = match ($o['status']) {
            'quote_requested' => $canPrice ? 'Price this quotation' : 'Special-rate quotation',
            'quoted' => 'Special-rate quotation',
            default => 'Quotation',
        };
        return Ui::card($title, $html, $actions !== '' ? '<div class="row-actions">' . $actions . '</div>' : '');
    }

    /** Normal vs special, line by line, with the saving. Escapes everything. */
    public static function comparison(array $lines, float $normalTotal, float $total, float $deposit): string
    {
        $rows = [];
        foreach ($lines as $l) {
            $rates = '';
            foreach ((array) ($l['special'] ?? []) as $f => $v) {
                $n = (float) ($l['normal'][$f] ?? 0);
                if ($n <= 0 && (float) $v <= 0) {
                    continue;
                }
                $rates .= '<span class="muted small rate">' . Ui::e(Quotes::FIELD_LABELS[$f] ?? $f) . ' '
                    . ((float) $v < $n ? '<s>' . Ui::e(Ui::money($n)) . '</s> ' : '') . '<b>' . Ui::e(Ui::money($v)) . '</b></span> ';
            }
            $qty = $l['line_type'] === 'delivery' ? (int) $l['qty'] . ' trip' . ((int) $l['qty'] === 1 ? '' : 's') : number_format((float) $l['qty']);
            $rows[] = [
                '<b>' . Ui::e((string) $l['description']) . '</b><br><span class="rates">' . $rates . '</span>',
                Ui::e($qty),
                (float) $l['normal_amount'] > (float) $l['amount'] ? '<s class="muted">' . Ui::e(Ui::money($l['normal_amount'])) . '</s>' : Ui::e(Ui::money($l['normal_amount'])),
                '<b>' . Ui::e(Ui::money($l['amount'])) . '</b>',
            ];
        }
        $saving = $normalTotal - $total;
        return Ui::table(['Item', '>Qty', '>Normal', '>Special'], $rows)
            . '<dl class="totals"><dt>Normal price</dt><dd class="num"><s class="muted">' . Ui::e(Ui::money($normalTotal)) . '</s></dd>'
            . '<dt><b>Special price</b></dt><dd class="num"><b>' . Ui::e(Ui::money($total)) . '</b></dd>'
            . ($saving > 0.004 ? '<dt>You save</dt><dd class="num save">' . Ui::e(Ui::money($saving)) . '</dd>' : '')
            . '<dt>Deposit to hold the order</dt><dd class="num">' . Ui::e(Ui::money($deposit)) . '</dd></dl>';
    }

    /** The Canpas admin's form: special rate per field (blank = normal), a % shortcut and a note. */
    private static function priceForm(Actor $actor, array $o): string
    {
        $lines = Quotes::lines($o['id']);
        $req = '';
        foreach (Quotes::rounds($actor, $o['id']) as $r) {
            if (in_array($r['kind'], ['request', 'counter'], true) && $r['note']) {
                $req = (string) $r['note'];
            }
        }
        $html = $req !== '' ? '<p class="callout info"><b>Customer’s note:</b> ' . Ui::e($req) . '</p>' : '';
        $html .= '<form class="form" data-endpoint="orders/' . esc_attr($o['id']) . '/quote-offer" data-after="reload" novalidate>';
        $html .= '<div class="form-grid"><div class="field"><label for="f-pct">Discount on all lines (%) <span class="opt">(optional)</span></label>'
            . '<input id="f-pct" name="pct_off" type="text" inputmode="decimal" class="num" placeholder="e.g. 10"></div></div>'
            . '<p class="muted small">Leave a rate blank to use the normal rate, or the discount above if you set one. A rate typed here wins over the discount.</p>';
        foreach ($lines as $l) {
            $normal = Quotes::normalRates($l);
            $free = '';
            if ($l['line_type'] === 'item') {
                $n = Orders::itemFree($l['item_id'], (string) $o['start_date'], (string) $o['end_date']);
                $free = $n >= (int) $l['qty'] ? '<span class="pill good">' . number_format($n) . ' free</span>' : '<span class="pill bad">Only ' . number_format($n) . ' free</span>';
            } elseif ($l['line_type'] === 'truck') {
                $n = Orders::truckTypeCapacity($l['truck_type_id']) - Orders::peakBooked('truck_type_id', $l['truck_type_id'], (string) $o['start_date'], (string) $o['end_date'], 'truck');
                $free = $n >= 1 ? '<span class="pill good">' . $n . ' free</span>' : '<span class="pill bad">None free</span>';
            }
            $qty = $l['line_type'] === 'delivery' ? (int) $l['qty'] . ' trip(s)' : number_format((float) $l['qty']);
            $html .= '<fieldset class="quote-line"><legend><b>' . Ui::e($l['description']) . '</b> <span class="muted small">× ' . Ui::e($qty) . ' · normal ' . Ui::e(Ui::money($l['amount'])) . '</span> ' . $free . '</legend><div class="rate-grid">';
            foreach (Quotes::FIELDS[$l['line_type']] ?? [] as $f) {
                $nv = (float) ($normal[$f] ?? 0);
                $id = 'r-' . $l['id'] . '-' . $f;
                $html .= '<div class="field"><label for="' . esc_attr($id) . '">' . Ui::e(Quotes::FIELD_LABELS[$f]) . ' <span class="muted small">normal ' . Ui::e(Ui::money($nv)) . '</span></label>'
                    . '<input id="' . esc_attr($id) . '" name="rate[' . esc_attr($l['id']) . '][' . esc_attr($f) . ']" type="text" inputmode="decimal" class="num" placeholder="' . esc_attr(number_format($nv, 2, '.', '')) . '"></div>';
            }
            $html .= '</div></fieldset>';
        }
        $html .= '<div class="form-grid"><div class="field wide"><label for="f-qnote">Note to the customer <span class="opt">(optional)</span></label><textarea id="f-qnote" name="note" rows="2" maxlength="1000"></textarea></div></div>'
            . '<div class="quote-box" hidden aria-live="polite"></div><p class="form-error" role="alert" hidden></p>'
            . '<div class="form-actions"><button type="button" class="btn" data-quote="orders/' . esc_attr($o['id']) . '/quote-preview">Preview special price</button>'
            . '<button type="submit" class="btn primary" data-needs-quote disabled>Send quotation</button></div></form>';
        return $html;
    }

    private static function history(Actor $actor, array $o): string
    {
        $words = ['request' => 'Asked for a special rate', 'offer' => 'Sent a quotation', 'counter' => 'Asked for a better price', 'accept' => 'Accepted the quotation',
            'decline' => 'Declined', 'reoffer' => 'Hold ended; quotation open again', 'expired' => 'Expired'];
        $items = '';
        foreach (Quotes::rounds($actor, $o['id']) as $r) {
            $what = $words[$r['kind']] ?? $r['kind'];
            if (in_array($r['kind'], ['offer', 'reoffer'], true)) {
                $what .= ' (Q' . (int) $r['round'] . ', ' . Ui::money($r['total']) . ')';
            }
            $items .= '<li><span><b>' . Ui::e($what) . '</b> · ' . Ui::e($r['by_name']) . ($r['note'] ? '<br><span class="muted small">“' . Ui::e((string) $r['note']) . '”</span>' : '') . '</span>'
                . '<span class="muted small">' . Ui::e(Ui::when($r['created_at'])) . '</span></li>';
        }
        return $items !== '' ? '<h3 class="cat">History</h3><ul class="list">' . $items . '</ul>' : '';
    }

    /** Emails sent about this order (any order), for the record. */
    public static function emailsCard(Actor $actor, array $o): string
    {
        $items = '';
        foreach (Mail::forOrder($o['id'], $actor->isStaff()) as $m) {
            $items .= '<li><span><b>' . Ui::e($m['subject']) . '</b><br><span class="muted small">To ' . Ui::e($m['to_email']) . ' · ' . Ui::e(Ui::when($m['created_at'])) . '</span></span>'
                . ($m['sent_ok'] ? Ui::pill('active', 'Sent') : Ui::pill('suspended', 'Not sent')) . '</li>';
        }
        return $items === '' ? '' : Ui::card('Emails sent', '<ul class="list">' . $items . '</ul>');
    }
}
