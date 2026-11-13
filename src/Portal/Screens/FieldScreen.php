<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal\Screens;

use Canpas\Fleet\Domain\Dispatch;
use Canpas\Fleet\Domain\Expenses;
use Canpas\Fleet\Domain\Orders;
use Canpas\Fleet\Domain\Settings;
use Canpas\Fleet\Domain\Trips;
use Canpas\Fleet\Domain\Yard;
use Canpas\Fleet\Portal\Layout;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Portal\Ui;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Phone screens for drivers (Today, Expenses) and the yard (Loadings, Returns).
 * Big buttons, 16px inputs, photos straight from the camera, signatures on the screen.
 */
final class FieldScreen
{
    // ----- Driver: today -----------------------------------------------------------------------

    public static function today(Actor $actor): void
    {
        $tripId = strtolower(sanitize_key((string) ($_GET['trip'] ?? '')));
        if ($tripId !== '') {
            self::trip($actor, $tripId);
            return;
        }
        $today = Db::todayManila()->format('Y-m-d');
        $cards = '';
        foreach (Trips::forDriver($actor) as $t) {
            $next = Trips::next($t);
            $when = $t['trip_date'] === $today ? 'Today' : Ui::date($t['trip_date']);
            $cards .= '<a class="trip-card" href="' . esc_url(add_query_arg('trip', $t['id'], Router::url('today'))) . '">'
                . '<div class="trip-top"><b>' . Ui::e(Dispatch::TRIP_KINDS[$t['kind']]) . '</b><span class="pill ' . ($t['status'] === 'planned' ? 'info' : 'warn') . '">' . Ui::e($t['status'] === 'planned' ? $when : Trips::LABELS[$t['status']]) . '</span></div>'
                . '<div class="trip-ref mono">' . Ui::e($t['ref']) . ' · ' . Ui::e((string) $t['plate']) . '</div>'
                . '<div>' . Ui::e($t['company_name']) . '</div><div class="muted small">' . Ui::e((string) $t['site_address']) . '</div>'
                . ($next ? '<div class="trip-next">Next: ' . Ui::e(Trips::LABELS[$next]) . ' →</div>' : '') . '</a>';
        }
        $body = $cards !== '' ? '<div class="trip-list">' . $cards . '</div>'
            : Ui::card('', '<p class="empty">No trips for you in the next 7 days.</p>');
        Layout::page($actor, 'today', 'My trips', $body, 'Trips');
    }

