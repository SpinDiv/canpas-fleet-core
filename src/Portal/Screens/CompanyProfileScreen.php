<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal\Screens;

use Canpas\Fleet\Domain\Companies;
use Canpas\Fleet\Domain\CompanyProfile;
use Canpas\Fleet\Portal\Layout;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Portal\Ui;
use Canpas\Fleet\Roles\Roles;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Company profile (customer) and the registration documents / change requests cards that the
 * Canpas admin sees on a company's page.
 */
final class CompanyProfileScreen
{
    public static function render(Actor $actor): void
    {
        $id = (string) $actor->companyId;
        $c = CompanyProfile::company($id);
        $isAdmin = $actor->can('cf_manage_company_users');
        $changes = CompanyProfile::changes($actor, $id);
        $pending = array_values(array_filter($changes, static fn($ch) => $ch['status'] === 'pending'))[0] ?? null;

        $body = self::readinessBanner($id, (int) $c['special_rates'] === 1);

        // Registered details.
        $dl = '<dl class="dl">'
            . '<dt>Company name</dt><dd>' . Ui::e($c['name']) . '</dd>'
            . '<dt>Business structure</dt><dd>' . Ui::e(CompanyProfile::STRUCTURES[(string) $c['structure']] ?? 'Not set yet') . '</dd>'
            . '<dt>TIN</dt><dd class="num">' . Ui::e((string) $c['tin']) . '</dd>'
            . '<dt>Registered address</dt><dd>' . Ui::e((string) $c['address']) . '</dd></dl>';
        if ($pending) {
            $dl .= '<div class="callout info"><b>Waiting for Canpas review</b> (sent ' . Ui::e(Ui::when($pending['requested_at'])) . '):<br>' . self::changeLines($pending['changes'])
                . ($isAdmin ? '<div class="row-actions" style="margin-top:8px">' . Ui::action('Cancel this change', "company-profile/changes/{$pending['id']}/cancel", '', 'Cancel the change waiting for Canpas?') . '</div>' : '') . '</div>';
        } elseif ($isAdmin) {
            $dl .= '<details class="upload"' . ((string) $c['structure'] === '' ? ' open' : '') . '><summary>Request a change</summary>'
                . '<p class="muted small">Canpas checks changes to these details before they are used on quotations, bills and agreements. Fill in only what changes.</p>'
                . '<form class="form" data-endpoint="company-profile/changes" novalidate><div class="form-grid">'
                . '<div class="field"><label for="ch-name">New company name</label><input id="ch-name" name="name" maxlength="191" placeholder="' . esc_attr($c['name']) . '"></div>'
                . '<div class="field"><label for="ch-structure">Business structure</label><select id="ch-structure" name="structure"><option value="">No change</option>' . self::options(CompanyProfile::STRUCTURES, '') . '</select></div>'
                . '<div class="field"><label for="ch-tin">New TIN</label><input id="ch-tin" name="tin" inputmode="numeric" placeholder="' . esc_attr((string) $c['tin']) . '"></div>'
                . '<div class="field wide"><label for="ch-address">New registered address</label><input id="ch-address" name="address" maxlength="255"></div>'
                . '</div><p class="form-error" role="alert" hidden></p><div class="form-actions"><button class="btn primary" type="submit">Send to Canpas</button></div></form></details>';
        }

        // Contact details.
        $contact = $isAdmin
            ? '<form class="form" data-endpoint="company-profile/contact" novalidate><div class="form-grid">'
                . Ui::field('contact_name', 'Contact person', 'text', true, $c['contact_name'])
                . Ui::field('contact_phone', 'Phone', 'text', true, $c['contact_phone'])
                . Ui::field('contact_email', 'Contact email', 'email', true, $c['contact_email'])
                . Ui::field('website', 'Website', 'text', false, $c['website'])
                . '</div><p class="form-error" role="alert" hidden></p><p class="muted small">These change straight away. Canpas is told what changed.</p><div class="form-actions"><button class="btn primary" type="submit">Save contact details</button></div></form>'
            : '<dl class="dl"><dt>Contact person</dt><dd>' . Ui::e((string) $c['contact_name']) . '</dd><dt>Phone</dt><dd>' . Ui::e((string) $c['contact_phone']) . '</dd>'
                . '<dt>Email</dt><dd>' . Ui::e((string) $c['contact_email']) . '</dd><dt>Website</dt><dd>' . Ui::e((string) $c['website']) . '</dd></dl>'
                . '<p class="muted small">Your company admin can change these.</p>';

        $body .= '<div class="split">' . Ui::card('Registered details', $dl) . Ui::card('Contact details', $contact) . '</div>';
        $body .= self::documentsCard($actor, $id, (string) $c['structure'], $isAdmin, false);
        if ($isAdmin) {
            $body .= self::adminsCard($actor);
        }
        $body .= self::historyCard($changes);
        Layout::page($actor, 'company-profile', 'Company profile', $body, 'My account');
    }

