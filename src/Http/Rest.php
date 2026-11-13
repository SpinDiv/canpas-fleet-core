<?php

declare(strict_types=1);

namespace Canpas\Fleet\Http;

use Canpas\Fleet\Domain\Agreements;
use Canpas\Fleet\Domain\Catalog;
use Canpas\Fleet\Domain\Companies;
use Canpas\Fleet\Domain\Dispatch;
use Canpas\Fleet\Domain\Documents;
use Canpas\Fleet\Domain\Drivers;
use Canpas\Fleet\Domain\Expenses;
use Canpas\Fleet\Domain\Orders;
use Canpas\Fleet\Domain\Payments;
use Canpas\Fleet\Domain\Requirements;
use Canpas\Fleet\Domain\Trips;
use Canpas\Fleet\Domain\Yard;
use Canpas\Fleet\Domain\Settings;
use Canpas\Fleet\Domain\Stock;
use Canpas\Fleet\Portal\Nav;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Portal\Screens\OrdersScreen;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Tenancy\Actor;

/**
 * REST API under canpas/v1. Every portal screen (and a future native driver app) talks to these
 * routes. Signed-in routes resolve the Actor first, so the company and permissions always come
 * from the signed-in user, never from the request. Cookie auth requires the X-WP-Nonce header,
 * which also protects every write against cross-site requests.
 */
final class Rest
{
    public const NS = 'canpas/v1';
    private const ID = '(?P<id>[0-9a-f]{32})';