    private static function trip(Actor $actor, string $tripId): void
    {
        try {
            $t = Trips::get($actor, $tripId);
        } catch (DomainError $e) {
            Layout::message('Trip not found', $e->getMessage(), 404, ['label' => 'Back to my trips', 'href' => Router::url('today')]);
            return;
        }
        $o = Orders::row($t['order_id']);
        $company = (string) Db::value(Db::prepare('SELECT name FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $o['company_id']));
        $next = Trips::next($t);
        $toYard = in_array($next, ['back'], true);
        [$lat, $lng] = $toYard ? [Settings::get('yard_lat'), Settings::get('yard_lng')] : [$o['site_lat'], $o['site_lng']];
        $nav = $lat && $lng
            ? '<div class="map-btns"><a class="btn big" href="' . esc_url('https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($lat . ',' . $lng)) . '" target="_blank" rel="noopener">Google Maps</a>'
              . '<a class="btn big" href="' . esc_url('https://waze.com/ul?ll=' . rawurlencode($lat . ',' . $lng) . '&navigate=yes') . '" target="_blank" rel="noopener">Waze</a></div>'
              . '<p class="muted small" style="margin:6px 0 0">Directions to ' . ($toYard ? 'the Canpas yard' : 'the site') . '.</p>'
            : '';
        $what = Db::value(Db::prepare('SELECT GROUP_CONCAT(CONCAT(qty, \' × \', description) ORDER BY sort SEPARATOR \'\n\') FROM ' . Db::t('order_lines') . ' WHERE order_id = UNHEX(%s) AND line_type <> %s', $o['id'], 'delivery'));
        $info = '<dl class="dl">'
            . '<dt>Order</dt><dd class="mono">' . Ui::e($o['ref']) . '</dd>'
            . '<dt>Customer</dt><dd>' . Ui::e($company) . '</dd>'
            . '<dt>Site</dt><dd>' . Ui::e((string) $o['site_address']) . '</dd>'
            . '<dt>Truck</dt><dd class="mono">' . Ui::e((string) $t['plate']) . '</dd>'
            . '<dt>Load</dt><dd>' . nl2br(Ui::e((string) $what)) . '</dd>'
            . '<dt>Dates</dt><dd>' . Ui::e(Ui::date($o['start_date'])) . ' – ' . Ui::e(Ui::date($o['end_date'])) . '</dd>'
            . ($o['notes'] ? '<dt>Notes</dt><dd>' . Ui::e($o['notes']) . '</dd>' : '') . '</dl>' . $nav;

        $steps = '<ol class="steps">';
        $done = true;
        foreach (Trips::STEPS[$t['kind']] as $s) {
            if ($s === $next) {
                $done = false;
            }
            $steps .= '<li class="' . ($s === $next ? 'now' : ($done && $t['status'] !== 'planned' ? 'done' : '')) . '">' . Ui::e(Trips::LABELS[$s]) . '</li>';
        }
        $steps .= '</ol>';

        $form = $next === null ? '<div class="callout info">This trip is finished. Thank you!</div>' : self::stepForm($t, $next);
        $body = Ui::card(Dispatch::TRIP_KINDS[$t['kind']] . ' · ' . Ui::date($t['trip_date']), $steps . $form) . Ui::card('Trip', $info) . Ui::card('', OpsScreen::timeline($o));
        Layout::page($actor, 'today', $o['ref'], $body, 'Trips / My trips');
    }

    private static function stepForm(array $t, string $step): string
    {
        $f = '<form class="form phone-form" data-endpoint="trips/' . esc_attr($t['id']) . '/step" novalidate><input type="hidden" name="step" value="' . esc_attr($step) . '">';
        if (in_array($step, ['left_yard', 'back'], true)) {
            $f .= self::odometerField($step === 'back' ? (int) $t['odo_out'] : null);
        }
        if ($step === 'delivered') {
            $f .= self::receiverFields('Name of the person receiving');
        }
        $needPhoto = in_array($step, ['left_yard', 'delivered', 'picked_up'], true);
        $f .= self::photoField($needPhoto ? ($step === 'left_yard' ? 'Photos of the truck and load' : 'Photos on site') : 'Photos', $needPhoto);
        $f .= '<div class="field"><label for="st-note">Note <span class="opt">(optional)</span></label><input id="st-note" name="note" maxlength="500"></div>';
        return $f . '<p class="form-error" role="alert" hidden></p><button class="btn primary big wide" type="submit">' . Ui::e(Trips::LABELS[$step]) . '</button></form>';
    }

    // ----- Driver: expenses --------------------------------------------------------------------

    public static function expenses(Actor $actor): void
    {
        $trucks = Db::rows('SELECT ' . Db::hex('id') . ', plate FROM ' . Db::t('trucks') . ' WHERE status <> \'retired\' ORDER BY plate');
        $orders = $actor->can('cf_drive') ? Trips::forDriver($actor) : [];
        $truckOpts = '<option value="">—</option>';
        foreach ($trucks as $tr) {
            $truckOpts .= '<option value="' . esc_attr($tr['id']) . '">' . Ui::e($tr['plate']) . '</option>';
        }
        $orderOpts = '<option value="">—</option>';
        foreach ($orders as $o) {
            $orderOpts .= '<option value="' . esc_attr($o['order_id']) . '">' . Ui::e($o['ref'] . ' · ' . $o['company_name']) . '</option>';
        }
        $kinds = '';
        foreach (Expenses::KINDS as $k => $l) {
            $kinds .= '<option value="' . $k . '">' . Ui::e($l) . '</option>';
        }
        $form = '<form class="form phone-form" data-endpoint="expenses" novalidate>'
            . '<div class="field"><label for="ex-kind">What for</label><select id="ex-kind" name="kind">' . $kinds . '</select></div>'
            . '<div class="field"><label for="ex-amount">Amount (₱)</label><input id="ex-amount" name="amount" inputmode="decimal" required></div>'
            . '<div class="field"><label for="ex-truck">Truck</label><select id="ex-truck" name="truck_id">' . $truckOpts . '</select></div>'
            . '<div class="field"><label for="ex-liters">Litres <span class="opt">(fuel)</span></label><input id="ex-liters" name="liters" inputmode="decimal"></div>'
            . '<div class="field"><label for="ex-odo">Odometer <span class="opt">(optional)</span></label><input id="ex-odo" name="odometer" inputmode="numeric"></div>'
            . ($orders ? '<div class="field"><label for="ex-order">Order <span class="opt">(optional)</span></label><select id="ex-order" name="order_id">' . $orderOpts . '</select></div>' : '')
            . '<div class="field"><label for="ex-date">Date</label><input id="ex-date" name="spent_on" type="date" value="' . esc_attr(Db::todayManila()->format('Y-m-d')) . '" max="' . esc_attr(Db::todayManila()->format('Y-m-d')) . '"></div>'
            . '<div class="field"><label for="ex-receipt">Receipt photo</label><input id="ex-receipt" name="receipt" type="file" accept="image/*,application/pdf" capture="environment" required></div>'
            . '<div class="field"><label for="ex-note">Note <span class="opt">(optional)</span></label><input id="ex-note" name="note" maxlength="255"></div>'
            . '<p class="form-error" role="alert" hidden></p><button class="btn primary big wide" type="submit">Save expense</button></form>';
        $list = Expenses::list($actor);
        $body = Ui::card('Add an expense', $form) . Ui::card($actor->can('cf_dispatch') ? 'All expenses' : 'My expenses', OpsScreen::expenseTable($list, $actor->can('cf_dispatch')) ?: '');
        Layout::page($actor, 'expenses', 'Fuel & expenses', $body, 'Trips');
    }

    // ----- Yard: loadings ----------------------------------------------------------------------

    public static function loadings(Actor $actor): void
    {
        $id = strtolower(sanitize_key((string) ($_GET['id'] ?? '')));
        if ($id !== '') {
            self::loadForm($actor, $id);
            return;
        }
        $rows = [];
        foreach (Yard::toLoad($actor) as $o) {
            $gaps = Dispatch::gaps($o);
            $action = $o['kind'] === 'truck' ? 'Release truck' : ($o['fulfilment'] === 'pickup' ? 'Load & hand over' : 'Load for delivery');
            $rows[] = [
                '<b>' . Ui::e(Ui::date($o['start_date'])) . '</b>',
                '<span class="mono"><b>' . Ui::e($o['ref']) . '</b></span><br><span class="muted small">' . Ui::e($o['company_name']) . '</span>',
                Ui::e((string) $o['summary']),
                $gaps !== [] ? '<span class="pill warn">Waiting for dispatch: ' . Ui::e(implode(', ', $gaps)) . '</span>'
                    : '<a class="btn primary" href="' . esc_url(add_query_arg('id', $o['id'], Router::url('loadings'))) . '">' . Ui::e($action) . '</a>',
            ];
        }
        Layout::page($actor, 'loadings', 'Loadings', Ui::card('Going out in the next 7 days', Ui::table(['Date', 'Order', 'What', ''], $rows, 'Nothing to load or release.')), 'Yard');
    }

    private static function loadForm(Actor $actor, string $id): void
    {
        $back = Router::url('loadings');
        try {
            $o = self::yardOrder($actor, $id);
        } catch (DomainError $e) {
            Layout::message('Order not found', $e->getMessage(), 404, ['label' => 'Back to loadings', 'href' => $back]);
            return;
        }
        if ($o['kind'] === 'truck') {
            $truck = Dispatch::assignments((string) $o['id'])['trucks'][0] ?? null;
            $f = '<form class="form phone-form" data-endpoint="orders/' . esc_attr($o['id']) . '/release" novalidate>'
                . '<p style="margin:0">Truck <b class="mono">' . Ui::e($truck['plate'] ?? '—') . '</b>, last reading ' . number_format((int) ($truck['odometer_km'] ?? 0)) . ' km.</p>'
                . self::odometerField((int) ($truck['odometer_km'] ?? 0))
                . self::receiverFields('Name of the customer\'s driver')
                . self::photoField('Photos of the truck (all sides, fuel gauge)', true)
                . '<div class="field"><label for="ld-note">Note <span class="opt">(optional)</span></label><input id="ld-note" name="note" maxlength="500"></div>'
                . '<p class="form-error" role="alert" hidden></p><button class="btn primary big wide" type="submit">Release truck</button></form>';
            Layout::page($actor, 'loadings', 'Release · ' . $o['ref'], Ui::card($o['company_name'] . ' · until ' . Ui::date($o['end_date']), $f), 'Yard / Loadings');
            return;
        }
        $rows = '';
        foreach (Yard::lines($o) as $l) {
            if ($l['tracking'] === 'serial') {
                $rows .= '<div class="count-row"><div><b>' . Ui::e($l['description']) . '</b><br><span class="muted small">Units: <span class="mono">' . Ui::e(implode(', ', array_column($l['units'], 'serial')) ?: 'not assigned') . '</span></span></div><span class="num">' . (int) $l['qty'] . '</span></div>';
            } else {
                $rows .= '<div class="count-row"><label for="q-' . $l['id'] . '"><b>' . Ui::e($l['description']) . '</b><br><span class="muted small">Booked ' . number_format((int) $l['qty']) . '</span></label>'
                    . '<input id="q-' . $l['id'] . '" name="qty[' . esc_attr($l['id']) . ']" inputmode="numeric" class="num" placeholder="' . (int) $l['qty'] . '" required></div>';
            }
        }
        $pickup = $o['fulfilment'] === 'pickup';
        $f = '<form class="form phone-form" data-endpoint="orders/' . esc_attr($o['id']) . '/load" novalidate>'
            . '<p class="muted small" style="margin:0">Count each line as it goes on the truck and enter the count.</p>' . $rows
            . ($pickup ? self::receiverFields('Name of the person collecting') : '')
            . self::photoField('Photos of the load', false)
            . '<div class="field"><label for="ld-note">Note <span class="opt">(optional)</span></label><input id="ld-note" name="note" maxlength="500"></div>'
            . '<p class="form-error" role="alert" hidden></p><button class="btn primary big wide" type="submit">' . ($pickup ? 'Loaded and handed over' : 'Loaded') . '</button></form>';
        Layout::page($actor, 'loadings', 'Load · ' . $o['ref'], Ui::card($o['company_name'] . ' · ' . ($pickup ? 'customer pickup' : 'delivery') . ' · ' . Ui::date($o['start_date']), $f), 'Yard / Loadings');
    }

    // ----- Yard: returns -----------------------------------------------------------------------

    public static function returns(Actor $actor): void
    {
        $id = strtolower(sanitize_key((string) ($_GET['id'] ?? '')));
        if ($id !== '') {
            self::returnForm($actor, $id);
            return;
        }
        $today = Db::todayManila()->format('Y-m-d');
        $rows = [];
        foreach (Yard::toReturn($actor) as $o) {
            $ready = $o['kind'] === 'truck' && (int) $o['needs_driver'] ? $o['trip_status'] === 'back'
                : ($o['kind'] === 'equipment' && $o['fulfilment'] === 'delivery' ? $o['trip_status'] === 'back' : true);
            $rows[] = [
                '<b>' . Ui::e(Ui::date($o['end_date'])) . '</b>' . ($o['end_date'] < $today ? '<br><span class="pill bad">Overdue</span>' : ''),
                '<span class="mono"><b>' . Ui::e($o['ref']) . '</b></span><br><span class="muted small">' . Ui::e($o['company_name']) . '</span>',
                Ui::e((string) $o['summary']) . '<br>' . OrdersScreen::statusPill($o['status']),
                $ready ? '<a class="btn primary" href="' . esc_url(add_query_arg('id', $o['id'], Router::url('returns'))) . '">Check in</a>'
                    : '<span class="muted small">Waiting for the driver to be back at the yard</span>',
            ];
        }
        Layout::page($actor, 'returns', 'Returns', Ui::card('Out with customers', Ui::table(['Due back', 'Order', 'What', ''], $rows, 'Nothing is out.')), 'Yard');
    }

    private static function returnForm(Actor $actor, string $id): void
    {
        $back = Router::url('returns');
        try {
            $o = self::yardOrder($actor, $id);
        } catch (DomainError $e) {
            Layout::message('Order not found', $e->getMessage(), 404, ['label' => 'Back to returns', 'href' => $back]);
            return;
        }
        $f = '<form class="form phone-form" data-endpoint="orders/' . esc_attr($o['id']) . '/return" novalidate>';
        if ($o['kind'] === 'truck') {
            $trip = Dispatch::assignments((string) $o['id'])['trips']['rental'] ?? null;
            $f .= '<p style="margin:0">Out at ' . number_format((int) $o['odo_out']) . ' km' . ($trip && $trip['odo_back'] ? ', driver logged ' . number_format((int) $trip['odo_back']) . ' km back at the yard' : '') . '.</p>'
                . self::odometerField((int) $o['odo_out'], $trip['odo_back'] ?? null)
                . '<div class="field"><label for="rt-fuel">Fuel short (litres) <span class="opt">(if any)</span></label><input id="rt-fuel" name="fuel_short_liters" inputmode="decimal"></div>'
                . '<div class="field"><label for="rt-dmg">Damage <span class="opt">(if any)</span></label><textarea id="rt-dmg" name="damage_note" rows="3" maxlength="500"></textarea></div>'
                . '<div class="field check"><label><input type="checkbox" name="to_repair" value="1"> Send the truck to repair</label></div>'
                . self::photoField('Photos of the truck (all sides, fuel gauge, any damage)', true);
        } else {
            foreach (Yard::lines($o) as $l) {
                $out = (int) ($l['out']['qty_good'] ?? $l['qty']);
                if ($l['tracking'] === 'serial') {
                    foreach ($l['units'] as $u) {
                        $f .= '<div class="count-row"><div><b>' . Ui::e($l['description']) . '</b><br><span class="mono small">' . Ui::e($u['serial']) . '</span></div>'
                            . '<select name="unit[' . esc_attr($u['id']) . ']" aria-label="Condition of ' . esc_attr($u['serial']) . '"><option value="">Condition…</option><option value="ok">Good</option><option value="repair">Damaged — to repair</option><option value="missing">Missing</option></select></div>';
                    }
                    continue;
                }
                $f .= '<fieldset class="count-set"><legend><b>' . Ui::e($l['description']) . '</b> · ' . number_format($out) . ' went out</legend><div class="count-3">'
                    . '<div class="field"><label for="g-' . $l['id'] . '">Good</label><input id="g-' . $l['id'] . '" name="good[' . esc_attr($l['id']) . ']" inputmode="numeric" class="num"></div>'
                    . '<div class="field"><label for="d-' . $l['id'] . '">Damaged</label><input id="d-' . $l['id'] . '" name="damaged[' . esc_attr($l['id']) . ']" inputmode="numeric" class="num"></div>'
                    . '<div class="field"><label for="m-' . $l['id'] . '">Missing</label><input id="m-' . $l['id'] . '" name="missing[' . esc_attr($l['id']) . ']" inputmode="numeric" class="num"></div>'
                    . '</div></fieldset>';
            }
            $f .= '<div class="field"><label for="rt-note">Note <span class="opt">(optional)</span></label><textarea id="rt-note" name="note" rows="2" maxlength="500"></textarea></div>'
                . self::photoField('Photos', false);
        }
        $f .= '<p class="form-error" role="alert" hidden></p><button class="btn primary big wide" type="submit">Save return</button></form>';
        $intro = '<p class="muted small" style="margin:0 0 12px">Damaged and missing items are taken out of the available stock and listed on the order for the final bill.</p>';
        Layout::page($actor, 'returns', 'Return · ' . $o['ref'], Ui::card($o['company_name'] . ' · due ' . Ui::date($o['end_date']), ($o['kind'] === 'equipment' ? $intro : '') . $f), 'Yard / Returns');
    }

    // ----- shared fields -----------------------------------------------------------------------

    /** Order for a yard screen: yard staff open any order, without the office's order permissions. @return array<string,mixed> */
    private static function yardOrder(Actor $actor, string $id): array
    {
        $actor->require('cf_count_stock');
        $o = Orders::row($id);
        $o['company_name'] = (string) Db::value(Db::prepare('SELECT name FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $o['company_id']));
        return $o;
    }

    private static function odometerField(?int $min, mixed $suggest = null): string
    {
        return '<div class="field"><label for="f-odo">Odometer (km)</label><input id="f-odo" name="odometer" inputmode="numeric" pattern="[0-9]*" class="num"'
            . ($suggest ? ' value="' . (int) $suggest . '"' : '') . ($min ? ' placeholder="at least ' . number_format($min) . '"' : '') . ' required></div>';
    }

    private static function receiverFields(string $label): string
    {
        return '<div class="field"><label for="f-recv">' . Ui::e($label) . '</label><input id="f-recv" name="receiver_name" maxlength="120" autocomplete="off" required></div>'
            . '<div class="field"><span class="label" id="f-sig-label">Signature</span><div class="sigpad"><canvas width="900" height="300" data-signature="signature" role="img" aria-labelledby="f-sig-label"></canvas>'
            . '<span class="sig-line" aria-hidden="true"></span><button type="button" class="btn" data-sig-clear>Clear</button></div><input type="hidden" name="signature" value=""></div>';
    }

    private static function photoField(string $label, bool $required): string
    {
        return '<div class="field"><label for="f-photos">' . Ui::e($label) . ($required ? '' : ' <span class="opt">(optional)</span>') . '</label>'
            . '<input id="f-photos" name="photos[]" type="file" accept="image/*" multiple' . ($required ? ' required' : '') . '>'
            . '<small class="muted">Take or choose up to 8 photos at once.</small></div>';
    }
}
