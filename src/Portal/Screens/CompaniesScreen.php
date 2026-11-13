<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal\Screens;

use Canpas\Fleet\Domain\Companies;
use Canpas\Fleet\Domain\CompanyProfile;
use Canpas\Fleet\Portal\Layout;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Portal\Ui;
use Canpas\Fleet\Roles\Roles;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Actor;

/** Approvals, companies (staff) and company users (customer admin). */
final class CompaniesScreen
{
    public static function approvals(Actor $actor): void
    {
        $pending = Companies::list($actor, 'pending');
        $cards = '';
        foreach ($pending as $c) {
            $id = $c['id'];
            $dl = '<dl class="dl">'
                . '<dt>TIN</dt><dd class="num">' . Ui::e($c['tin']) . '</dd>'
                . '<dt>Contact</dt><dd>' . Ui::e($c['contact_name']) . ' · ' . Ui::e($c['contact_phone']) . '</dd>'
                . '<dt>Email</dt><dd>' . Ui::e($c['contact_email']) . '</dd>'
                . '<dt>Requested</dt><dd>' . Ui::e(Ui::when($c['created_at'])) . '</dd>'
                . '<dt>Business permit</dt><dd><a href="' . esc_url(add_query_arg('company', $id, Router::url('file'))) . '" target="_blank" rel="noopener">Open document</a> <span class="muted small">(viewing is logged)</span></dd>'
                . '</dl>';
            $actions = '<div class="form-actions">'
                . Ui::action('Approve', "companies/{$id}/approve", 'primary', "Approve {$c['name']}? They will be able to book straight away.")
                . Ui::action('Decline', "companies/{$id}/decline", 'danger', '', "Why is {$c['name']} declined? This reason is emailed to them.")
                . '</div>';
            $cards .= Ui::card($c['name'], $dl . $actions);
        }
        if ($cards === '') {
            $cards = Ui::card('', '<p class="empty">No new company is waiting for approval.</p>');
        }
        $rows = [];
        foreach (CompanyProfile::pendingCompanies() as $p) {
            $what = [];
            if ($p['documents'] > 0) {
                $what[] = $p['documents'] . ' document' . ($p['documents'] > 1 ? 's' : '');
            }
            if ($p['changes'] > 0) {
                $what[] = 'details change';
            }
            $rows[] = [Ui::e($p['name']), Ui::e(implode(' and ', $what)),
                '<a class="btn" href="' . esc_url(add_query_arg('id', $p['id'], Router::url('companies'))) . '">Review</a>'];
        }
        if ($rows !== []) {
            $cards = Ui::card('Profile updates waiting', Ui::table(['Company', 'Waiting', ''], $rows)) . $cards;
        }
        Layout::page($actor, 'approvals', 'Approvals', '<div class="stack">' . $cards . '</div>', 'Customers');
    }

    public static function companies(Actor $actor): void
    {
        $id = isset($_GET['id']) ? strtolower(sanitize_key((string) $_GET['id'])) : '';
        if ($id !== '' && Uuid::isHex($id)) {
            self::companyDetail($actor, $id);
            return;
        }
        $status = isset($_GET['status']) ? sanitize_key((string) $_GET['status']) : '';
        $status = in_array($status, Companies::STATUSES, true) ? $status : '';
        $counts = Companies::counts();
        $tabs = ['' => 'All (' . array_sum($counts) . ')'];
        foreach (Companies::STATUSES as $s) {
            $tabs[$s] = Ui::label($s) . ' (' . $counts[$s] . ')';
        }
        $rows = [];
        foreach (Companies::list($actor, $status) as $c) {
            $rows[] = [
                '<a href="' . esc_url(add_query_arg('id', $c['id'], Router::url('companies'))) . '"><b>' . Ui::e($c['name']) . '</b></a>',
                Ui::e($c['contact_name']) . '<br><span class="muted small">' . Ui::e($c['contact_email']) . '</span>',
                Ui::e($c['contact_phone']),
                (string) (int) $c['users'],
                Ui::pill($c['status']),
                Ui::e(Ui::when($c['created_at'])),
            ];
        }
        $body = Ui::tabs($tabs, $status, 'status')
            . Ui::card('', Ui::table(['Company', 'Contact', 'Mobile', '>People', 'Status', 'Since'], $rows, 'No companies yet.'));
        Layout::page($actor, 'companies', 'Companies', $body, 'Customers');
    }

