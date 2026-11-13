<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal;

use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Renders a portal page. Pages that are not built yet show what they will do and in which
 * milestone, so the menu can be reviewed for every role from the first week.
 */
final class Pages
{
    private const PLANNED = [
        'dashboard' => 'Deliveries and returns today, orders waiting for a check, fleet status, utilisation and alerts.',
        'orders' => 'All orders with status filters; open one to check documents, confirm the deposit and assign trucks and drivers.',
        'dispatch' => 'Trips by day and truck: who goes where, with what load.',
        'calendar' => 'Each truck and heavy unit by day, so double-booking is blocked.',
        'trucks' => 'Trucks with plate, type, odometer, registration and insurance expiry, and status.',
        'heavy-equipment' => 'Serial-tracked units with hour meter, inspection expiry and status.',
        'stock' => 'Formwork and scaffolding by item: owned, out on rent, in repair and available by date.',
        'maintenance' => 'Service schedule by odometer and alerts for expiring registration and insurance.',
        'companies' => 'Customer companies, their users, documents, order history and balances.',
        'documents' => 'IDs, licences, permits and signed agreements, opened only through the portal and logged.',
        'approvals' => 'New company sign-ups to approve or decline, and suspensions.',
        'payments' => 'Deposits and payments with proof of payment, to confirm or reject.',
        'statements' => 'Monthly statements for long rentals and deposit settlement at return.',
        'reports' => 'Revenue by customer and item, kilometres, utilisation and overdue returns, with CSV export.',
        'settings' => 'Yard address, hold time, km tolerance, payment instructions and emails.',
        'agreement' => 'The rental agreement text customers sign, with version history.',
        'loadings' => 'Count items onto each truck before it leaves; shortfalls split the order line.',
        'returns' => 'Count items back in: good, damaged and missing, with photos.',
        'today' => 'Today\'s trips with map buttons, step by step: leaving yard, arrived, delivered, back at yard.',
        'expenses' => 'Fuel and trip costs with a receipt photo.',
        'home' => 'Your active orders, equipment on site, balance due and anything that needs your action.',
        'book-truck' => 'Pick a truck type, site and dates and see the price at once.',
        'rent' => 'Browse formwork, scaffolding and heavy equipment, choose quantities and dates, and see the price.',
        'my-orders' => 'Every order with its status, requirements checklist, documents and bills.',
        'my-documents' => 'Upload IDs and licences once and reuse them until they expire.',
        'my-statements' => 'Statements and receipts, and upload proof of payment.',
        'company-users' => 'Add and remove the people in your company who can book.',
    ];

    /** Screens that are built. Everything else shows its planned description. */
    private const BUILT = [
        'dashboard' => [Screens\Dashboard::class, 'render'],
        'approvals' => [Screens\CompaniesScreen::class, 'approvals'],
        'companies' => [Screens\CompaniesScreen::class, 'companies'],
        'company-users' => [Screens\CompaniesScreen::class, 'companyUsers'],
        'home' => [Screens\CompaniesScreen::class, 'customerHome'],
        'trucks' => [Screens\CatalogScreen::class, 'trucks'],
        'heavy-equipment' => [Screens\CatalogScreen::class, 'heavy'],
        'stock' => [Screens\CatalogScreen::class, 'stock'],
        'settings' => [Screens\SettingsScreen::class, 'render'],
        'orders' => [Screens\OrdersScreen::class, 'staffOrders'],
        'book-truck' => [Screens\OrdersScreen::class, 'bookTruck'],
        'rent' => [Screens\OrdersScreen::class, 'rent'],
        'my-orders' => [Screens\OrdersScreen::class, 'myOrders'],
        'my-documents' => [Screens\RequirementsScreen::class, 'myDocuments'],
        'documents' => [Screens\RequirementsScreen::class, 'documents'],
        'payments' => [Screens\RequirementsScreen::class, 'payments'],
        'agreement' => [Screens\RequirementsScreen::class, 'agreement'],
        'dispatch' => [Screens\OpsScreen::class, 'dispatch'],
        'calendar' => [Screens\OpsScreen::class, 'calendar'],
        'drivers' => [Screens\OpsScreen::class, 'drivers'],
        'today' => [Screens\FieldScreen::class, 'today'],
        'expenses' => [Screens\FieldScreen::class, 'expenses'],
        'loadings' => [Screens\FieldScreen::class, 'loadings'],
        'returns' => [Screens\FieldScreen::class, 'returns'],
        'statements' => [Screens\BillingScreen::class, 'statements'],
        'my-statements' => [Screens\BillingScreen::class, 'myStatements'],
        'maintenance' => [Screens\ManageScreen::class, 'maintenance'],
        'reports' => [Screens\ManageScreen::class, 'reports'],
        'account' => [Screens\AccountScreen::class, 'render'],
    ];

