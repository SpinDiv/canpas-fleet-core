<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal;

use Canpas\Fleet\Tenancy\Actor;

/**
 * Every portal page: its URL slug, menu label, icon, menu group, the capability that opens it
 * and the build milestone it belongs to. The sidebar, the router's permission check and the
 * placeholder pages all read this one list, so a page can never be in the menu but unreachable.
 */
final class Nav
{
    public const ICONS = [
        'grid' => 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
        'orders' => 'M9 4h6v3H9zM7 5H5v15h14V5h-2M8 11h8M8 15h6',
        'truck' => 'M3 6h11v10H3zM14 9h4l3 3v4h-7M7 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4zM17 19a2 2 0 1 0 0-4 2 2 0 0 0 0 4z',
        'cal' => 'M4 6h16v14H4zM4 10h16M8 3v4M16 3v4',
        'heavy' => 'M3 17h12v3H3zM5 17v-5h6l2 5M11 12l5-6 4 3-2 3M18 12v4',
        'stock' => 'M3 7l9-4 9 4v10l-9 4-9-4zM3 7l9 4 9-4M12 11v10',
        'wrench' => 'M14 6a4 4 0 0 0 5 5l-8 8a2 2 0 0 1-3-3l8-8',
        'building' => 'M5 21V4h10v17M15 9h4v12M8 8h2M8 12h2M8 16h2M3 21h18',
        'file' => 'M6 3h8l4 4v14H6zM14 3v4h4M9 13h6M9 17h6',
        'check' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18zM8 12l3 3 5-6',
        'card' => 'M3 6h18v12H3zM3 10h18M7 15h4',
        'receipt' => 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6',
        'chart' => 'M4 20V10M10 20V4M16 20v-7M22 20H2',
        'settings' => 'M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M16 4v4M10 10v4M18 16v4',
        'users' => 'M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM2 21a7 7 0 0 1 14 0M17 11a3 3 0 1 0 0-6M22 21a6 6 0 0 0-4-5.5',
        'home' => 'M3 11l9-7 9 7M5 10v10h14V10',
        'returns' => 'M9 14l-4-4 4-4M5 10h10a4 4 0 0 1 0 8h-3',
        'logout' => 'M15 4h4v16h-4M10 8l-4 4 4 4M6 12h11',
        'user' => 'M12 12a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM4 21a8 8 0 0 1 16 0',
        'search' => 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14zM21 21l-5-5',
    ];

    /**
     * slug => [label, icon, group, capability, milestone]
     * Group order in the sidebar follows GROUPS.
     */
    public const PAGES = [
        // Staff
        'dashboard' => ['Dashboard', 'grid', 'Operations', 'cf_view_dashboard', 'M1'],
        'orders' => ['Orders', 'orders', 'Operations', 'cf_manage_orders', 'M2'],
        'dispatch' => ['Dispatch board', 'truck', 'Operations', 'cf_dispatch', 'M4'],
        'calendar' => ['Calendar', 'cal', 'Operations', 'cf_dispatch', 'M4'],
        'trucks' => ['Trucks', 'truck', 'Fleet & stock', 'cf_assign_fleet', 'M1'],
        'heavy-equipment' => ['Heavy equipment', 'heavy', 'Fleet & stock', 'cf_assign_fleet', 'M1'],
        'stock' => ['Formwork & scaffolding', 'stock', 'Fleet & stock', 'cf_assign_fleet', 'M1'],
        'drivers' => ['Drivers', 'users', 'Fleet & stock', 'cf_assign_fleet', 'M4'],
        'maintenance' => ['Maintenance', 'wrench', 'Fleet & stock', 'cf_manage_fleet', 'M5'],
        'companies' => ['Companies', 'building', 'Customers', 'cf_manage_companies', 'M1'],
        'documents' => ['Documents', 'file', 'Customers', 'cf_view_documents', 'M3'],
        'approvals' => ['Approvals', 'check', 'Customers', 'cf_approve_companies', 'M1'],
        'payments' => ['Deposits & payments', 'card', 'Finance', 'cf_confirm_payments', 'M3'],
        'statements' => ['Statements', 'receipt', 'Finance', 'cf_settle_deposits', 'M5'],
        'reports' => ['Reports', 'chart', 'Finance', 'cf_view_reports', 'M5'],
        'settings' => ['Settings', 'settings', 'Account', 'cf_manage_settings', 'M1'],
        'agreement' => ['Rental agreement', 'file', 'Account', 'cf_manage_settings', 'M3'],
        // Yard and drivers (phone-first)
        'loadings' => ['Loadings', 'stock', 'Yard', 'cf_count_stock', 'M4'],
        'returns' => ['Returns', 'returns', 'Yard', 'cf_count_stock', 'M4'],
        'today' => ['Today', 'home', 'Trips', 'cf_drive', 'M4'],
        'expenses' => ['Expenses', 'receipt', 'Trips', 'cf_drive', 'M4'],
        // Customers
        'home' => ['Dashboard', 'grid', 'My account', 'cf_book', 'M1'],
        'book-truck' => ['Book a truck', 'truck', 'My account', 'cf_book', 'M2'],
        'rent' => ['Rent equipment', 'stock', 'My account', 'cf_book', 'M2'],
        'my-orders' => ['My orders', 'orders', 'My account', 'cf_book', 'M2'],
        'my-documents' => ['Documents', 'file', 'My account', 'cf_upload_documents', 'M3'],
        'my-statements' => ['Statements', 'receipt', 'My account', 'cf_view_statements', 'M5'],
        'company-users' => ['Company users', 'users', 'My account', 'cf_manage_company_users', 'M1'],
        // Everyone
        'account' => ['Account', 'user', 'Account', 'cf_access_portal', 'M1'],
    ];