    /** "Complete to request special rates" checklist, for companies with special rates switched on. */
    public static function readinessBanner(string $companyId, bool $specialRates, bool $withLink = false): string
    {
        if (!$specialRates) {
            return '';
        }
        $r = CompanyProfile::quoteReady($companyId);
        if ($r['ok']) {
            return '';
        }
        return '<div class="callout warn"><b>Complete your company profile to request special rates.</b> Still needed: '
            . Ui::e(self::andList($r['missing'])) . '. Documents must be checked by Canpas and not expired.'
            . ($withLink ? ' <a href="' . esc_url(Router::url('company-profile')) . '">Open Company profile</a>' : '') . '</div>';
    }

    /** Documents card. $staff = the Canpas admin's view on the company page (review buttons). */
    public static function documentsCard(Actor $actor, string $companyId, string $structure, bool $canUpload, bool $staff): string
    {
        $rows = [];
        foreach (CompanyProfile::documents($actor, $companyId) as $d) {
            $state = match ($d['state']) {
                'approved' => Ui::pill('active', 'Approved'),
                'pending' => Ui::pill('pending', 'Waiting for Canpas'),
                'rejected' => Ui::pill('suspended', 'Not accepted'),
                'expired' => Ui::pill('suspended', 'Expired'),
                default => Ui::pill('retired', 'Replaced'),
            };
            $acts = '<a class="btn" href="' . esc_url(add_query_arg('companydoc', $d['id'], Router::url('file'))) . '" target="_blank" rel="noopener">Open</a>';
            if ($staff && $d['status'] === 'pending' && $actor->can('cf_approve_companies')) {
                $acts .= Ui::action('Approve', "company-documents/{$d['id']}/approve", 'primary', 'Approve this ' . strtolower(CompanyProfile::KINDS[$d['kind']]) . '? Check the name, TIN and expiry date against the company first.')
                    . Ui::action('Reject', "company-documents/{$d['id']}/reject", 'danger', '', 'Why is this document not accepted? The customer sees this reason.');
            }
            $rows[] = [
                '<b>' . Ui::e(CompanyProfile::KINDS[$d['kind']] ?? $d['kind']) . '</b><br><span class="muted small">' . Ui::e($d['by_name']) . ' · ' . Ui::e(Ui::when($d['uploaded_at'])) . '</span>'
                    . ($d['status_reason'] ? '<br><span class="muted small">“' . Ui::e((string) $d['status_reason']) . '”</span>' : ''),
                $d['expires_on'] ? Ui::e((new \DateTimeImmutable((string) $d['expires_on']))->format('j M Y')) : '<span class="muted">—</span>',
                $state,
                '<div class="row-actions">' . $acts . '</div>',
            ];
        }
        $need = ['permit' => CompanyProfile::KINDS['permit']];
        if ($structure === 'sole_prop') {
            $need['dti'] = CompanyProfile::KINDS['dti'];
        } elseif ($structure !== '') {
            $need['sec'] = CompanyProfile::KINDS['sec'];
        }
        $html = '<p class="muted small">' . ($structure === '' ? ($staff ? 'Business structure not set yet. ' : 'Set your business structure first (Request a change above). ') : '')
            . 'Needed: ' . Ui::e(implode(' and ', $need)) . '. Canpas checks each one. Opening a document is recorded.</p>'
            . Ui::table(['Document', 'Expires', 'Status', ''], $rows, 'No documents yet.');
        if ($canUpload) {
            $html .= '<details class="upload"' . ($rows === [] ? ' open' : '') . '><summary>Upload a document</summary>'
                . '<form class="form" data-endpoint="company-profile/documents" enctype="multipart/form-data" novalidate><div class="form-grid">'
                . '<div class="field"><label for="cd-kind">Document</label><select id="cd-kind" name="kind">' . self::options($need + CompanyProfile::KINDS, '') . '</select></div>'
                . '<div class="field"><label for="cd-exp">Expires on</label><input id="cd-exp" name="expires_on" type="date"><small class="muted">Not needed for SEC registration</small></div>'
                . '<div class="field wide"><label for="cd-file">Photo or PDF</label><input id="cd-file" name="file" type="file" accept="image/*,application/pdf" required></div>'
                . '</div><p class="form-error" role="alert" hidden></p><div class="form-actions"><button class="btn primary" type="submit">Upload</button></div></form></details>';
        }
        return Ui::card('Registration documents', $html);
    }