    public static function register(): void
    {
        register_rest_route(self::NS, '/me', [
            'methods' => 'GET',
            'permission_callback' => static fn(): bool => is_user_logged_in(),
            'callback' => static fn(): \WP_REST_Response|\WP_Error => self::handle(static fn(Actor $a): array => [
                'user_id' => $a->userId,
                'role' => $a->role,
                'company_id' => $a->companyId,
                'staff' => $a->isStaff(),
                'home' => Nav::home($a),
                'pages' => array_values(array_filter(array_keys(Nav::PAGES), static fn(string $s): bool => Nav::canOpen($a, $s))),
            ]),
        ]);

        // Public: company sign-up (multipart, with the business permit file).
        register_rest_route(self::NS, '/signup', [
            'methods' => 'POST',
            'permission_callback' => '__return_true',
            'callback' => static function (\WP_REST_Request $r): \WP_REST_Response|\WP_Error {
                if (trim((string) $r->get_param('website')) !== '') {
                    // Honeypot field filled in: a bot. Pretend success, do nothing.
                    return new \WP_REST_Response(['ok' => true, 'message' => 'Thank you.'], 200);
                }
                try {
                    $files = $r->get_file_params();
                    Companies::signup($r->get_params(), $files['permit'] ?? null);
                    return new \WP_REST_Response(['ok' => true, 'message' => 'Thank you. Canpas will review your details and email you once your account is approved.'], 200);
                } catch (DomainError $e) {
                    return self::error($e);
                }
            },
        ]);

        // Companies: approval workflow (staff).
        foreach (['approve', 'reactivate'] as $action) {
            self::post('/companies/' . self::ID . '/' . $action, static function (Actor $a, \WP_REST_Request $r) use ($action): array {
                [Companies::class, $action]($a, (string) $r['id']);
                return ['message' => $action === 'approve' ? 'Company approved. The contact has been emailed.' : 'Company reactivated.'];
            });
        }
        foreach (['decline', 'suspend'] as $action) {
            self::post('/companies/' . self::ID . '/' . $action, static function (Actor $a, \WP_REST_Request $r) use ($action): array {
                [Companies::class, $action]($a, (string) $r['id'], (string) $r->get_param('reason'));
                return ['message' => $action === 'decline' ? 'Company declined. The contact has been emailed.' : 'Company suspended.'];
            });
        }

        // Company users (customer admin).
        self::post('/company-users', static function (Actor $a, \WP_REST_Request $r): array {
            Companies::addUser($a, (string) $r->get_param('name'), (string) $r->get_param('email'), (string) $r->get_param('role'));
            return ['message' => 'Added. They will get an email to set their password.'];
        });
        self::post('/company-users/(?P<uid>\d+)/remove', static function (Actor $a, \WP_REST_Request $r): array {
            Companies::deactivateUser($a, (int) $r['uid']);
            return ['message' => 'Access removed.'];
        });

        // Catalog (create and update).
        $catalog = [
            'truck-types' => 'saveTruckType',
            'trucks' => 'saveTruck',
            'items' => 'saveItem',
            'units' => 'saveUnit',
        ];
        foreach ($catalog as $path => $method) {
            self::post('/' . $path, static fn(Actor $a, \WP_REST_Request $r): array => ['id' => [Catalog::class, $method]($a, self::body($r)), 'message' => 'Saved.']);
            self::post('/' . $path . '/' . self::ID, static fn(Actor $a, \WP_REST_Request $r): array => ['id' => [Catalog::class, $method]($a, self::body($r), (string) $r['id']), 'message' => 'Saved.']);
        }

        // Ordering: quotes (nothing saved) and bookings. Prices are always recalculated on the server.
        foreach (['truck' => 'Truck', 'equipment' => 'Equipment'] as $kind => $Kind) {
            self::post('/quote/' . $kind, static function (Actor $a, \WP_REST_Request $r) use ($Kind): array {
                $d = [Orders::class, 'quote' . $Kind]($a, self::body($r));
                return ['subtotal' => $d['subtotal'], 'deposit' => $d['deposit'], 'html' => OrdersScreen::breakdown($d)];
            });
            self::post('/orders/' . $kind, static function (Actor $a, \WP_REST_Request $r) use ($Kind): array {
                $id = [Orders::class, 'book' . $Kind]($a, self::body($r));
                return ['id' => $id, 'message' => 'Booked and held for you. Complete the requirements before the hold ends.', 'redirect' => add_query_arg('id', $id, Router::url('my-orders'))];
            });
        }
        self::post('/orders/' . self::ID . '/cancel', static function (Actor $a, \WP_REST_Request $r): array {
            Orders::cancel($a, (string) $r['id'], (string) $r->get_param('reason'));
            return ['message' => 'Order cancelled.'];
        });
        self::post('/stock', static function (Actor $a, \WP_REST_Request $r): array {
            Stock::adjust($a, (string) $r->get_param('item_id'), (int) $r->get_param('qty'), (string) $r->get_param('reason'), (string) $r->get_param('note'));
            return ['message' => 'Stock updated.'];
        });

        // Requirements: documents, deposit, agreement, and the steps to confirmation.
        self::post('/documents', static function (Actor $a, \WP_REST_Request $r): array {
            $in = self::body($r);
            $id = Documents::upload($a, $in, $r->get_file_params());
            $order = strtolower((string) ($in['order_id'] ?? ''));
            if ($order !== '') {
                Requirements::attach($a, $order, (string) $in['doc_type'], $id);
                return ['id' => $id, 'message' => 'Uploaded and added to the order.'];
            }
            return ['id' => $id, 'message' => 'Uploaded. Canpas will check it.'];
        });
        self::post('/documents/' . self::ID . '/accept', static function (Actor $a, \WP_REST_Request $r): array {
            Documents::accept($a, (string) $r['id']);
            return ['message' => 'Marked as checked.'];
        });
        self::post('/documents/' . self::ID . '/reject', static function (Actor $a, \WP_REST_Request $r): array {
            Documents::reject($a, (string) $r['id'], (string) $r->get_param('reason'));
            return ['message' => 'Rejected. The customer sees your reason.'];
        });
        self::post('/orders/' . self::ID . '/attach', static function (Actor $a, \WP_REST_Request $r): array {
            Requirements::attach($a, (string) $r['id'], (string) $r->get_param('doc_type'), strtolower((string) $r->get_param('document_id')));
            return ['message' => 'Added to the order.'];
        });
        self::post('/orders/' . self::ID . '/payments', static function (Actor $a, \WP_REST_Request $r): array {
            $files = $r->get_file_params();
            Payments::submit($a, (string) $r['id'], self::body($r), $files['proof'] ?? null);
            return ['message' => 'Proof of payment sent. Canpas will check it against their account.'];
        });
        self::post('/payments/' . self::ID . '/receive', static function (Actor $a, \WP_REST_Request $r): array {
            Payments::receive($a, (string) $r['id']);
            return ['message' => 'Payment marked as received.'];
        });
        self::post('/payments/' . self::ID . '/reject', static function (Actor $a, \WP_REST_Request $r): array {
            Payments::reject($a, (string) $r['id'], (string) $r->get_param('reason'));
            return ['message' => 'Payment rejected. The customer sees your reason.'];
        });
        self::post('/orders/' . self::ID . '/sign', static function (Actor $a, \WP_REST_Request $r): array {
            Agreements::sign($a, (string) $r['id'], self::body($r));
            return ['message' => 'Agreement signed. A copy is saved on the order.', 'redirect' => add_query_arg('id', (string) $r['id'], Router::url('my-orders'))];
        });
        self::post('/orders/' . self::ID . '/submit', static function (Actor $a, \WP_REST_Request $r): array {
            Requirements::submit($a, (string) $r['id']);
            return ['message' => 'Sent to Canpas. You will get an email once they have checked it.'];
        });
        self::post('/orders/' . self::ID . '/send-back', static function (Actor $a, \WP_REST_Request $r): array {
            Requirements::sendBack($a, (string) $r['id'], (string) $r->get_param('reason'));
            return ['message' => 'Sent back to the customer.'];
        });
        self::post('/orders/' . self::ID . '/confirm', static function (Actor $a, \WP_REST_Request $r): array {
            Requirements::confirm($a, (string) $r['id']);
            return ['message' => 'Order confirmed. The customer has been emailed.'];
        });
        self::post('/orders/' . self::ID . '/decline', static function (Actor $a, \WP_REST_Request $r): array {
            Requirements::decline($a, (string) $r['id'], (string) $r->get_param('reason'));
            return ['message' => 'Order declined. The customer has been emailed.'];
        });
        self::post('/agreement', static function (Actor $a, \WP_REST_Request $r): array {
            $v = Agreements::saveVersion($a, (string) $r->get_param('title'), (string) $r->get_param('body'));
            return ['message' => "Saved as version {$v}. New signatures use it; signed orders keep theirs."];
        });

        // Operations: assigning the fleet, trips, yard and expenses.
        self::post('/orders/' . self::ID . '/assign-truck', static function (Actor $a, \WP_REST_Request $r): array {
            Dispatch::assignTruck($a, (string) $r['id'], strtolower((string) $r->get_param('truck_id')), (int) $r->get_param('driver_user_id'));
            return ['message' => 'Truck assigned.'];
        });
        self::post('/orders/' . self::ID . '/assign-units', static function (Actor $a, \WP_REST_Request $r): array {
            $in = self::body($r);
            Dispatch::assignUnits($a, (string) $r['id'], strtolower((string) ($in['line_id'] ?? '')), array_values((array) ($in['units'] ?? [])));
            return ['message' => 'Units assigned.'];
        });
        self::post('/orders/' . self::ID . '/assign-run', static function (Actor $a, \WP_REST_Request $r): array {
            Dispatch::assignRun($a, (string) $r['id'], (string) $r->get_param('kind'), strtolower((string) $r->get_param('truck_id')), (int) $r->get_param('driver_user_id'));
            return ['message' => ucfirst((string) $r->get_param('kind')) . ' run assigned.'];
        });
        self::post('/trips/' . self::ID . '/step', static function (Actor $a, \WP_REST_Request $r): array {
            $in = self::body($r);
            Trips::step($a, (string) $r['id'], (string) ($in['step'] ?? ''), $in, $r->get_file_params());
            return ['message' => Trips::LABELS[(string) $in['step']] . ' recorded.'];
        });
        self::post('/orders/' . self::ID . '/load', static function (Actor $a, \WP_REST_Request $r): array {
            Yard::load($a, (string) $r['id'], self::body($r), $r->get_file_params());
            return ['message' => 'Loading recorded.', 'redirect' => Router::url('loadings')];
        });
        self::post('/orders/' . self::ID . '/release', static function (Actor $a, \WP_REST_Request $r): array {
            Yard::releaseTruck($a, (string) $r['id'], self::body($r), $r->get_file_params());
            return ['message' => 'Truck released.', 'redirect' => Router::url('loadings')];
        });
        self::post('/orders/' . self::ID . '/return', static function (Actor $a, \WP_REST_Request $r): array {
            $o = Orders::row((string) $r['id']);
            $o['kind'] === 'truck'
                ? Yard::returnTruck($a, $o['id'], self::body($r), $r->get_file_params())
                : Yard::returnEquipment($a, $o['id'], self::body($r), $r->get_file_params());
            return ['message' => 'Return recorded.', 'redirect' => Router::url('returns')];
        });
        self::post('/expenses', static function (Actor $a, \WP_REST_Request $r): array {
            $files = $r->get_file_params();
            Expenses::add($a, self::body($r), $files['receipt'] ?? null);
            return ['message' => 'Expense saved.'];
        });
        self::post('/drivers', static function (Actor $a, \WP_REST_Request $r): array {
            Drivers::create($a, self::body($r));
            return ['message' => 'Driver added. They get an email to set their password.'];
        });
        self::post('/drivers/(?P<uid>\d+)', static function (Actor $a, \WP_REST_Request $r): array {
            Drivers::save($a, (int) $r['uid'], self::body($r));
            return ['message' => 'Driver saved.'];
        });

        // Settings.
        self::post('/settings', static function (Actor $a, \WP_REST_Request $r): array {
            Settings::save($a, self::body($r));
            return ['message' => 'Settings saved.'];
        });
    }