    public const GROUPS = ['Operations', 'Fleet & stock', 'Customers', 'Finance', 'Yard', 'Trips', 'My account', 'Account'];

    public const MILESTONES = [
        'M1' => 'Foundation (weeks 1–2)',
        'M2' => 'Ordering (weeks 2–4)',
        'M3' => 'Requirements (weeks 4–5)',
        'M4' => 'Operations (weeks 5–7)',
        'M5' => 'Close & money (weeks 7–8)',
    ];

    public static function canOpen(Actor $actor, string $slug): bool
    {
        return isset(self::PAGES[$slug]) && $actor->can(self::PAGES[$slug][3]);
    }

    /** The page a role lands on at /fleet/. */
    public static function home(Actor $actor): string
    {
        foreach (['dashboard', 'today', 'loadings', 'home'] as $slug) {
            if (self::canOpen($actor, $slug)) {
                return $slug;
            }
        }
        return '';
    }

    /** @return list<array{label:string, items:list<array{slug:string,label:string,icon:string}>}> */
    public static function menu(Actor $actor): array
    {
        $groups = [];
        foreach (self::PAGES as $slug => [$label, $icon, $group, $cap]) {
            if ($actor->can($cap)) {
                $groups[$group][] = ['slug' => $slug, 'label' => $label, 'icon' => $icon];
            }
        }
        $out = [];
        foreach (self::GROUPS as $g) {
            if (!empty($groups[$g])) {
                $out[] = ['label' => $g, 'items' => $groups[$g]];
            }
        }
        return $out;
    }

    /**
     * Phone bottom tabs: only for people whose pages fit in 2–4 tabs (today yard checkers and
     * drivers). Everyone else keeps the slide-out menu, so this returns [] for them.
     *
     * @return list<array{slug:string,label:string,icon:string}>
     */
    public static function tabs(Actor $actor): array
    {
        $tabs = [];
        foreach (self::PAGES as $slug => [$label, $icon, , $cap]) {
            if ($slug !== 'account' && $actor->can($cap)) {
                $tabs[] = ['slug' => $slug, 'label' => $label, 'icon' => $icon];
            }
        }
        if (self::canOpen($actor, 'account')) {
            $tabs[] = ['slug' => 'account', 'label' => self::PAGES['account'][0], 'icon' => self::PAGES['account'][1]];
        }
        $n = count($tabs);
        return $n >= 2 && $n <= 4 ? $tabs : [];
    }

    public static function icon(string $name, int $size = 18): string
    {
        $d = self::ICONS[$name] ?? self::ICONS['grid'];
        return '<svg viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="' . esc_attr($d) . '"/></svg>';
    }
}