    private static function companyDetail(Actor $actor, string $id): void
    {
        try {
            $c = Companies::get($actor, $id);
        } catch (DomainError $e) {
            Layout::message('Company not found', $e->getMessage(), 404, ['label' => 'Back to companies', 'href' => Router::url('companies')]);
            return;
        }
        $profile = CompanyProfile::company($id);
        $dl = '<dl class="dl">'
            . '<dt>Status</dt><dd>' . Ui::pill($c['status']) . ($c['status_reason'] ? ' <span class="muted small">' . Ui::e($c['status_reason']) . '</span>' : '') . '</dd>'
            . '<dt>TIN</dt><dd class="num">' . Ui::e($c['tin']) . '</dd>'
            . '<dt>Address</dt><dd>' . Ui::e($c['address']) . '</dd>'
            . '<dt>Business structure</dt><dd>' . Ui::e(CompanyProfile::STRUCTURES[(string) $profile['structure']] ?? 'Not set') . '</dd>'
            . '<dt>Contact</dt><dd>' . Ui::e($c['contact_name']) . ' · ' . Ui::e($c['contact_phone']) . ' · ' . Ui::e($c['contact_email']) . '</dd>'
            . ($profile['website'] ? '<dt>Website</dt><dd>' . Ui::e((string) $profile['website']) . '</dd>' : '')
            . '<dt>Requested</dt><dd>' . Ui::e(Ui::when($c['created_at'])) . '</dd>'
            . '<dt>Approved</dt><dd>' . Ui::e(Ui::when($c['approved_at'])) . '</dd>'
            . '<dt>Special rates</dt><dd>' . (Companies::specialRates($id) ? Ui::pill('active', 'Can request quotations') : '<span class="muted">Normal rates only</span>') . '</dd>'
            . '</dl>';

        $actions = '';
        if ($actor->can('cf_approve_companies')) {
            $actions = match ($c['status']) {
                'pending' => Ui::action('Approve', "companies/{$id}/approve", 'primary', "Approve {$c['name']}?")
                    . Ui::action('Decline', "companies/{$id}/decline", 'danger', '', 'Why is this company declined? The reason is emailed to them.'),
                'approved' => Ui::action('Suspend', "companies/{$id}/suspend", 'danger', '', "Why suspend {$c['name']}? Their people will not be able to book until you reactivate."),
                'suspended' => Ui::action('Reactivate', "companies/{$id}/reactivate", 'primary', "Reactivate {$c['name']}?"),
                default => '',
            };
        }

        if ($actor->can('cf_manage_rates') && $c['status'] === 'approved') {
            $actions .= Companies::specialRates($id)
                ? Ui::action('Turn off special rates', "companies/{$id}/special-rates-off", '', "Stop {$c['name']} from requesting special-rate quotations? Quotations already sent are not affected.")
                : Ui::action('Allow special rates', "companies/{$id}/special-rates-on", '', "Let {$c['name']} request special-rate quotations?");
        }

        $rows = [];
        foreach (Companies::users($actor, $id) as $u) {
            $rows[] = [Ui::e($u['name']), Ui::e($u['email']), Ui::e($u['role_label']), Ui::pill($u['status'])];
        }
        $body = '<div class="split">' . Ui::card('Details', $dl)
            . Ui::card('People who can sign in', Ui::table(['Name', 'Email', 'Role', 'Status'], $rows, 'No sign-ins.')) . '</div>';
        $body .= CompanyProfileScreen::staffChangesCard($actor, $id)
            . CompanyProfileScreen::documentsCard($actor, $id, (string) $profile['structure'], false, true);
        Layout::page($actor, 'companies', $c['name'], $body, 'Customers / Companies', $actions);
    }

    public static function companyUsers(Actor $actor): void
    {
        $rows = [];
        foreach (Companies::users($actor) as $u) {
            $remove = $u['status'] === 'active' && $u['user_id'] !== $actor->userId
                ? Ui::action('Remove access', "company-users/{$u['user_id']}/remove", 'danger', "Remove {$u['name']}'s access? They will be signed out.")
                : ($u['user_id'] === $actor->userId ? '<span class="muted small">You</span>' : '');
            $rows[] = [Ui::e($u['name']), Ui::e($u['email']), Ui::e($u['role_label']), Ui::pill($u['status']), '<div class="row-actions">' . $remove . '</div>'];
        }
        $fields = [
            'name' => ['Full name', 'text', true, null, null],
            'email' => ['Work email', 'email', true, null, null],
            'role' => ['Access', 'enum:' . Roles::CUSTOMER_USER . '|' . Roles::CUSTOMER_ADMIN, true, null, null],
        ];
        $form = '<p class="muted">They get an email with a link to set their own password.</p>'
            . Ui::form('company-users', $fields, ['role' => Roles::CUSTOMER_USER], [], 'Add person');
        $body = Ui::card('People in your company', Ui::table(['Name', 'Email', 'Access', 'Status', ''], $rows))
            . Ui::card('Add a person', $form);
        Layout::page($actor, 'company-users', 'Company users', $body, 'My account');
    }

    /** Customer landing page until ordering arrives in milestone 2. */
    public static function customerHome(Actor $actor): void
    {
        $c = Companies::get($actor, (string) $actor->companyId);
        $people = Companies::activeUsers((string) $actor->companyId);
        $first = Ui::e(explode(' ', trim(wp_get_current_user()->display_name))[0] ?? '');
        $kpis = '<div class="grid k4">'
            . Ui::kpi('Company', $c['name'], 'Account ' . strtolower(Ui::label($c['status'])))
            . Ui::kpi('Needs your action', (string) count(array_filter(\Canpas\Fleet\Domain\Orders::list($actor), static fn($o) => in_array($o['status'], ['requested', 'needs_fix'], true))), 'Orders waiting for requirements')
            . Ui::kpi('People who can book', (string) $people)
            . '</div>';
        $next = Ui::card('Coming next', '<ul class="list">'
            . '<li><span>Upload IDs, pay the deposit and sign the rental agreement online</span>' . Ui::pill('info', 'Milestone 3') . '</li>'
            . '<li><span>Track deliveries, equipment on site and returns</span>' . Ui::pill('info', 'Milestone 4') . '</li>'
            . '<li><span>Statements and final bills</span>' . Ui::pill('info', 'Milestone 5') . '</li>'
            . '</ul>');
        $actions = '<a class="btn" href="' . esc_url(Router::url('book-truck')) . '">Book a truck</a><a class="btn primary" href="' . esc_url(Router::url('rent')) . '">Rent equipment</a>';
        Layout::page($actor, 'home', 'Welcome, ' . html_entity_decode($first), $kpis . $next, $c['name'], $actions);
    }
}
