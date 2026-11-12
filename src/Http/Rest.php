<?php

declare(strict_types=1);

namespace Canpas\Fleet\Http;

use Canpas\Fleet\Portal\Nav;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Tenancy\Actor;

/**
 * REST API under canpas/v1. Every portal screen (and a future native driver app) talks to these
 * routes. Each route resolves the Actor first, so the company and permissions always come from
 * the signed-in user, never from the request.
 */
final class Rest
{
    public const NS = 'canpas/v1';

    public static function register(): void
    {
        register_rest_route(self::NS, '/me', [
            'methods' => 'GET',
            'permission_callback' => static fn(): bool => is_user_logged_in(),
            'callback' => static fn(): \WP_REST_Response|\WP_Error => self::handle(static function (Actor $a): array {
                return [
                    'user_id' => $a->userId,
                    'role' => $a->role,
                    'company_id' => $a->companyId,
                    'staff' => $a->isStaff(),
                    'home' => Nav::home($a),
                    'pages' => array_values(array_filter(array_keys(Nav::PAGES), static fn(string $s): bool => Nav::canOpen($a, $s))),
                ];
            }),
        ]);
    }

    /** Run $fn with the current actor; turn business-rule refusals into clean JSON errors. */
    public static function handle(callable $fn): \WP_REST_Response|\WP_Error
    {
        try {
            return new \WP_REST_Response($fn(Actor::current()), 200);
        } catch (DomainError $e) {
            $status = match ($e->reason) {
                'not_logged_in' => 401,
                'forbidden', 'no_membership', 'company_inactive' => 403,
                'not_found' => 404,
                default => 422,
            };
            return new \WP_Error($e->reason, $e->getMessage(), ['status' => $status] + $e->context);
        }
    }
}
