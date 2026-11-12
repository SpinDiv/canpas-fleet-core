<?php

declare(strict_types=1);

namespace Canpas\Fleet\Http;

use Canpas\Fleet\Domain\Catalog;
use Canpas\Fleet\Domain\Companies;
use Canpas\Fleet\Domain\Orders;
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
        // Never let the browser set money fields; the domain recalculates them anyway.
        unset($p['total'], $p['subtotal'], $p['deposit'], $p['deposit_amount']);
        unset($p['id'], $p['_wpnonce']);
        return is_array($p) ? $p : [];
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
