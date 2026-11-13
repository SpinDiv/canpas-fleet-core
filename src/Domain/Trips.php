<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Files;
use Canpas\Fleet\Support\Mail;
use Canpas\Fleet\Support\Signature;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Actor;

/**
 * The driver's steps on the road, in order:
 *
 *   rental (truck with driver):  Leaving yard → Arrived on site → Delivered → Picked up → Back at yard
 *   delivery (equipment):        Leaving yard → Arrived on site → Delivered → Back at yard
 *   pickup (equipment):          Leaving yard → Arrived on site → Picked up → Back at yard
 *
 * Leaving and coming back need the odometer; leaving, delivering and picking up need photos;
 * delivering needs the receiver's name and signature. Each step is a trip event, and moves the order:
 * leaving yard → on the way, delivered → on site, picked up → being returned.
 * Every step, photo, signature and private file here is also governed by canSee().
 */
final class Trips
{
    public const STEPS = [
        'rental' => ['left_yard', 'on_site', 'delivered', 'picked_up', 'back'],
        'delivery' => ['left_yard', 'on_site', 'delivered', 'back'],
        'pickup' => ['left_yard', 'on_site', 'picked_up', 'back'],
    ];

    public const LABELS = [
        'planned' => 'Not started',
        'left_yard' => 'Leaving yard',
        'on_site' => 'Arrived on site',
        'delivered' => 'Delivered',
        'picked_up' => 'Picked up',
        'back' => 'Back at yard',
        // yard events
        'loaded' => 'Loaded at the yard',
        'released' => 'Released at the yard',
        'returned' => 'Return inspection',
    ];

    private const NEEDS_PHOTO = ['left_yard', 'delivered', 'picked_up'];

    /** Next step for a trip, or null when finished. */
    public static function next(array $trip): ?string
    {
        $steps = self::STEPS[$trip['kind']];
        if ($trip['status'] === 'planned') {
            return $steps[0];
        }
        $i = array_search($trip['status'], $steps, true);
        return $i === false || $i + 1 >= count($steps) ? null : $steps[$i + 1];
    }

