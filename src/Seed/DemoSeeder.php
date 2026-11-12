<?php

declare(strict_types=1);

namespace Canpas\Fleet\Seed;

use Canpas\Fleet\Roles\Roles;
use Canpas\Fleet\Tenancy\Accounts;

/**
 * Staging demo data: roles, one demo customer company and one sign-in per role.
 * Safe to re-run. Passwords are random and never printed; set one in wp-admin > Users
 * (or send a reset link) to sign in as a demo user. Never run on the live site.
 */
final class DemoSeeder
{
    public const COMPANY = 'Demo Builders Corp.';

    /** @return list<string> */
    public static function run(): array
    {
        Roles::install();
        $out = ['Roles installed: ' . implode(', ', Roles::all())];

        $company = Accounts::company(self::COMPANY, 'approved');
        $out[] = 'Company: ' . self::COMPANY;

        $people = [
            ['demo_admin', 'Ana Villanueva', Roles::ADMIN, null],
            ['demo_dispatcher', 'Maria Reyes', Roles::DISPATCHER, null],
            ['demo_yard', 'Noel Garcia', Roles::YARD, null],
            ['demo_driver', 'Jun Santos', Roles::DRIVER, null],
            ['demo_customer_admin', 'Paolo Cruz', Roles::CUSTOMER_ADMIN, $company],
            ['demo_customer_user', 'Liza Ramos', Roles::CUSTOMER_USER, $company],
        ];
        foreach ($people as [$login, $name, $role, $companyId]) {
            [$uid, $created] = Accounts::user($login, str_replace('_', '.', $login) . '@canpas-demo.example', $name, $role);
            Accounts::membership($uid, $role, $companyId);
            $out[] = sprintf('%s %s (%s)', $created ? 'Created' : 'Kept', $login, Roles::LABELS[$role]);
        }
        return $out;
    }
}
