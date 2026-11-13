<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Roles\Roles;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Mail;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Special-rate quotations. A quotation is an order in an earlier stage:
 *   quote_requested → quoted ⇄ quote_requested (ask for a better price) → requested (accepted)
 *   | quote_declined | quote_expired.
 * Only companies the Canpas admin ticks may request. Only the Canpas admin (cf_manage_rates) prices,
 * by entering special rates per line; amounts are always worked out here with Pricing.
 * Only the customer's company admin accepts, counters or declines.
 * Order lines keep the normal rates until acceptance; offers live in quote_rounds.
 * A sent quotation holds its items until quote_valid_until.
 */
final class Quotes
{
    /** Peso rate fields that can be specially priced, per line type. */
    public const FIELDS = [
        'truck' => ['starting_fee', 'rate_per_km', 'driver_flat_fee', 'driver_rate_per_km', 'daily_rate'],
        'delivery' => ['starting_fee', 'rate_per_km', 'driver_flat_fee', 'driver_rate_per_km', 'daily_rate'],
        'item' => ['rate_day', 'rate_week', 'rate_month'],
    ];

    public const FIELD_LABELS = [
        'starting_fee' => 'Starting fee', 'rate_per_km' => 'Per km', 'driver_flat_fee' => 'Driver flat fee',
        'driver_rate_per_km' => 'Driver per km', 'daily_rate' => 'Per day',
        'rate_day' => 'Per day', 'rate_week' => 'Per week', 'rate_month' => 'Per month',
    ];

    // ----- Customer: request ----------------------------------------------------------------

    /** @param array<string, mixed> $in booking input plus 'note' */
    public static function request(Actor $actor, string $kind, array $in): string
    {
        $actor->require('cf_book');
        if ($actor->companyId === null || !Companies::specialRates($actor->companyId)) {
            throw new DomainError('not_eligible', 'Special-rate quotations are not available for your account. Book at the normal rates or contact Canpas.');
        }
        $note = self::text($in['note'] ?? '', 1000);
        if (mb_strlen($note) < 3) {
            throw new DomainError('invalid_input', 'Tell Canpas what rate you are hoping for or why (a short note).', ['field' => 'note']);
        }
        $d = $kind === 'truck' ? Orders::quoteTruck($actor, $in) : Orders::quoteEquipment($actor, $in);
        $id = Orders::insertOrder($actor, $d, 'quote_requested', static function (string $id) use ($actor, $note, $d): void {
            self::addRound($id, 0, 'request', $actor->userId, $note, null, (float) $d['total'], (float) $d['total'], (float) $d['deposit'], null);
            Audit::log($actor->companyId, $actor->userId, 'quote.request', 'order', $id, ['total' => $d['total']]);
        });
        $o = Orders::row($id);
        self::mail((string) Settings::get('notify_email'), "Quotation request {$o['ref']} from " . self::companyName($o['company_id']), $o, 'quote_request', [
            'title' => 'New quotation request',
            'paragraphs' => [self::userName($actor->userId) . ' asked for a special rate:', '“' . $note . '”', 'Open the quotation in the portal to set the special rates and send it.'],
            'button' => ['label' => 'Price this quotation', 'href' => self::staffLink($id)],
        ]);
        return $id;
    }

    // ----- Canpas admin: price, send, decline ------------------------------------------------

    /**
     * Work out the special rates and amounts without saving (for the screen's live totals).
     *
     * @param array<string, array<string, mixed>> $rates lineId => field => amount
     * @return array{lines: list<array<string,mixed>>, normal_total: float, total: float, deposit: float, saving: float}
     */
    public static function preview(Actor $actor, string $orderId, array $rates, ?float $pctOff = null): array
    {
        $actor->require('cf_manage_rates');
        $o = Orders::row($orderId);
        return self::compute($o, self::lines($o['id']), $rates, $pctOff);
    }

    /** @param array<string, array<string, mixed>> $rates */
    public static function offer(Actor $actor, string $orderId, array $rates, string $note, ?float $pctOff = null): void
    {
        $actor->require('cf_manage_rates');
        $o = Orders::row($orderId);
        if ($o['status'] !== 'quote_requested') {
            throw new DomainError('wrong_status', 'This quotation is not waiting for a price.');
        }
        $valid = self::validUntil((string) $o['start_date']);
        if ($valid <= Db::now()) {
            throw new DomainError('quote_expired', 'It is too close to the start date to send a quotation. Ask the customer to choose later dates.');
        }
        $c = self::compute($o, self::lines($o['id']), $rates, $pctOff);
        $note = self::text($note, 1000);
        $round = (int) $o['quote_round'] + 1;
        Db::transaction(static function () use ($actor, $o, $c, $note, $round, $valid): void {
            $cur = self::lockOrder($o['id'], ['quote_requested']);
            if ((int) $cur['quote_round'] !== $round - 1) {
                throw new DomainError('wrong_status', 'This quotation was changed a moment ago. Refresh the page.');
            }
            self::assertFree($o);
            self::changed(Db::exec(Db::prepare(
                'UPDATE ' . Db::t('orders') . ' SET status = %s, quote_round = %d, quote_valid_until = %s, status_changed_by = %d, status_changed_at = %s, updated_at = %s WHERE id = UNHEX(%s) AND status = %s',
                'quoted', $round, $valid, $actor->userId, Db::now(), Db::now(), $o['id'], 'quote_requested'
            )));
            self::addRound($o['id'], $round, 'offer', $actor->userId, $note, $c['lines'], $c['normal_total'], $c['total'], $c['deposit'], $valid);
            Audit::log($o['company_id'], $actor->userId, 'quote.offer', 'order', $o['id'], ['round' => $round, 'total' => $c['total'], 'normal_total' => $c['normal_total']]);
        });
        $o = Orders::row($o['id']);
        foreach (self::customerAdmins($o['company_id']) as $email) {
            self::mail($email, "Quotation {$o['ref']} / Q{$round} is ready", $o, 'quote_offer', [
                'title' => 'Your special-rate quotation is ready',
                'summary' => self::summary($o, $c['normal_total'], $c['total'], $valid),
                'paragraphs' => array_values(array_filter([
                    $note !== '' ? 'Note from Canpas: “' . $note . '”' : '',
                    'Accept it in the portal to hold it as your order, or ask for a better price. You can also download the quotation as a PDF.',
                ])),
                'button' => ['label' => 'View the quotation', 'href' => self::customerLink($o['id'])],
            ]);
        }
    }