    /**
     * Record a step.
     *
     * @param array<string,mixed> $in odometer, note, receiver_name, signature
     * @param array<string,mixed> $files photos[]
     */
    public static function step(Actor $actor, string $tripId, string $step, array $in, array $files): void
    {
        $trip = self::row($tripId);
        self::assertDriverOf($actor, $trip);
        $o = Orders::row($trip['order_id']);
        $next = self::next($trip);
        if ($next === null) {
            throw new DomainError('trip_done', 'This trip is already finished.');
        }
        if ($step !== $next) {
            throw new DomainError('wrong_step', 'Next step is: ' . self::LABELS[$next] . '.');
        }
        if (!in_array($o['status'], ['confirmed', 'dispatched', 'on_site', 'returning'], true)) {
            throw new DomainError('wrong_status', 'This order is not ready to go out (' . (Orders::STATUS_LABELS[$o['status']] ?? $o['status']) . ').');
        }
        if ($step === 'left_yard' && $trip['kind'] === 'delivery' && empty($o['loaded_at'])) {
            throw new DomainError('not_loaded', 'The yard has not loaded this order yet.');
        }
        if ($step === 'left_yard' && $trip['kind'] === 'pickup' && $o['status'] !== 'on_site') {
            throw new DomainError('wrong_status', 'The equipment has not been delivered yet.');
        }
        $truckOdo = $trip['truck_id'] ? (int) Db::value(Db::prepare('SELECT odometer_km FROM ' . Db::t('trucks') . ' WHERE id = UNHEX(%s)', $trip['truck_id'])) : 0;
        $odo = null;
        if (in_array($step, ['left_yard', 'back'], true)) {
            $odo = self::odometer($in, $step === 'back' ? (int) $trip['odo_out'] : $truckOdo);
        }
        $receiver = '';
        if ($step === 'delivered') {
            $receiver = sanitize_text_field(wp_unslash((string) ($in['receiver_name'] ?? '')));
            if (mb_strlen($receiver) < 3) {
                throw new DomainError('invalid_input', 'Enter the name of the person receiving.', ['field' => 'receiver_name']);
            }
            Signature::image((string) ($in['signature'] ?? '')); // validate before anything is saved
        }
        $note = mb_substr(sanitize_text_field(wp_unslash((string) ($in['note'] ?? ''))), 0, 500);
        $photos = Files::storeMany($files['photos'] ?? null, 'trips/' . $o['company_id']);
        if ($photos === [] && in_array($step, self::NEEDS_PHOTO, true)) {
            throw new DomainError('photos_required', 'Take at least one photo.', ['field' => 'photos']);
        }
        $sig = $step === 'delivered' ? Signature::store((string) $in['signature'], 'trips/' . $o['company_id']) : null;

        try {
            Db::transaction(static function () use ($actor, $trip, $o, $step, $odo, $receiver, $sig, $note, $photos): void {
                $now = Db::row(Db::prepare('SELECT status FROM ' . Db::t('trips') . ' WHERE id = UNHEX(%s) FOR UPDATE', $trip['id']));
                if ($now === null || $now['status'] !== $trip['status']) {
                    throw new DomainError('stale', 'This trip was just updated. Reload the page.');
                }
                $set = ['status' => $step, 'updated_at' => Db::now()];
                if ($step === 'left_yard') {
                    $set['odo_out'] = $odo;
                } elseif ($step === 'back') {
                    $set['odo_back'] = $odo;
                } elseif ($step === 'delivered') {
                    $set += ['receiver_name' => $receiver, 'receiver_signature' => $sig, 'receiver_at' => Db::now()];
                }
                Db::update('trips', $set, ['id' => $trip['id']], ['id']);
                self::event($actor, $o['id'], $trip['id'], $step, $odo, $note, $photos);

                // The order follows the trip.
                $orderSet = match (true) {
                    $step === 'left_yard' && $trip['kind'] !== 'pickup' => ['status' => 'dispatched', 'released_at' => Db::now()] + ($trip['kind'] === 'rental' ? ['odo_out' => $odo] : []),
                    $step === 'delivered' => ['status' => 'on_site'],
                    $step === 'picked_up' => ['status' => 'returning'],
                    default => [],
                };
                if ($orderSet !== []) {
                    Db::update('orders', $orderSet + ['status_changed_by' => $actor->userId, 'status_changed_at' => Db::now(), 'updated_at' => Db::now()], ['id' => $o['id']], ['id']);
                }
                if ($odo !== null && $trip['truck_id']) {
                    Db::exec(Db::prepare('UPDATE ' . Db::t('trucks') . ' SET odometer_km = GREATEST(odometer_km, %d) WHERE id = UNHEX(%s)', $odo, $trip['truck_id']));
                }
                Audit::log($o['company_id'], $actor->userId, 'trip.' . $step, 'order', $o['id'], ['trip' => $trip['kind']] + ($odo !== null ? ['odometer' => $odo] : []));
            });
        } catch (\Throwable $e) {
            foreach ($photos as $p) {
                Files::delete($p);
            }
            Files::delete($sig);
            throw $e;
        }
        if ($step === 'delivered') {
            self::mailCustomer($o, "Order {$o['ref']} was delivered", [
                "Order {$o['ref']} was delivered to your site and received by {$receiver}.",
                $trip['kind'] === 'rental' ? 'The truck stays with you until ' . $o['end_date'] . '.' : 'Canpas will collect the equipment on ' . $o['end_date'] . '.',
            ]);
        }
    }

