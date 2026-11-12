<?php

declare(strict_types=1);

namespace Canpas\Fleet\Roles;

/**
 * The portal roles and their capabilities. WordPress administrators get none of these:
 * running the website is not the same as access to Canpas customer data.
 */
final class Roles
{
    public const ADMIN = 'cf_admin';
    public const DISPATCHER = 'cf_dispatcher';
    public const YARD = 'cf_yard';
    public const DRIVER = 'cf_driver';
    public const CUSTOMER_ADMIN = 'cf_customer_admin';
    public const CUSTOMER_USER = 'cf_customer_user';
    public const PENDING = 'cf_pending';

    /** Roles that belong to Canpas itself (no company). */
    public const STAFF = [self::ADMIN, self::DISPATCHER, self::YARD, self::DRIVER];

    /** Roles that belong to a customer company. */
    public const CUSTOMER = [self::CUSTOMER_ADMIN, self::CUSTOMER_USER, self::PENDING];

    public const LABELS = [
        self::ADMIN => 'Canpas: Admin',
        self::DISPATCHER => 'Canpas: Dispatcher',
        self::YARD => 'Canpas: Yard checker',
        self::DRIVER => 'Canpas: Driver',
        self::CUSTOMER_ADMIN => 'Canpas: Customer admin',
        self::CUSTOMER_USER => 'Canpas: Customer user',
        self::PENDING => 'Canpas: Awaiting approval',
    ];

    private const DISPATCH = [
        'cf_access_portal', 'cf_view_dashboard', 'cf_manage_orders', 'cf_view_documents', 'cf_confirm_payments',
        'cf_assign_fleet', 'cf_dispatch', 'cf_manage_companies', 'cf_view_reports',
    ];

    /** @var array<string, list<string>> */
    public const CAPS = [
        self::ADMIN => [
            ...self::DISPATCH,
            'cf_approve_companies', 'cf_manage_fleet', 'cf_manage_stock', 'cf_manage_rates', 'cf_manage_settings',
            'cf_override_price', 'cf_settle_deposits', 'cf_view_audit', 'cf_manage_staff', 'cf_count_stock',
        ],
        self::DISPATCHER => self::DISPATCH,
        self::YARD => ['cf_access_portal', 'cf_count_stock'],
        self::DRIVER => ['cf_access_portal', 'cf_drive'],
        self::CUSTOMER_ADMIN => [
            'cf_access_portal', 'cf_book', 'cf_upload_documents', 'cf_sign_agreements', 'cf_view_statements',
            'cf_view_company_orders', 'cf_manage_company_users',
        ],
        self::CUSTOMER_USER => ['cf_access_portal', 'cf_book', 'cf_upload_documents', 'cf_sign_agreements'],
        self::PENDING => ['cf_access_portal'],
    ];

    /** @return list<string> */
    public static function caps(string $role): array
    {
        return self::CAPS[$role] ?? [];
    }

    /** @return list<string> */
    public static function allCaps(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::CAPS))));
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::CAPS);
    }

    public static function isStaff(string $role): bool
    {
        return in_array($role, self::STAFF, true);
    }

    /** Create or refresh the roles. Idempotent. */
    public static function install(): void
    {
        foreach (self::CAPS as $role => $caps) {
            remove_role($role);
            $grant = ['read' => true];
            foreach ($caps as $cap) {
                $grant[$cap] = true;
            }
            add_role($role, self::LABELS[$role], $grant);
        }
        // No stock WordPress role carries Canpas capabilities.
        foreach (['administrator', 'editor', 'author', 'contributor', 'subscriber'] as $stock) {
            $r = get_role($stock);
            if ($r === null) {
                continue;
            }
            foreach (self::allCaps() as $cap) {
                $r->remove_cap($cap);
            }
        }
    }
}