    public static function render(Actor $actor, string $slug): void
    {
        if (isset(self::BUILT[$slug])) {
            (self::BUILT[$slug])($actor);
            return;
        }
        [$label, , $group, , $milestone] = Nav::PAGES[$slug];
        $body = in_array($slug, ['today', 'loadings'], true)
            ? self::welcome($actor, $slug)
            : self::planned($slug, $milestone);
        Layout::page($actor, $slug, $label, $body, $group);
    }

    /**
     * Private file download: business permit (?company=), ID or licence (?doc=&side=),
     * proof of payment (?payment=) or signed agreement (?agreement=<order id>).
     * Every one goes through a domain permission check that also logs the view.
     */
    public static function file(Actor $actor): void
    {
        $param = static fn(string $k): string => strtolower(sanitize_key((string) ($_GET[$k] ?? '')));
        try {
            $f = match (true) {
                $param('doc') !== '' => \Canpas\Fleet\Domain\Documents::file($actor, $param('doc'), $param('side')),
                $param('payment') !== '' => \Canpas\Fleet\Domain\Payments::proof($actor, $param('payment')),
                $param('agreement') !== '' => \Canpas\Fleet\Domain\Agreements::file($actor, $param('agreement')),
                $param('photo') !== '' => \Canpas\Fleet\Domain\Trips::photo($actor, $param('photo'), (int) ($_GET['n'] ?? 0)),
                $param('sig') !== '' => \Canpas\Fleet\Domain\Trips::signature($actor, $param('sig')),
                $param('expense') !== '' => \Canpas\Fleet\Domain\Expenses::receipt($actor, $param('expense')),
                $param('maint') !== '' => \Canpas\Fleet\Domain\Maintenance::receipt($actor, $param('maint')),
                $param('bill') !== '' => ['bytes' => \Canpas\Fleet\Domain\Billing::pdf($actor, $param('bill')), 'name' => 'bill.pdf'],
                default => \Canpas\Fleet\Domain\Companies::permitFile($actor, $param('company')),
            };
        } catch (\Canpas\Fleet\Support\DomainError $e) {
            Layout::message('Document not available', $e->getMessage(), $e->reason === 'not_found' ? 404 : 403);
            return;
        }
        if (isset($f['bytes'])) {
            nocache_headers();
            header('Content-Type: application/pdf');
            header('Content-Length: ' . strlen($f['bytes']));
            header('Content-Disposition: inline; filename="' . $f['name'] . '"');
            header('X-Content-Type-Options: nosniff');
            echo $f['bytes'];
            exit;
        }
        \Canpas\Fleet\Support\Files::stream($f['path'], $f['name']);
    }

    /** Link to a private file through the portal. @param array<string,string> $args */
    public static function fileUrl(array $args): string
    {
        return add_query_arg($args, Router::url('file'));
    }

    private static function planned(string $slug, string $milestone): string
    {
        $what = Layout::esc(self::PLANNED[$slug] ?? '');
        $when = Layout::esc(Nav::MILESTONES[$milestone] ?? $milestone);
        return '<section class="card"><p><span class="pill info">Planned · ' . $when . '</span></p><p>' . $what . '</p></section>';
    }

    private static function welcome(Actor $actor, string $slug): string
    {
        $user = wp_get_current_user();
        $first = Layout::esc(explode(' ', trim($user->display_name))[0] ?? '');
        $today = Layout::esc(Db::todayManila()->format('l, j F Y'));
        $intro = '<p class="muted">' . $today . '</p><section class="card"><h2>Hello, ' . $first . '</h2><p>'
            . Layout::esc(self::PLANNED[$slug] ?? '') . '</p><p class="muted small">This page is being built. The menu on the left shows every page your role will use.</p></section>';

        $rows = '';
        foreach (Nav::MILESTONES as $code => $name) {
            $pages = [];
            foreach (Nav::PAGES as $s => [$label, , , , $m]) {
                if ($m === $code && Nav::canOpen($actor, $s)) {
                    $pages[] = $label;
                }
            }
            if ($pages !== []) {
                $rows .= '<li><span><b>' . Layout::esc($name) . '</b><br><span class="muted small">' . Layout::esc(implode(' · ', $pages)) . '</span></span><span class="pill info">' . $code . '</span></li>';
            }
        }
        return $intro . '<section class="card" style="margin-top:16px"><h2>What is coming for your role</h2><ul class="list">' . $rows . '</ul></section>';
    }
}