    /** Register a signed-in POST route whose callback receives the Actor. */
    private static function post(string $route, callable $fn): void
    {
        register_rest_route(self::NS, $route, [
            'methods' => 'POST',
            'permission_callback' => static fn(): bool => is_user_logged_in(),
            'callback' => static fn(\WP_REST_Request $r): \WP_REST_Response|\WP_Error => self::handle(static fn(Actor $a) => ['ok' => true] + $fn($a, $r)),
        ]);
    }

    /** @return array<string, mixed> request fields without the route placeholders */
    private static function body(\WP_REST_Request $r): array
    {
        $p = $r->get_json_params() ?: $r->get_body_params();
        $p = is_array($p) ? self::unflatten($p) : [];
        // Never let the browser set money fields; the domain recalculates them anyway.
        unset($p['total'], $p['subtotal'], $p['deposit'], $p['deposit_amount']);
        unset($p['id'], $p['_wpnonce']);
        return is_array($p) ? $p : [];
    }

    /**
     * JSON bodies from the portal keep form names like "qty[abc]" or "units[]" as flat keys.
     * Turn them into nested arrays, as PHP does for multipart forms.
     *
     * @param array<string,mixed> $in
     * @return array<string,mixed>
     */
    private static function unflatten(array $in): array
    {
        $out = [];
        foreach ($in as $k => $v) {
            if (!is_string($k) || !preg_match('/^([^\[]+)((?:\[[^\]]*\])+)$/', $k, $m)) {
                $out[$k] = $v;
                continue;
            }
            preg_match_all('/\[([^\]]*)\]/', $m[2], $parts);
            $path = array_merge([$m[1]], $parts[1]);
            foreach (is_array($v) && end($path) === '' ? $v : [$v] as $val) {
                $ref = &$out;
                foreach ($path as $i => $seg) {
                    $last = $i === count($path) - 1;
                    if ($seg === '') {
                        $ref[] = $last ? $val : [];
                        end($ref);
                        $seg = key($ref);
                    } elseif ($last) {
                        $ref[$seg] = $val;
                    }
                    if (!$last) {
                        if (!isset($ref[$seg]) || !is_array($ref[$seg])) {
                            $ref[$seg] = [];
                        }
                        $ref = &$ref[$seg];
                    }
                }
                unset($ref);
            }
        }
        return $out;
    }

    /** Run $fn with the current actor; turn business-rule refusals into clean JSON errors. */
    public static function handle(callable $fn): \WP_REST_Response|\WP_Error
    {
        try {
            return new \WP_REST_Response($fn(Actor::current()), 200);
        } catch (DomainError $e) {
            return self::error($e);
        }
    }

    private static function error(DomainError $e): \WP_Error
    {
        $status = match ($e->reason) {
            'not_logged_in' => 401,
            'forbidden', 'no_membership', 'company_inactive' => 403,
            'not_found' => 404,
            'rate_limited' => 429,
            default => 422,
        };
        return new \WP_Error($e->reason, $e->getMessage(), ['status' => $status] + $e->context);
    }
}