    /** Customer admin: reason optional. Canpas admin: reason required. */
    public static function decline(Actor $actor, string $orderId, string $reason): void
    {
        $o = Orders::row($orderId);
        $reason = self::text($reason, 255);
        $staff = $actor->can('cf_manage_rates');
        if ($staff) {
            if (!in_array($o['status'], ['quote_requested', 'quoted'], true)) {
                throw new DomainError('wrong_status', 'This quotation is already closed.');
            }
            if (mb_strlen($reason) < 5) {
                throw new DomainError('reason_required', 'Give a short reason (at least 5 characters). It is shown to the customer.', ['field' => 'reason']);
            }
        } else {
            self::assertCustomerAdmin($actor, $o);
            if ($o['status'] !== 'quoted') {
                throw new DomainError('wrong_status', 'This quotation cannot be declined now.');
            }
        }
        Db::transaction(static function () use ($actor, $o, $reason): void {
            self::lockOrder($o['id'], [$o['status']]);
            self::changed(Db::exec(Db::prepare(
                'UPDATE ' . Db::t('orders') . ' SET status = %s, status_reason = %s, status_changed_by = %d, status_changed_at = %s, updated_at = %s WHERE id = UNHEX(%s) AND status = %s',
                'quote_declined', $reason !== '' ? $reason : 'Declined by the customer', $actor->userId, Db::now(), Db::now(), $o['id'], $o['status']
            )));
            self::addRound($o['id'], (int) $o['quote_round'], 'decline', $actor->userId, $reason, null, 0, 0, 0, null);
            Audit::log($o['company_id'], $actor->userId, 'quote.decline', 'order', $o['id'], ['by' => $actor->isStaff() ? 'staff' : 'customer']);
        });
        $paras = [($staff ? 'Canpas' : self::userName($actor->userId)) . ' declined quotation ' . $o['ref'] . '.'];
        if ($reason !== '') {
            $paras[] = 'Reason: “' . $reason . '”';
        }
        $to = $staff ? self::customerAdmins($o['company_id']) : [(string) Settings::get('notify_email')];
        foreach ($to as $email) {
            self::mail($email, "Quotation {$o['ref']} was declined", $o, 'quote_decline', [
                'title' => 'Quotation declined', 'paragraphs' => $paras,
                'button' => ['label' => 'Open the order', 'href' => $staff ? self::customerLink($o['id']) : self::staffLink($o['id'])],
            ]);
        }
    }

    // ----- Customer admin: accept, counter --------------------------------------------------

    public static function accept(Actor $actor, string $orderId): void
    {
        $o = Orders::row($orderId);
        self::assertCustomerAdmin($actor, $o);
        if ($o['status'] !== 'quoted') {
            throw new DomainError('wrong_status', 'This quotation cannot be accepted now.');
        }
        if ((string) $o['quote_valid_until'] <= Db::now()) {
            throw new DomainError('quote_expired', 'This quotation has expired. Ask Canpas for a new one.');
        }
        $offer = self::latestOffer($o['id']);
        if ($offer === null) {
            throw new DomainError('wrong_status', 'This quotation has no price yet.');
        }
        $hold = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify('+' . (int) Settings::get('hold_hours') . ' hours')->format('Y-m-d H:i:s.u');
        Db::transaction(static function () use ($actor, $o, $offer, $hold): void {
            $cur = self::lockOrder($o['id'], ['quoted']);
            if ((string) $cur['quote_valid_until'] <= Db::now() || (int) $cur['quote_round'] !== (int) $o['quote_round']) {
                throw new DomainError('quote_expired', 'This quotation has expired or changed. Refresh the page.');
            }
            self::assertFree($o);
            self::applyOffer($o, $offer);
            self::changed(Db::exec(Db::prepare(
                'UPDATE ' . Db::t('orders') . ' SET status = %s, hold_expires_at = %s, subtotal = %s, total = %s, deposit_amount = %s, status_changed_by = %d, status_changed_at = %s, updated_at = %s WHERE id = UNHEX(%s) AND status = %s',
                'requested', $hold, $offer['total'], $offer['total'], $offer['deposit'], $actor->userId, Db::now(), Db::now(), $o['id'], 'quoted'
            )));
            self::addRound($o['id'], (int) $o['quote_round'], 'accept', $actor->userId, '', null, (float) $offer['normal_total'], (float) $offer['total'], (float) $offer['deposit'], null);
            Audit::log($o['company_id'], $actor->userId, 'quote.accept', 'order', $o['id'], ['round' => (int) $o['quote_round'], 'total' => $offer['total']]);
        });
        $o = Orders::row($o['id']);
        self::mail((string) Settings::get('notify_email'), "Quotation {$o['ref']} accepted by " . self::companyName($o['company_id']), $o, 'quote_accept', [
            'title' => 'Quotation accepted',
            'summary' => self::summary($o, (float) $offer['normal_total'], (float) $offer['total'], null),
            'paragraphs' => [self::userName($actor->userId) . ' accepted the special rates. The order is now held while the customer completes the requirements.'],
            'button' => ['label' => 'Open the order', 'href' => self::staffLink($o['id'])],
        ]);
        foreach (self::customerAdmins($o['company_id']) as $email) {
            self::mail($email, "Order {$o['ref']} is held at your special rates", $o, 'quote_accept', [
                'title' => 'Your order is held at the special rates',
                'summary' => self::summary($o, (float) $offer['normal_total'], (float) $offer['total'], null) + [99 => ['Complete by', self::manila((string) $o['hold_expires_at'])]],
                'paragraphs' => ['Send the ID, licence (if needed), deposit and signed agreement in the portal before the hold ends.'],
                'button' => ['label' => 'Complete the requirements', 'href' => self::customerLink($o['id'])],
            ]);
        }
    }