    /** The Canpas admin's view of change requests on a company page. */
    public static function staffChangesCard(Actor $actor, string $companyId): string
    {
        $changes = CompanyProfile::changes($actor, $companyId);
        if ($changes === []) {
            return '';
        }
        $html = '';
        foreach ($changes as $ch) {
            if ($ch['status'] !== 'pending') {
                continue;
            }
            $html .= '<div class="callout info"><b>Waiting for review</b> · ' . Ui::e($ch['by_name']) . ' · ' . Ui::e(Ui::when($ch['requested_at'])) . '<br>' . self::changeLines($ch['changes'], true)
                . '<div class="row-actions" style="margin-top:8px">'
                . Ui::action('Approve change', "company-changes/{$ch['id']}/approve", 'primary', 'Approve this change? The new details are used on quotations, bills and agreements from now on.')
                . Ui::action('Reject', "company-changes/{$ch['id']}/reject", 'danger', '', 'Why is this change not approved? The customer sees this reason.')
                . '</div></div>';
        }
        return Ui::card('Change requests', $html . self::historyList($changes));
    }

    private static function adminsCard(Actor $actor): string
    {
        $rows = [];
        $people = array_values(array_filter(Companies::users($actor), static fn($u) => $u['status'] === 'active'));
        $admins = count(array_filter($people, static fn($u) => $u['role'] === Roles::CUSTOMER_ADMIN));
        foreach ($people as $u) {
            $isAdmin = $u['role'] === Roles::CUSTOMER_ADMIN;
            $act = $isAdmin && $admins < 2
                ? '<span class="muted small">Only admin</span>'
                : ($isAdmin
                ? Ui::action('Make user', "company-profile/people/{$u['user_id']}/user", '', "Make {$u['name']} a regular user? They can still book but not manage the company.")
                : Ui::action('Make admin', "company-profile/people/{$u['user_id']}/admin", '', "Make {$u['name']} a company admin? They can change the company profile and people."));
            $rows[] = [Ui::e($u['name']), Ui::e($u['role_label']), '<div class="row-actions">' . $act . '</div>'];
        }
        return Ui::card('Company admins', '<p class="muted small">Admins can change the company profile, documents and people. There must always be at least one.</p>' . Ui::table(['Name', 'Access', ''], $rows));
    }

    private static function historyCard(array $changes): string
    {
        $list = self::historyList($changes);
        return $list === '' ? '' : Ui::card('Change history', $list);
    }

    private static function historyList(array $changes): string
    {
        $items = '';
        foreach ($changes as $ch) {
            if ($ch['status'] === 'pending') {
                continue;
            }
            $word = ['approved' => 'Approved', 'rejected' => 'Not approved', 'cancelled' => 'Cancelled'][$ch['status']] ?? $ch['status'];
            $items .= '<li><span><b>' . Ui::e($word) . '</b> · ' . self::changeLines($ch['changes'])
                . ($ch['reason'] ? '<br><span class="muted small">“' . Ui::e((string) $ch['reason']) . '”</span>' : '') . '</span>'
                . '<span class="muted small">' . Ui::e(Ui::when($ch['reviewed_at'] ?: $ch['requested_at'])) . '</span></li>';
        }
        return $items === '' ? '' : '<ul class="list">' . $items . '</ul>';
    }

    private static function changeLines(array $changes, bool $withOld = false): string
    {
        $out = [];
        foreach ($changes as $k => $d) {
            $out[] = Ui::e(CompanyProfile::LEGAL[$k] ?? $k) . ': '
                . ($withOld ? '<s class="muted">' . Ui::e(CompanyProfile::show($k, $d['old'] ?? '') ?: '—') . '</s> → ' : '')
                . '<b>' . Ui::e(CompanyProfile::show($k, $d['new'] ?? '')) . '</b>';
        }
        return implode('<br>', $out);
    }

    /** @param list<string> $items "A", "A and B", "A, B and C" */
    private static function andList(array $items): string
    {
        $last = array_pop($items);
        return $items === [] ? (string) $last : implode(', ', $items) . ' and ' . $last;
    }

    /** @param array<string,string> $opts */
    private static function options(array $opts, string $selected): string
    {
        $h = '';
        foreach ($opts as $k => $v) {
            $h .= '<option value="' . esc_attr($k) . '"' . ($k === $selected ? ' selected' : '') . '>' . Ui::e($v) . '</option>';
        }
        return $h;
    }
}