    /** Trips for a driver: unfinished ones and the next 7 days. @return list<array<string,mixed>> */
    public static function forDriver(Actor $actor): array
    {
        $actor->require('cf_drive');
        $until = Db::todayManila()->modify('+7 days')->format('Y-m-d');
        return Db::rows(Db::prepare(
            'SELECT tr.*, ' . Db::hex('tr.id', 'id') . ', ' . Db::hex('tr.order_id', 'order_id') . ', t.plate, o.ref, o.site_address, o.site_lat, o.site_lng, o.start_date, o.end_date, o.status AS order_status, c.name AS company_name
             FROM ' . Db::t('trips') . ' tr JOIN ' . Db::t('orders') . ' o ON o.id = tr.order_id JOIN ' . Db::t('companies') . ' c ON c.id = o.company_id
             LEFT JOIN ' . Db::t('trucks') . ' t ON t.id = tr.truck_id
             WHERE tr.driver_user_id = %d AND tr.status NOT IN (%s, %s) AND o.status IN (%s, %s, %s, %s)
               AND (tr.status <> %s OR tr.trip_date <= %s) ORDER BY tr.trip_date, o.ref',
            $actor->userId, 'back', 'cancelled', 'confirmed', 'dispatched', 'on_site', 'returning', 'planned', $until
        ));
    }

    /** @return array<string,mixed> trip with order and truck, after the access check */
    public static function get(Actor $actor, string $tripId): array
    {
        $trip = self::row($tripId);
        self::assertDriverOf($actor, $trip);
        return $trip;
    }

    /** Everything that happened to an order on the road and in the yard. @return list<array<string,mixed>> */
    public static function timeline(string $orderId): array
    {
        $rows = Db::rows(Db::prepare(
            'SELECT e.*, ' . Db::hex('e.id', 'id') . ', tr.kind AS trip_kind FROM ' . Db::t('trip_events') . ' e LEFT JOIN ' . Db::t('trips') . ' tr ON tr.id = e.trip_id
             WHERE e.order_id = UNHEX(%s) ORDER BY e.created_at', $orderId
        ));
        foreach ($rows as &$r) {
            $u = get_userdata((int) $r['user_id']);
            $r['user_name'] = $u ? $u->display_name : '';
            $r['photo_list'] = $r['photos'] ? (array) json_decode((string) $r['photos'], true) : [];
        }
        return $rows;
    }

    // ----- Private files -----------------------------------------------------------------------

    /** Staff, the trip's driver, or the customer of the order. */
    public static function canSee(Actor $actor, array $o): void
    {
        if ($actor->can('cf_manage_orders') || $actor->can('cf_dispatch') || $actor->can('cf_count_stock')) {
            return;
        }
        if ($actor->can('cf_drive')) {
            $mine = (int) Db::value(Db::prepare('SELECT COUNT(*) FROM ' . Db::t('trips') . ' WHERE order_id = UNHEX(%s) AND driver_user_id = %d', $o['id'], $actor->userId));
            if ($mine > 0) {
                return;
            }
            throw new DomainError('not_found', 'Not found.');
        }
        Orders::assertCustomerAccess($actor, $o);
    }

    /** @return array{path:string,name:string} */
    public static function photo(Actor $actor, string $eventId, int $n): array
    {
        $eventId = Uuid::assertHex($eventId, 'photo');
        $e = Db::row(Db::prepare('SELECT ' . Db::hex('order_id') . ', photos FROM ' . Db::t('trip_events') . ' WHERE id = UNHEX(%s)', $eventId));
        if ($e === null) {
            throw new DomainError('not_found', 'Photo not found.');
        }
        self::canSee($actor, Orders::row($e['order_id']));
        $list = $e['photos'] ? (array) json_decode((string) $e['photos'], true) : [];
        if (!isset($list[$n])) {
            throw new DomainError('not_found', 'Photo not found.');
        }
        Documents::logView($actor, 'photo', $eventId, (string) $n);
        return ['path' => (string) $list[$n], 'name' => 'photo-' . ($n + 1) . '.' . pathinfo((string) $list[$n], PATHINFO_EXTENSION)];
    }