    public static function counter(Actor $actor, string $orderId, string $note): void
    {
        $o = Orders::row($orderId);
        self::assertCustomerAdmin($actor, $o);
        if ($o['status'] !== 'quoted') {
            throw new DomainError('wrong_status', 'You can ask for a better price only on a sent quotation.');
        }
        $note = self::text($note, 1000);
        if (mb_strlen($note) < 3) {
            throw new DomainError('invalid_input', 'Tell Canpas what price you are hoping for.', ['field' => 'note']);
        }
        // A quotation offered again after acceptance keeps the signed agreement and deposit valid only
        // at the same price, so it can be accepted or declined but not re-negotiated.
        if ((int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('quote_rounds') . ' WHERE order_id = UNHEX(%s) AND kind = %s', $o['id'], 'accept')) > 0) {
            throw new DomainError('wrong_status', 'This quotation was already accepted once, so its price is fixed. Accept it again or decline it.');
        }
        Db::transaction(static function () use ($actor, $o, $note): void {
            self::lockOrder($o['id'], ['quoted']);
            self::changed(Db::exec(Db::prepare(
                'UPDATE ' . Db::t('orders') . ' SET status = %s, quote_valid_until = NULL, status_changed_by = %d, status_changed_at = %s, updated_at = %s WHERE id = UNHEX(%s) AND status = %s',
                'quote_requested', $actor->userId, Db::now(), Db::now(), $o['id'], 'quoted'
            )));
            self::addRound($o['id'], (int) $o['quote_round'], 'counter', $actor->userId, $note, null, 0, 0, 0, null);
            Audit::log($o['company_id'], $actor->userId, 'quote.counter', 'order', $o['id'], ['round' => (int) $o['quote_round']]);
        });
        self::mail((string) Settings::get('notify_email'), "Better price asked on quotation {$o['ref']}", $o, 'quote_counter', [
            'title' => 'The customer asked for a better price',
            'paragraphs' => [self::userName($actor->userId) . ' (' . self::companyName($o['company_id']) . ') wrote:', '“' . $note . '”', 'The items are no longer held. Send a new price from the portal.'],
            'button' => ['label' => 'Price this quotation', 'href' => self::staffLink($o['id'])],
        ]);
    }

    // ----- Timers (hourly) -----------------------------------------------------------------

    /**
     * Sent quotations past their validity, and requests never priced before their start date,
     * expire. Each is recorded (history and audit) and the customer admins are emailed.
     */
    public static function expire(): int
    {
        $rows = Db::rows(Db::prepare('SELECT ' . Db::hex('id') . ', status FROM ' . Db::t('orders') . ' WHERE (status = %s AND quote_valid_until < %s) OR (status = %s AND start_date <= %s)',
            'quoted', Db::now(), 'quote_requested', Db::todayManila()->format('Y-m-d')));
        $n = 0;
        foreach ($rows as $r) {
            try {
                Db::transaction(static function () use ($r): void {
                    $cur = self::lockOrder($r['id'], [$r['status']]);
                    if ($r['status'] === 'quoted' && (string) $cur['quote_valid_until'] >= Db::now()) {
                        throw new DomainError('wrong_status', 'still valid');
                    }
                    self::changed(Db::exec(Db::prepare(
                        'UPDATE ' . Db::t('orders') . ' SET status = %s, status_reason = %s, status_changed_at = %s, updated_at = %s WHERE id = UNHEX(%s) AND status = %s',
                        'quote_expired', $r['status'] === 'quoted' ? 'The quotation was not accepted in time' : 'The rental start date passed before a price was sent',
                        Db::now(), Db::now(), $r['id'], $r['status']
                    )));
                    $o = Orders::row($r['id']);
                    self::addRound($o['id'], (int) $o['quote_round'], 'expired', null, '', null, 0, 0, 0, null);
                    Audit::log($o['company_id'], 0, 'quote.expired', 'order', $o['id'], ['from' => $r['status']]);
                });
            } catch (\Throwable $e) {
                continue;
            }
            $n++;
            try {
                $o = Orders::row($r['id']);
                foreach (self::customerAdmins($o['company_id']) as $email) {
                    self::mail($email, "Quotation {$o['ref']} has expired", $o, 'quote_expired', [
                        'title' => 'Quotation expired',
                        'paragraphs' => [$r['status'] === 'quoted'
                            ? 'The special-rate quotation ' . $o['ref'] . ' was not accepted before it expired, so the items are no longer held. Request a new quotation if you still need them.'
                            : 'Canpas could not send a price for ' . $o['ref'] . ' before the rental start date, so the request has closed. Request a new quotation for other dates.'],
                        'button' => ['label' => 'Open the order', 'href' => self::customerLink($o['id'])],
                    ]);
                }
            } catch (\Throwable $e) {
                error_log('Canpas quote expiry email failed: ' . $e->getMessage());
            }
        }
        return $n;
    }

    /**
     * Accepted quotations whose hold ran out go back to "Quotation sent" at the same special rates
     * when every item is still free and there is time before the start; otherwise they expire.
     * Both sides are emailed either way.
     */
    public static function reofferExpiredHolds(): int
    {
        $rows = Db::rows(Db::prepare('SELECT ' . Db::hex('id') . ' FROM ' . Db::t('orders') . ' WHERE status = %s AND quote_round > 0 AND hold_expires_at < %s', 'requested', Db::now()));
        $n = 0;
        foreach ($rows as $r) {
            $o = Orders::row($r['id']);
            $offer = self::latestOffer($o['id']);
            $valid = self::validUntil((string) $o['start_date']);
            $again = false;
            try {
                Db::transaction(static function () use ($o, $offer, $valid, &$again): void {
                    $cur = self::lockOrder($o['id'], ['requested']);
                    if ((string) $cur['hold_expires_at'] >= Db::now()) {
                        throw new DomainError('wrong_status', 'hold still active');
                    }
                    $again = $offer !== null && $valid > Db::now() && self::isFree($o, true);
                    if ($again) {
                        Db::exec(Db::prepare(
                            'UPDATE ' . Db::t('orders') . ' SET status = %s, status_reason = NULL, quote_valid_until = %s, status_changed_at = %s, updated_at = %s WHERE id = UNHEX(%s) AND status = %s',
                            'quoted', $valid, Db::now(), Db::now(), $o['id'], 'requested'
                        ));
                    } else {
                        Db::exec(Db::prepare(
                            'UPDATE ' . Db::t('orders') . ' SET status = %s, status_reason = %s, status_changed_at = %s, updated_at = %s WHERE id = UNHEX(%s) AND status = %s',
                            'quote_expired', 'The hold ended and the items are no longer free', Db::now(), Db::now(), $o['id'], 'requested'
                        ));
                    }
                    self::addRound($o['id'], (int) $o['quote_round'], $again ? 'reoffer' : 'expired', null, '',
                        $again ? (array) json_decode((string) $offer['lines_json'], true) : null,
                        (float) ($offer['normal_total'] ?? 0), (float) ($offer['total'] ?? 0), (float) ($offer['deposit'] ?? 0), $again ? $valid : null);
                    Audit::log($o['company_id'], 0, $again ? 'quote.reoffer' : 'quote.expired', 'order', $o['id'], []);
                });
            } catch (\Throwable $e) {
                error_log('Canpas quote re-offer failed for ' . $o['ref'] . ': ' . $e->getMessage());
                continue;
            }
            $n++;
            $o = Orders::row($o['id']);
            $subject = $again ? "Quotation {$o['ref']} is open again until " . self::manila($valid) : "Quotation {$o['ref']} has expired";
            $para = $again
                ? 'The hold on order ' . $o['ref'] . ' ended before the requirements were completed. The special-rate quotation is open again until ' . self::manila($valid) . ' (Philippine time) while the items are still free. Accept it again to continue; anything already uploaded is kept.'
                : 'The hold on order ' . $o['ref'] . ' ended before the requirements were completed, and the items are no longer free on those dates, so the special-rate quotation has expired. Request a new quotation for other dates.';
            $mails = array_merge(self::customerAdmins($o['company_id']), [(string) Settings::get('notify_email')]);
            foreach ($mails as $i => $email) {
                $customer = $i < count($mails) - 1;
                self::mail($email, $subject, $o, $again ? 'quote_reoffer' : 'quote_expired', [
                    'title' => $again ? 'Quotation open again' : 'Quotation expired',
                    'summary' => self::summary($o, (float) ($offer['normal_total'] ?? 0), (float) ($offer['total'] ?? 0), $again ? $valid : null),
                    'paragraphs' => [$para],
                    'button' => ['label' => 'Open the order', 'href' => $customer ? self::customerLink($o['id']) : self::staffLink($o['id'])],
                ]);
            }
        }
        return $n;
    }

    // ----- Reading ---------------------------------------------------------------------------

    /** @return list<array<string, mixed>> every round, oldest first, with the person's name */
    public static function rounds(Actor $actor, string $orderId): array
    {
        $o = Orders::row($orderId);
        if (!$actor->can('cf_manage_orders')) {
            Orders::assertCustomerAccess($actor, $o);
        }
        $rows = Db::rows(Db::prepare('SELECT round, kind, by_user, note, lines_json, normal_total, total, deposit, valid_until, created_at FROM ' . Db::t('quote_rounds') . ' WHERE order_id = UNHEX(%s) ORDER BY created_at, round', $o['id']));
        foreach ($rows as &$r) {
            $r['by_name'] = $r['by_user'] ? self::userName((int) $r['by_user']) : 'Canpas system';
            $r['lines'] = $r['lines_json'] ? (array) json_decode((string) $r['lines_json'], true) : [];
        }
        return $rows;
    }

    /** @return array<string, mixed>|null the latest offer (or re-offer) round */
    public static function latestOffer(string $orderId): ?array
    {
        return Db::row(Db::prepare('SELECT round, lines_json, normal_total, total, deposit, valid_until, note FROM ' . Db::t('quote_rounds') . ' WHERE order_id = UNHEX(%s) AND kind IN (%s, %s) ORDER BY created_at DESC LIMIT 1', $orderId, 'offer', 'reoffer'));
    }

    public static function isCustomerAdmin(Actor $actor, array $o): bool
    {
        return $actor->role === Roles::CUSTOMER_ADMIN && $actor->companyId === $o['company_id'];
    }

    /**
     * When a quotation sent now stops holding: now + the validity setting, but never past the end of
     * the day before the rental starts (Philippine time). UTC, 'Y-m-d H:i:s.u'.
     */
    public static function validUntil(string $startDate, ?\DateTimeImmutable $now = null): string
    {
        $utc = new \DateTimeZone('UTC');
        $now ??= new \DateTimeImmutable('now', $utc);
        $byDays = $now->setTimezone($utc)->modify('+' . max(1, (int) Settings::get('quote_valid_days')) . ' days');
        $cap = (new \DateTimeImmutable($startDate . ' 23:59:59', new \DateTimeZone('Asia/Manila')))->modify('-1 day')->setTimezone($utc);
        return ($byDays < $cap ? $byDays : $cap)->format('Y-m-d H:i:s.u');
    }

    // ----- Print-ready quotation ------------------------------------------------------------

    /** The quotation for one priced round as a letterhead PDF. Opening it is logged. */
    public static function pdf(Actor $actor, string $orderId, int $round): string
    {
        $o = Orders::row($orderId);
        if (!$actor->can('cf_manage_orders')) {
            Orders::assertCustomerAccess($actor, $o);
        }
        $r = Db::row(Db::prepare('SELECT round, kind, by_user, note, lines_json, normal_total, total, deposit, valid_until, created_at FROM ' . Db::t('quote_rounds')
            . ' WHERE order_id = UNHEX(%s) AND round = %d AND kind IN (%s, %s) ORDER BY created_at DESC LIMIT 1', $o['id'], $round, 'offer', 'reoffer'));
        if ($r === null) {
            throw new DomainError('not_found', 'That quotation was not found.');
        }
        Documents::logView($actor, 'quote', $o['id'], 'Q' . $round);
        $company = Db::row(Db::prepare('SELECT name, address, tin FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $o['company_id'])) ?? [];
        $num = static fn($v): string => number_format((float) $v, 2);
        $sent = (new \DateTimeImmutable((string) $r['created_at'], new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Manila'));
        $no = $o['ref'] . ' / Q' . $round;
        $pdf = new \Canpas\Fleet\Support\Pdf('Quotation ' . $no, 'Canpas · Quotation ' . $no . ' · Prices valid until ' . ($r['valid_until'] ? self::manila((string) $r['valid_until']) : '—') . ', subject to availability.');
        $pdf->letterhead('QUOTATION', $no, 'Date ' . $sent->format('j F Y'),
            array_values(array_filter([(string) ($company['name'] ?? ''), (string) ($company['address'] ?? ''), !empty($company['tin']) ? 'TIN ' . $company['tin'] : ''])));
        $where = $o['fulfilment'] === 'pickup' ? 'Pick-up and return at the Canpas yard' : (string) $o['site_address'];
        $pdf->paragraph('Rental: ' . (new \DateTimeImmutable((string) $o['start_date']))->format('j M Y') . ' to ' . (new \DateTimeImmutable((string) $o['end_date']))->format('j M Y')
            . ' (' . (int) $o['days'] . ' day' . ((int) $o['days'] === 1 ? '' : 's') . ')' . ($where !== '' ? ' · ' . $where : '')
            . ($o['distance_km'] !== null ? ' · ' . number_format((float) $o['distance_km'], 1) . ' km round trip' : ''), 9.5, false, 2);
        if ($r['valid_until']) {
            $pdf->paragraph('Valid until ' . self::manila((string) $r['valid_until']) . ' (Philippine time)', 10, true, 6);
        }
        $pdf->rule();
        $pdf->row([['Description', 0, 220, 'L'], ['Qty', 220, 35, 'R'], ['Normal rate', 260, 70, 'R'], ['Special rate', 335, 70, 'R'], ['Amount', 410, 73, 'R']], 9, true);
        $pdf->rule();
        foreach ((array) json_decode((string) $r['lines_json'], true) as $l) {
            $qty = rtrim(rtrim(number_format((float) $l['qty'], 2), '0'), '.');
            $pdf->row([[(string) $l['description'], 0, 220, 'L'], [$qty, 220, 35, 'R'], ['', 260, 70, 'R'], ['', 335, 70, 'R'], [$num($l['amount']), 410, 73, 'R']], 9.5, true);
            foreach ((array) ($l['special'] ?? []) as $f => $v) {
                $n = (float) ($l['normal'][$f] ?? 0);
                if ($n <= 0 && (float) $v <= 0) {
                    continue;
                }
                $pdf->row([['   ' . (self::FIELD_LABELS[$f] ?? $f), 0, 220, 'L'], ['', 220, 35, 'R'], [$num($n), 260, 70, 'R'], [$num($v), 335, 70, 'R'], ['', 410, 73, 'R']], 8.5);
            }
            if ((float) $l['normal_amount'] > (float) $l['amount']) {
                $pdf->row([['   Normal amount ' . $num($l['normal_amount']), 0, 400, 'L']], 8);
            }
        }
        $pdf->rule();
        $sum = [['Normal price', $r['normal_total'], false], ['Special price', $r['total'], true]];
        if ((float) $r['normal_total'] > (float) $r['total']) {
            $sum[] = ['You save', (float) $r['normal_total'] - (float) $r['total'], false];
        }
        $sum[] = ['Deposit to hold the order', $r['deposit'], false];
        foreach ($sum as [$label, $v, $bold]) {
            $pdf->row([[$label, 260, 145, 'R'], ['PHP ' . $num($v), 410, 73, 'R']], $bold ? 10.5 : 9.5, $bold);
        }
        $pdf->space(10);
        if ($r['note']) {
            $pdf->paragraph('Note from Canpas', 9, true, 0);
            $pdf->paragraph((string) $r['note'], 9.5, false, 8);
        }
        $pdf->paragraph('Terms', 9, true, 0);
        $pdf->paragraph('These special rates apply to this rental only and are valid until the date above, subject to availability. Accept the quotation in the Canpas portal to hold the order; the ID, licence (where needed), deposit and signed rental agreement are then required before Canpas confirms it. Late returns, extra kilometres, damage and missing items are charged at the rates in this quotation.', 8.5, false, 10);
        $by = $r['by_user'] ? self::userName((int) $r['by_user']) : 'Canpas';
        $pdf->paragraph('Prepared by ' . $by . ' for ' . (string) Settings::get('company_legal_name'), 9, false, 0);
        return $pdf->output();
    }

    // ----- Pricing internals -------------------------------------------------------------------

    /** @return list<array<string, mixed>> order lines with hex ids */
    public static function lines(string $orderId, bool $forUpdate = false): array
    {
        return Db::rows(Db::prepare('SELECT ' . Db::hex('id') . ', ' . Db::hex('truck_type_id') . ', ' . Db::hex('item_id') . ', line_type, description, qty, charged_days, unit_price, amount, deposit, rates_json FROM ' . Db::t('order_lines') . ' WHERE order_id = UNHEX(%s) ORDER BY sort' . ($forUpdate ? ' FOR UPDATE' : ''), $orderId));
    }

    /** @return array<string, mixed> the line's normal (catalog-at-booking) rates */
    public static function normalRates(array $line): array
    {
        $r = (array) json_decode((string) $line['rates_json'], true);
        return isset($r['normal_rates']) && is_array($r['normal_rates']) ? $r['normal_rates'] : $r;
    }

    /**
     * @param list<array<string,mixed>> $lines
     * @param array<string, array<string, mixed>> $input
     * @return array{lines: list<array<string,mixed>>, normal_total: float, total: float, deposit: float, saving: float}
     */
    private static function compute(array $o, array $lines, array $input, ?float $pctOff): array
    {
        if ($pctOff !== null && ($pctOff < 0 || $pctOff > 100)) {
            throw new DomainError('invalid_input', 'The discount must be between 0 and 100%.', ['field' => 'pct_off']);
        }
        $input = array_change_key_case($input, CASE_LOWER);
        $out = [];
        $normalTotal = $total = $deposit = 0.0;
        foreach ($lines as $l) {
            $normal = self::normalRates($l);
            $special = $normal;
            foreach (self::FIELDS[$l['line_type']] ?? [] as $f) {
                $n = round((float) ($normal[$f] ?? 0), 2);
                $raw = $input[$l['id']][$f] ?? null;
                if ($raw !== null && trim((string) $raw) !== '') {
                    $raw = trim((string) $raw);
                    if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
                        throw new DomainError('invalid_input', self::FIELD_LABELS[$f] . ' for ' . $l['description'] . ' must be a number like 1250 or 1250.50.', ['field' => "rate[{$l['id']}][{$f}]"]);
                    }
                    $v = (float) $raw;
                } elseif ($pctOff !== null) {
                    $v = round($n * (1 - $pctOff / 100), 2);
                } else {
                    $v = $n;
                }
                if ($v > $n + 0.001) {
                    throw new DomainError('rate_too_high', self::FIELD_LABELS[$f] . ' for ' . $l['description'] . ' cannot be above the normal rate (' . number_format($n, 2) . ').', ['field' => "rate[{$l['id']}][{$f}]"]);
                }
                $special[$f] = $v;
            }
            $np = self::price($o, $l, $normal);
            $sp = self::price($o, $l, $special);
            $out[] = [
                'id' => $l['id'], 'line_type' => $l['line_type'], 'description' => $l['description'], 'qty' => (float) $l['qty'],
                'normal' => array_intersect_key($normal, array_flip(self::FIELDS[$l['line_type']] ?? [])),
                'special' => array_intersect_key($special, array_flip(self::FIELDS[$l['line_type']] ?? [])),
                'normal_amount' => $np['amount'], 'amount' => $sp['amount'], 'unit_price' => $sp['unit_price'],
                'charged_days' => $sp['charged_days'], 'deposit' => $sp['deposit'], 'breakdown' => $sp['breakdown'],
            ];
            $normalTotal += $np['amount'];
            $total += $sp['amount'];
            $deposit += $sp['deposit'];
        }
        return ['lines' => $out, 'normal_total' => round($normalTotal, 2), 'total' => round($total, 2), 'deposit' => round($deposit, 2), 'saving' => round($normalTotal - $total, 2)];
    }

    /** @return array{amount: float, unit_price: float, charged_days: int, deposit: float, breakdown: array<string,mixed>} */
    private static function price(array $o, array $l, array $rates): array
    {
        $basis = (string) ($rates['driver_km_basis'] ?? Settings::get('driver_km_basis'));
        $km = (float) ($o['distance_km'] ?? 0);
        if ($l['line_type'] === 'item') {
            $e = Pricing::equipment($rates, (int) $l['qty'], (int) $o['days']);
            return ['amount' => $e['amount'], 'unit_price' => $e['unit_price'], 'charged_days' => $e['charged_days'],
                'deposit' => Pricing::deposit((string) ($rates['deposit_mode'] ?? 'percent'), (float) ($rates['deposit_value'] ?? 0), $e['amount']),
                'breakdown' => $e + ['period' => Pricing::periodLabel($e)]];
        }
        if ($l['line_type'] === 'delivery') {
            $t = Pricing::truckTrip($rates, $km, true, 0, $basis);
            $qty = (int) $l['qty'];
            return ['amount' => round($t['total'] * $qty, 2), 'unit_price' => $t['total'], 'charged_days' => 0, 'deposit' => 0.0, 'breakdown' => $t];
        }
        $t = Pricing::truckTrip($rates, $km, (int) $o['needs_driver'] === 1, (int) $o['days'], $basis);
        return ['amount' => $t['total'], 'unit_price' => $t['total'], 'charged_days' => (int) $o['days'],
            'deposit' => Pricing::deposit((string) ($rates['deposit_mode'] ?? 'percent'), (float) ($rates['deposit_value'] ?? 0), $t['total']),
            'breakdown' => $t];
    }

    /** Write the offer's special rates onto the order lines (normal rates kept alongside for display). */
    private static function applyOffer(array $o, array $offer): void
    {
        $byId = [];
        foreach ((array) json_decode((string) $offer['lines_json'], true) as $l) {
            $byId[strtolower((string) $l['id'])] = $l;
        }
        foreach (self::lines($o['id']) as $line) {
            $l = $byId[$line['id']] ?? null;
            if ($l === null) {
                throw new DomainError('wrong_status', 'The quotation no longer matches the order. Ask Canpas for a new quotation.');
            }
            $normal = self::normalRates($line);
            $rates = array_merge($normal, (array) $l['special'], ['normal_rates' => $normal]);
            Db::update('order_lines', [
                'rates_json' => wp_json_encode($rates), 'breakdown_json' => wp_json_encode($l['breakdown'] ?? []),
                'unit_price' => number_format((float) $l['unit_price'], 2, '.', ''), 'amount' => number_format((float) $l['amount'], 2, '.', ''),
                'deposit' => number_format((float) $l['deposit'], 2, '.', ''), 'charged_days' => (int) $l['charged_days'],
            ], ['id' => $line['id']], ['id']);
        }
    }

    /** Lock the catalog rows and refuse if any line is not free for the order's dates. */
    private static function assertFree(array $o): void
    {
        if (!self::isFree($o, true)) {
            throw new DomainError('not_available', 'Some items in this quotation are no longer free for these dates.');
        }
    }

    private static function isFree(array $o, bool $lock = false): bool
    {
        $lines = self::lines($o['id'], $lock);
        if ($lock) {
            // Take every lock before the first plain read, so the availability count below sees
            // bookings committed while we were waiting.
            foreach ($lines as $l) {
                if ($l['line_type'] === 'truck') {
                    Db::value(Db::prepare('SELECT id FROM ' . Db::t('truck_types') . ' WHERE id = UNHEX(%s) FOR UPDATE', $l['truck_type_id']));
                } elseif ($l['line_type'] === 'item') {
                    Db::value(Db::prepare('SELECT id FROM ' . Db::t('equipment_items') . ' WHERE id = UNHEX(%s) FOR UPDATE', $l['item_id']));
                }
            }
        }
        foreach ($lines as $l) {
            if ($l['line_type'] === 'truck') {
                $free = Orders::truckTypeCapacity($l['truck_type_id']) - Orders::peakBooked('truck_type_id', $l['truck_type_id'], (string) $o['start_date'], (string) $o['end_date'], 'truck', $o['id']);
                if ($free < 1) {
                    return false;
                }
            } elseif ($l['line_type'] === 'item') {
                $free = Orders::itemCapacity($l['item_id']) - Orders::peakBooked('item_id', $l['item_id'], (string) $o['start_date'], (string) $o['end_date'], 'item', $o['id']);
                if ($free < (int) $l['qty']) {
                    return false;
                }
            }
        }
        return true;
    }

    // ----- Small helpers ---------------------------------------------------------------------

    /**
     * First statement of every quotation transaction: lock the order row (a locking read, so it
     * also sees changes committed a moment ago) and re-check its status there.
     *
     * @param list<string> $statuses
     * @return array<string, mixed>
     */
    private static function lockOrder(string $orderId, array $statuses): array
    {
        $row = Db::row(Db::prepare('SELECT status, quote_round, quote_valid_until, hold_expires_at FROM ' . Db::t('orders') . ' WHERE id = UNHEX(%s) FOR UPDATE', $orderId));
        if ($row === null || !in_array($row['status'], $statuses, true)) {
            throw new DomainError('wrong_status', 'This quotation was changed a moment ago. Refresh the page.');
        }
        return $row;
    }

    private static function changed(int $rows): void
    {
        if ($rows !== 1) {
            throw new DomainError('wrong_status', 'This quotation was changed a moment ago. Refresh the page.');
        }
    }

    private static function assertCustomerAdmin(Actor $actor, array $o): void
    {
        $actor->require('cf_book');
        Orders::assertCustomerAccess($actor, $o);
        if (!self::isCustomerAdmin($actor, $o)) {
            throw new DomainError('forbidden', 'Only your company admin can accept, decline or ask for a better price.');
        }
    }

    /** @param list<array<string,mixed>>|null $lines */
    private static function addRound(string $orderId, int $round, string $kind, ?int $by, string $note, ?array $lines, float $normalTotal, float $total, float $deposit, ?string $validUntil): void
    {
        Db::insert('quote_rounds', [
            'id' => Uuid::v7(), 'order_id' => $orderId, 'round' => $round, 'kind' => $kind, 'by_user' => $by,
            'note' => $note !== '' ? $note : null, 'lines_json' => $lines !== null ? wp_json_encode($lines) : null,
            'normal_total' => number_format($normalTotal, 2, '.', ''), 'total' => number_format($total, 2, '.', ''), 'deposit' => number_format($deposit, 2, '.', ''),
            'valid_until' => $validUntil, 'created_at' => Db::now(),
        ], ['id', 'order_id']);
    }

    /** @return list<string> emails of the company's active customer admins */
    private static function customerAdmins(string $companyId): array
    {
        $out = [];
        foreach (Db::rows(Db::prepare('SELECT user_id FROM ' . Db::t('memberships') . ' WHERE company_id = UNHEX(%s) AND role = %s AND status = %s', $companyId, Roles::CUSTOMER_ADMIN, 'active')) as $r) {
            $u = get_userdata((int) $r['user_id']);
            if ($u && is_email($u->user_email)) {
                $out[] = $u->user_email;
            }
        }
        return $out;
    }

    /** @return list<array{0:string,1:string}> */
    private static function summary(array $o, float $normal, float $special, ?string $validUntil): array
    {
        $rows = [
            ['Quotation', $o['ref'] . ' / Q' . (int) $o['quote_round']],
            ['Company', self::companyName($o['company_id'])],
            ['Rental', (new \DateTimeImmutable((string) $o['start_date']))->format('j M Y') . ' to ' . (new \DateTimeImmutable((string) $o['end_date']))->format('j M Y') . ' (' . (int) $o['days'] . ' days)'],
        ];
        if ($normal > 0) {
            $rows[] = ['Normal price', '₱' . number_format($normal, 2)];
            $rows[] = ['Special price', '₱' . number_format($special, 2) . ($normal > $special ? '  (you save ₱' . number_format($normal - $special, 2) . ')' : '')];
        }
        if ($validUntil !== null) {
            $rows[] = ['Valid until', self::manila($validUntil)];
        }
        return $rows;
    }

    /** @param array{title?:string, summary?:list<array{0:string,1:string}>, paragraphs:list<string>, button?:array{label:string,href:string}} $m */
    private static function mail(string $to, string $subject, array $o, string $kind, array $m): void
    {
        Mail::send($to, $subject, $m['paragraphs'], $m['button'] ?? null, [
            'title' => $m['title'] ?? '', 'summary' => array_values($m['summary'] ?? []),
            'order_id' => $o['id'], 'company_id' => $o['company_id'], 'kind' => $kind,
        ]);
    }

    public static function manila(string $utc): string
    {
        return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone(new \DateTimeZone('Asia/Manila'))->format('j M Y, g:i a');
    }

    private static function companyName(string $companyId): string
    {
        return (string) Db::value(Db::prepare('SELECT name FROM ' . Db::t('companies') . ' WHERE id = UNHEX(%s)', $companyId));
    }

    private static function userName(int $userId): string
    {
        $u = get_userdata($userId);
        return $u ? $u->display_name : 'Someone';
    }

    private static function customerLink(string $orderId): string
    {
        return add_query_arg('id', $orderId, Router::url('my-orders'));
    }

    private static function staffLink(string $orderId): string
    {
        return add_query_arg('id', $orderId, Router::url('orders'));
    }

    private static function text(mixed $v, int $max): string
    {
        return mb_substr(sanitize_textarea_field(wp_unslash((string) $v)), 0, $max);
    }
}