    /** Receiver signature: on a trip (?sig=trip id) or the yard handover on the order (?sig=order id). @return array{path:string,name:string} */
    public static function signature(Actor $actor, string $id): array
    {
        $id = Uuid::assertHex($id, 'signature');
        $trip = Db::row(Db::prepare('SELECT ' . Db::hex('order_id') . ', receiver_signature FROM ' . Db::t('trips') . ' WHERE id = UNHEX(%s)', $id));
        if ($trip !== null) {
            $o = Orders::row($trip['order_id']);
            $path = $trip['receiver_signature'];
        } else {
            $o = Orders::row($id);
            $path = $o['handover_signature'];
        }
        self::canSee($actor, $o);
        if (empty($path)) {
            throw new DomainError('not_found', 'No signature on file.');
        }
        Documents::logView($actor, 'signature', $id);
        return ['path' => (string) $path, 'name' => $o['ref'] . '-signature.png'];
    }

    // ----- internals ---------------------------------------------------------------------------

    /** Save a step or yard event. @param list<string> $photos */
    public static function event(Actor $actor, string $orderId, ?string $tripId, string $step, ?int $odo, string $note, array $photos): void
    {
        Db::insert('trip_events', [
            'id' => Uuid::v7(), 'order_id' => $orderId, 'trip_id' => $tripId, 'step' => $step, 'odometer' => $odo,
            'note' => $note !== '' ? $note : null, 'photos' => $photos !== [] ? wp_json_encode(array_values($photos)) : null,
            'user_id' => $actor->userId, 'created_at' => Db::now(),
        ], ['id', 'order_id', 'trip_id']);
    }

    /** Whole-km odometer reading, not below $min. */
    public static function odometer(array $in, int $min): int
    {
        $raw = str_replace([',', ' '], '', (string) ($in['odometer'] ?? ''));
        if ($raw === '' || !ctype_digit($raw) || (int) $raw > 9999999) {
            throw new DomainError('invalid_input', 'Enter the odometer reading in km.', ['field' => 'odometer']);
        }
        if ((int) $raw < $min) {
            throw new DomainError('odometer_low', 'The odometer cannot be lower than the last reading (' . number_format($min) . ' km).', ['field' => 'odometer']);
        }
        return (int) $raw;
    }

    /** @param list<string> $paragraphs */
    public static function mailCustomer(array $o, string $subject, array $paragraphs): void
    {
        $u = get_userdata((int) $o['created_by']);
        if ($u) {
            Mail::send($u->user_email, $subject, $paragraphs, ['label' => 'Open your order', 'href' => add_query_arg('id', $o['id'], Router::url('my-orders'))]);
        }
    }

    private static function assertDriverOf(Actor $actor, array $trip): void
    {
        if ($actor->can('cf_dispatch')) {
            return; // the office can record a step for the driver
        }
        if (!$actor->can('cf_drive') || (int) $trip['driver_user_id'] !== $actor->userId) {
            throw new DomainError('not_found', 'Trip not found.');
        }
    }

    /** @return array<string,mixed> */
    private static function row(string $tripId): array
    {
        $tripId = Uuid::assertHex($tripId, 'trip');
        $t = Db::row(Db::prepare(
            'SELECT tr.*, ' . Db::hex('tr.id', 'id') . ', ' . Db::hex('tr.order_id', 'order_id') . ', ' . Db::hex('tr.truck_id', 'truck_id') . ', t.plate
             FROM ' . Db::t('trips') . ' tr LEFT JOIN ' . Db::t('trucks') . ' t ON t.id = tr.truck_id WHERE tr.id = UNHEX(%s)', $tripId
        ));
        if ($t === null) {
            throw new DomainError('not_found', 'Trip not found.');
        }
        return $t;
    }
}
