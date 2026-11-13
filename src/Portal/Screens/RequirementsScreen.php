<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal\Screens;

use Canpas\Fleet\Domain\Agreements;
use Canpas\Fleet\Domain\Documents;
use Canpas\Fleet\Domain\Payments;
use Canpas\Fleet\Domain\Requirements;
use Canpas\Fleet\Domain\Settings;
use Canpas\Fleet\Portal\Layout;
use Canpas\Fleet\Portal\Pages;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Portal\Ui;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Requirements screens: the checklist on an order (customer and staff), the signing page,
 * the customer's documents, and the staff pages for documents, payments and the agreement text.
 */
final class RequirementsScreen
{
    private const STATE_PILL = [
        'missing' => ['warn', 'Needed'],
        'pending' => ['info', 'Waiting for Canpas check'],
        'ok' => ['good', 'Checked'],
        'rejected' => ['bad', 'Rejected — replace it'],
    ];

    // ----- Checklist on the order page ---------------------------------------------------------

    /** The requirements card for an order. Editable for the customer while the order is held or sent back. */
    public static function card(Actor $actor, array $o): string
    {
        $staff = $actor->isStaff();
        $editable = !$staff && in_array($o['status'], ['requested', 'needs_fix'], true) && ($o['status'] !== 'requested' || $o['hold_expires_at'] > Db::now());
        $list = Requirements::checklist($o);
        $items = '';
        foreach ($list as $key => $r) {
            $state = $r['state'];
            if ($key === 'deposit' && $state === 'pending') {
                $pill = '<span class="pill info">' . Ui::e($staff ? 'Proof sent — check the account' : 'Proof sent — Canpas checking') . '</span>';
            } else {
                [$tone, $text] = self::STATE_PILL[$state];
                $pill = '<span class="pill ' . $tone . '">' . Ui::e($text) . '</span>';
            }
            $body = match ($key) {
                'gov_id', 'license' => self::docBody($actor, $o, $key, $r, $editable),
                'deposit' => self::depositBody($actor, $o, $r, $editable),
                default => self::agreementBody($actor, $o, $editable),
            };
            $items .= '<li><div class="req-head"><b>' . Ui::e(Requirements::LABELS[$key]) . '</b>' . $pill . '</div><div class="req-body">' . $body . '</div></li>';
        }

        $intro = '';
        if ($o['status'] === 'needs_fix' && $o['fix_note']) {
            $intro = '<div class="callout"><b>Canpas needs something from you:</b> ' . Ui::e($o['fix_note']) . '</div>';
        } elseif ($editable) {
            $intro = '<p class="muted small" style="margin:0 0 12px">Provide all four, then send the order to Canpas. Canpas checks them and confirms your booking. IDs and licences are kept private and deleted after the retention period.</p>';
        } elseif ($o['status'] === 'submitted' && !$staff) {
            $intro = '<div class="callout info">Sent to Canpas on ' . Ui::e(Ui::when($o['submitted_at'])) . '. You will get an email once they have checked it.</div>';
        }

        $foot = '';
        if ($editable) {
            $blocking = array_keys(array_filter($list, static fn(array $r): bool => in_array($r['state'], ['missing', 'rejected'], true)));
            $foot = '<div class="form-actions" style="margin-top:16px">'
                . ($blocking === []
                    ? Ui::action($o['status'] === 'needs_fix' ? 'Send to Canpas again' : 'Send to Canpas for checking', "orders/{$o['id']}/submit", 'primary')
                    : '<button type="button" class="btn primary" disabled>Send to Canpas for checking</button><span class="muted small">Still needed: ' . Ui::e(implode(', ', array_map(static fn(string $k): string => lcfirst(Requirements::LABELS[$k]), $blocking))) . '</span>')
                . '</div>';
        }
        return Ui::card('Requirements', $intro . '<ul class="req">' . $items . '</ul>' . $foot);
    }

    private static function docBody(Actor $actor, array $o, string $type, array $r, bool $editable): string
    {
        $html = '';
        if (isset($r['doc'])) {
            $html .= self::docLine($actor, $r['doc'], $actor->isStaff());
            if ($r['state'] === 'rejected' && $r['note'] !== '') {
                $html .= '<p class="small bad-text">' . Ui::e($r['note']) . '</p>';
            }
        } elseif (!$editable) {
            $html .= '<p class="muted small">Not provided.</p>';
        }
        if ($type === 'license' && !isset($r['doc']) && $editable) {
            $html .= '<p class="muted small">' . ($o['kind'] === 'truck' ? 'You drive this truck yourselves' : 'This order includes heavy equipment')
                . ', so Canpas needs the licence of the person who will ' . ($o['kind'] === 'truck' ? 'drive' : 'operate it') . ', valid until ' . Ui::e(Ui::date($o['end_date'])) . '.</p>';
        }
        if (!$editable || $r['state'] === 'ok') {
            return $html;
        }
        $validOn = $type === 'license' ? (string) $o['end_date'] : (string) $o['start_date'];
        $usable = array_filter(Documents::usable($actor, $type, $validOn), static fn(array $d): bool => !isset($r['doc']) || $d['id'] !== $r['doc']['id']);
        if ($usable !== []) {
            $opts = '';
            foreach ($usable as $d) {
                $opts .= '<option value="' . esc_attr($d['id']) . '">' . Ui::e($d['person_name'] . ' · ' . Documents::masked((string) $d['id_number'])
                    . ($d['expiry_date'] ? ' · valid to ' . Ui::date($d['expiry_date']) : '')) . '</option>';
            }
            $html .= '<form class="form inline-form" data-endpoint="orders/' . esc_attr($o['id']) . '/attach" novalidate>'
                . '<input type="hidden" name="doc_type" value="' . esc_attr($type) . '">'
                . '<div class="field"><label for="use-' . $type . '">Use one you uploaded before</label><select id="use-' . $type . '" name="document_id">' . $opts . '</select></div>'
                . '<button class="btn" type="submit">Use this</button><p class="form-error" role="alert" hidden></p></form>';
        }
        $html .= '<details class="upload"' . ($usable === [] && !isset($r['doc']) ? ' open' : '') . '><summary>' . ($usable === [] && !isset($r['doc']) ? 'Upload' : 'Upload a new one') . '</summary>'
            . self::uploadForm($type, (string) $o['id']) . '</details>';
        return $html;
    }

    private static function depositBody(Actor $actor, array $o, array $r, bool $editable): string
    {
        $staff = $actor->can('cf_confirm_payments');
        $html = '<p style="margin:0">Deposit due: <b class="num">' . Ui::e(Ui::money($r['due'])) . '</b>'
            . ' · received <span class="num">' . Ui::e(Ui::money($r['received'])) . '</span>'
            . ($r['pending'] > 0 ? ' · waiting for check <span class="num">' . Ui::e(Ui::money($r['pending'])) . '</span>' : '') . '</p>';
        $rows = [];
        foreach (Payments::forOrder((string) $o['id']) as $p) {
            if ($p['kind'] !== 'deposit') {
                continue;
            }
            $acts = '';
            if ($staff && $p['status'] === 'pending') {
                $acts = '<div class="row-actions">' . Ui::action('Received', "payments/{$p['id']}/receive", 'primary', 'Mark ' . Ui::money($p['amount']) . ' as received? Check that it is in the Canpas account first.')
                    . Ui::action('Reject', "payments/{$p['id']}/reject", 'danger', '', 'Why is this payment rejected? The customer sees this reason.') . '</div>';
            }
            $rows[] = [
                Ui::e(Ui::when($p['created_at'])),
                Ui::e(Payments::METHODS[$p['method']] ?? $p['method']) . '<br><span class="muted small mono">' . Ui::e($p['reference']) . '</span>',
                '<a href="' . esc_url(Pages::fileUrl(['payment' => $p['id']])) . '" target="_blank" rel="noopener">Proof</a>',
                self::paymentPill($p) . ($p['status'] === 'rejected' && $p['status_reason'] ? '<br><span class="small bad-text">' . Ui::e($p['status_reason']) . '</span>' : ''),
                Ui::e(Ui::money($p['amount'])),
                $acts,
            ];
        }
        if ($rows !== []) {
            $html .= Ui::table(['Sent', 'How', 'Proof', 'Status', '>Amount', ''], $rows);
        }
        if ($editable && $r['state'] !== 'ok') {
            $left = max(0, round($r['due'] - $r['received'] - $r['pending'], 2));
            $instructions = trim((string) Settings::get('payment_instructions'));
            $html .= '<details class="upload"' . ($rows === [] ? ' open' : '') . '><summary>Send proof of payment</summary>'
                . ($instructions !== '' ? '<div class="pre small">' . Ui::e($instructions) . '</div>' : '<p class="muted small">Ask Canpas for their bank or GCash details.</p>')
                . '<form class="form" data-endpoint="orders/' . esc_attr($o['id']) . '/payments" novalidate><div class="form-grid">'
                . '<div class="field"><label for="p-amount">Amount paid (₱)</label><input id="p-amount" name="amount" inputmode="decimal" class="num" value="' . esc_attr($left > 0 ? number_format($left, 2, '.', '') : '') . '" required></div>'
                . '<div class="field"><label for="p-method">Paid by</label><select id="p-method" name="method">' . self::options(Payments::METHODS, 'bank') . '</select></div>'
                . '<div class="field"><label for="p-ref">Reference number</label><input id="p-ref" name="reference" maxlength="80" required></div>'
                . '<div class="field"><label for="p-proof">Receipt or screenshot</label><input id="p-proof" name="proof" type="file" accept="image/*,application/pdf" required></div>'
                . '</div><p class="form-error" role="alert" hidden></p><div class="form-actions"><button class="btn primary" type="submit">Send proof</button></div></form></details>';
        }
        return $html;
    }

    private static function agreementBody(Actor $actor, array $o, bool $editable): string
    {
        if (!empty($o['signed_at'])) {
            $v = $o['agreement_version_id'] ? (int) Db::value(Db::prepare('SELECT version FROM ' . Db::t('agreement_versions') . ' WHERE id = UNHEX(%s)', $o['agreement_version_id'])) : 0;
            $html = '<p style="margin:0">Signed by <b>' . Ui::e($o['signed_name']) . '</b> on ' . Ui::e(Ui::when($o['signed_at'])) . ' · version ' . $v
                . ' · <a href="' . esc_url(Pages::fileUrl(['agreement' => $o['id']])) . '" target="_blank" rel="noopener">View PDF</a></p>';
            if ($actor->isStaff() && !Agreements::intact($o)) {
                $html .= '<p class="small bad-text">The saved PDF no longer matches the fingerprint taken at signing.</p>';
            }
            return $html;
        }
        if ($editable) {
            $url = add_query_arg(['id' => $o['id'], 'sign' => 1], Router::url('my-orders'));
            return '<p style="margin:0"><a class="btn" href="' . esc_url($url) . '">Read and sign the agreement</a></p>';
        }
        return '<p class="muted small">Not signed.</p>';
    }

    // ----- Signing page ------------------------------------------------------------------------

    public static function signPage(Actor $actor, array $o): void
    {
        $back = add_query_arg('id', $o['id'], Router::url('my-orders'));
        if (!$actor->can('cf_sign_agreements') || !in_array($o['status'], ['requested', 'needs_fix'], true)) {
            Layout::message('Cannot sign this order', 'This order can no longer be signed here.', 200, ['label' => 'Back to the order', 'href' => $back]);
            return;
        }
        $version = Agreements::active();
        $user = wp_get_current_user();
        $text = Agreements::merged($o, $version, (string) $user->display_name);
        $html = '';
        foreach (Agreements::blocks($text) as [$kind, $block]) {
            $html .= $kind === 'h' ? '<h3>' . Ui::e($block) . '</h3>' : '<p>' . nl2br(Ui::e($block)) . '</p>';
        }
        $doc = '<div class="agreement" tabindex="0" aria-label="Agreement text"><h2>' . Ui::e($version['title']) . '</h2>' . $html . '</div>'
            . '<p class="muted small" style="margin-top:8px">Version ' . (int) $version['version'] . '. A PDF copy with your signature is saved on the order.</p>';
        $form = '<form class="form" data-endpoint="orders/' . esc_attr($o['id']) . '/sign" novalidate>'
            . '<div class="form-grid"><div class="field wide"><label for="s-name">Your full name</label><input id="s-name" name="signed_name" maxlength="120" autocomplete="name" value="' . esc_attr($user->display_name) . '" required></div>'
            . '<div class="field wide"><span class="label" id="sig-label">Your signature</span>'
            . '<div class="sigpad"><canvas width="900" height="260" data-signature="signature" role="img" aria-labelledby="sig-label"></canvas><span class="sig-line" aria-hidden="true"></span>'
            . '<button type="button" class="btn" data-sig-clear>Clear</button></div><input type="hidden" name="signature" value="">'
            . '<small class="muted">Sign with your finger, a stylus or the mouse.</small></div>'
            . '<div class="field wide check"><label><input type="checkbox" name="agree" value="1"> I have read and agree to this agreement on behalf of ' . Ui::e(rtrim((string) $o['company_name'], '.')) . '.</label></div></div>'
            . '<p class="form-error" role="alert" hidden></p>'
            . '<div class="form-actions"><a class="btn" href="' . esc_url($back) . '">Back</a><button class="btn primary" type="submit">Sign agreement</button></div></form>';
        Layout::page($actor, 'my-orders', 'Sign agreement · ' . $o['ref'], Ui::card('', $doc) . Ui::card('Sign', $form), 'My account / My orders');
    }

    // ----- Customer: my documents --------------------------------------------------------------

    public static function myDocuments(Actor $actor): void
    {
        $rows = [];
        foreach (Documents::list($actor) as $d) {
            $rows[] = [
                Ui::e(Documents::TYPES[$d['doc_type']] ?? $d['doc_type']),
                '<b>' . Ui::e($d['person_name']) . '</b><br><span class="muted small mono">' . Ui::e(Documents::masked((string) $d['id_number'])) . '</span>',
                Ui::e($d['expiry_date'] ? Ui::date($d['expiry_date']) : '—'),
                self::docPill($d) . ($d['status'] === 'rejected' && $d['status_reason'] ? '<br><span class="small bad-text">' . Ui::e($d['status_reason']) . '</span>' : ''),
                self::fileLinks($d),
            ];
        }
        $list = Ui::card('', Ui::table(['Document', 'Name and number', 'Expires', 'Status', 'Files'], $rows, 'No documents yet. Upload them here or from an order.'));
        $add = '<details class="card adder"><summary><span class="btn primary">+ Upload a document</span></summary><div style="margin-top:16px">' . self::uploadForm(null, null) . '</div></details>';
        $note = '<p class="muted small" style="margin-top:12px">Upload each ID and licence once; you can use it on every order until it expires. Files are kept private, opened only by Canpas staff and logged, and deleted after the retention period.</p>';
        Layout::page($actor, 'my-documents', 'Documents', $list . $add . $note, 'My account');
    }

    // ----- Staff: documents --------------------------------------------------------------------

    public static function documents(Actor $actor): void
    {
        $status = sanitize_key((string) ($_GET['status'] ?? 'pending'));
        $status = isset(Documents::STATUS_LABELS[$status]) ? $status : 'pending';
        $counts = Documents::counts();
        $tabs = [];
        foreach (Documents::STATUS_LABELS as $k => $label) {
            $tabs[$k] = $label . ' (' . $counts[$k] . ')';
        }
        $canReview = $actor->can('cf_manage_orders');
        $rows = [];
        foreach (Documents::list($actor, $status) as $d) {
            $acts = '';
            if ($canReview && $d['status'] !== 'deleted') {
                $acts = '<div class="row-actions">'
                    . ($d['status'] !== 'ok' ? Ui::action('Checked', "documents/{$d['id']}/accept", 'primary') : '')
                    . ($d['status'] !== 'rejected' ? Ui::action('Reject', "documents/{$d['id']}/reject", 'danger', '', 'Why is this document rejected? The customer sees this reason.') : '')
                    . '</div>';
            }
            $rows[] = [
                Ui::e($d['company_name']),
                Ui::e(Documents::TYPES[$d['doc_type']] ?? $d['doc_type']),
                '<b>' . Ui::e($d['person_name']) . '</b><br><span class="muted small mono">' . Ui::e($d['id_number']) . '</span>',
                Ui::e($d['expiry_date'] ? Ui::date($d['expiry_date']) : '—'),
                Ui::e(Ui::when($d['created_at'])),
                self::fileLinks($d),
                self::docPill($d) . ($d['status_reason'] ? '<br><span class="muted small">' . Ui::e($d['status_reason']) . '</span>' : ''),
                $acts,
            ];
        }
        $note = '<p class="muted small" style="margin-top:12px">Opening a file is recorded with your name and the time. Files are deleted ' . (int) Settings::get('document_retention_days') . ' days after upload unless an open order still uses them.</p>';
        Layout::page($actor, 'documents', 'Documents', Ui::tabs($tabs, $status, 'status')
            . Ui::card('', Ui::table(['Company', 'Document', 'Name and number', 'Expires', 'Uploaded', 'Files', 'Status', ''], $rows, 'Nothing here.')) . $note, 'Customers');
    }

    // ----- Staff: payments ---------------------------------------------------------------------

    public static function payments(Actor $actor): void
    {
        $status = sanitize_key((string) ($_GET['status'] ?? 'pending'));
        $status = isset(Payments::STATUS_LABELS[$status]) ? $status : 'pending';
        $counts = Payments::counts();
        $tabs = [];
        foreach (Payments::STATUS_LABELS as $k => $label) {
            $tabs[$k] = $label . ' (' . $counts[$k] . ')';
        }
        $rows = [];
        foreach (Payments::list($actor, $status) as $p) {
            $acts = $p['status'] === 'pending'
                ? '<div class="row-actions">' . Ui::action('Received', "payments/{$p['id']}/receive", 'primary', 'Mark ' . Ui::money($p['amount']) . ' as received? Check that it is in the Canpas account first.')
                    . Ui::action('Reject', "payments/{$p['id']}/reject", 'danger', '', 'Why is this payment rejected? The customer sees this reason.') . '</div>'
                : '';
            $rows[] = [
                '<a class="mono" href="' . esc_url(add_query_arg('id', $p['order_id'], Router::url('orders'))) . '"><b>' . Ui::e($p['ref']) . '</b></a>',
                Ui::e($p['company_name']),
                Ui::e(Payments::METHODS[$p['method']] ?? $p['method']) . '<br><span class="muted small mono">' . Ui::e($p['reference']) . '</span>',
                Ui::e(Ui::when($p['created_at'])),
                '<a href="' . esc_url(Pages::fileUrl(['payment' => $p['id']])) . '" target="_blank" rel="noopener">Proof</a>',
                self::paymentPill($p) . ($p['status_reason'] ? '<br><span class="muted small">' . Ui::e($p['status_reason']) . '</span>' : ''),
                Ui::e(Ui::money($p['amount'])) . '<br><span class="muted small">' . ($p['kind'] === 'deposit' ? 'of ' . Ui::e(Ui::money($p['deposit_amount'])) . ' deposit' : Ui::e(Payments::KINDS[$p['kind']] ?? $p['kind'])) . '</span>',
                $acts,
            ];
        }
        Layout::page($actor, 'payments', 'Deposits & payments', Ui::tabs($tabs, $status, 'status')
            . Ui::card('', Ui::table(['Order', 'Company', 'How', 'Sent', 'Proof', 'Status', '>Amount', ''], $rows, 'Nothing here.')), 'Finance');
    }

    // ----- Admin: agreement text ---------------------------------------------------------------

    public static function agreement(Actor $actor): void
    {
        $active = Agreements::active();
        $tags = [];
        foreach (Agreements::TAGS as $tag => $what) {
            $tags[] = '<li><span class="mono">' . Ui::e($tag) . '</span><span class="muted">' . Ui::e($what) . '</span></li>';
        }
        $form = '<form class="form" data-endpoint="agreement" novalidate><div class="form-grid">'
            . '<div class="field wide"><label for="a-title">Title</label><input id="a-title" name="title" maxlength="160" value="' . esc_attr($active['title']) . '" required></div>'
            . '<div class="field wide"><label for="a-body">Text</label><textarea id="a-body" name="body" rows="26" class="mono-area" required>' . esc_textarea($active['body']) . '</textarea>'
            . '<small class="muted">Start a line with "# " for a heading. Leave an empty line between paragraphs.</small></div>'
            . '</div><p class="form-error" role="alert" hidden></p><div class="form-actions"><button class="btn primary" type="submit">Save as version ' . ((int) $active['version'] + 1) . '</button></div></form>';
        $rows = [];
        foreach (Agreements::versions() as $v) {
            $by = $v['created_by'] ? get_userdata((int) $v['created_by']) : null;
            $rows[] = [
                '<b>' . (int) $v['version'] . '</b>',
                Ui::e($v['title']),
                Ui::e(Ui::when($v['created_at'])) . '<br><span class="muted small">' . Ui::e($by ? $by->display_name : 'Starting draft') . '</span>',
                (int) $v['active'] ? Ui::pill('active', 'In use') : '',
                number_format((int) $v['signed']),
            ];
        }
        $body = '<div class="split">' . Ui::card('Agreement text (version ' . (int) $active['version'] . ')', $form)
            . '<div class="stack">' . Ui::card('Merge tags', '<ul class="list tags">' . implode('', $tags) . '</ul>')
            . Ui::card('Versions', Ui::table(['Version', 'Title', 'Saved', '', '>Signed'], $rows)
                . '<p class="muted small" style="margin-top:12px">Saving creates a new version for new signatures. Orders already signed keep the version they signed.</p>') . '</div></div>';
        Layout::page($actor, 'agreement', 'Rental agreement', $body, 'Account');
    }

    // ----- helpers -----------------------------------------------------------------------------

    /** Upload form for an ID or licence; with an order id it is also added to that order. */
    public static function uploadForm(?string $type, ?string $orderId): string
    {
        $p = $type ?? 'any';
        $typeField = $type !== null
            ? '<input type="hidden" name="doc_type" value="' . esc_attr($type) . '">'
            : '<div class="field"><label for="d-type-' . $p . '">Document</label><select id="d-type-' . $p . '" name="doc_type">' . self::options(Documents::TYPES, 'gov_id') . '</select></div>';
        $order = $orderId !== null ? '<input type="hidden" name="order_id" value="' . esc_attr($orderId) . '">' : '';
        $accept = 'image/*,application/pdf';
        return '<form class="form" data-endpoint="documents" novalidate>' . $order . ($type !== null ? $typeField : '')
            . '<div class="form-grid">' . ($type === null ? $typeField : '')
            . '<div class="field"><label for="d-name-' . $p . '">Full name on the document</label><input id="d-name-' . $p . '" name="person_name" maxlength="120" required></div>'
            . '<div class="field"><label for="d-num-' . $p . '">' . ($type === 'license' ? 'Licence number' : 'ID number') . '</label><input id="d-num-' . $p . '" name="id_number" maxlength="40" class="mono" autocapitalize="characters" required></div>'
            . '<div class="field"><label for="d-exp-' . $p . '">Expiry date' . ($type === 'license' ? '' : ' <span class="opt">(if shown)</span>') . '</label><input id="d-exp-' . $p . '" name="expiry_date" type="date" min="' . esc_attr(Db::todayManila()->format('Y-m-d')) . '"></div>'
            . '<div class="field"><label for="d-front-' . $p . '">Front (photo or PDF)</label><input id="d-front-' . $p . '" name="front" type="file" accept="' . $accept . '" required></div>'
            . '<div class="field"><label for="d-back-' . $p . '">Back' . ($type === 'license' ? '' : ' <span class="opt">(if it has one)</span>') . '</label><input id="d-back-' . $p . '" name="back" type="file" accept="' . $accept . '"></div>'
            . '</div><p class="form-error" role="alert" hidden></p><div class="form-actions"><button class="btn primary" type="submit">Upload</button><span class="muted small">PDF, JPG, PNG or WebP, up to 10 MB each.</span></div></form>';
    }

    private static function docLine(Actor $actor, array $d, bool $fullNumber): string
    {
        $acts = '';
        if ($actor->can('cf_manage_orders') && $d['status'] !== 'deleted') {
            $acts = ($d['status'] !== 'ok' ? Ui::action('Checked', "documents/{$d['id']}/accept", 'primary') : '')
                . ($d['status'] !== 'rejected' ? Ui::action('Reject', "documents/{$d['id']}/reject", 'danger', '', 'Why is this document rejected? The customer sees this reason.') : '');
        }
        return '<div class="doc-line"><span><b>' . Ui::e($d['person_name']) . '</b> · <span class="mono">' . Ui::e($fullNumber ? (string) $d['id_number'] : Documents::masked((string) $d['id_number'])) . '</span>'
            . ($d['expiry_date'] ? ' · valid to ' . Ui::e(Ui::date($d['expiry_date'])) : '') . ' · ' . self::fileLinks($d) . '</span>'
            . ($acts !== '' ? '<span class="row-actions">' . $acts . '</span>' : '') . '</div>';
    }

    private static function fileLinks(array $d): string
    {
        $links = [];
        foreach (['front' => 'Front', 'back' => 'Back'] as $side => $label) {
            if (!empty($d[$side . '_file'])) {
                $links[] = '<a href="' . esc_url(Pages::fileUrl(['doc' => $d['id'], 'side' => $side])) . '" target="_blank" rel="noopener">' . $label . '</a>';
            }
        }
        return $links === [] ? '<span class="muted">—</span>' : implode(' · ', $links);
    }

    private static function docPill(array $d): string
    {
        $tone = ['pending' => 'info', 'ok' => 'good', 'rejected' => 'bad', 'deleted' => 'muted'][$d['status']] ?? 'info';
        return '<span class="pill ' . $tone . '">' . Ui::e(Documents::STATUS_LABELS[$d['status']] ?? $d['status']) . '</span>';
    }

    private static function paymentPill(array $p): string
    {
        $tone = ['pending' => 'info', 'received' => 'good', 'rejected' => 'bad'][$p['status']] ?? 'info';
        return '<span class="pill ' . $tone . '">' . Ui::e(Payments::STATUS_LABELS[$p['status']] ?? $p['status']) . '</span>';
    }

    /** @param array<string,string> $choices */
    private static function options(array $choices, string $selected): string
    {
        $out = '';
        foreach ($choices as $v => $label) {
            $out .= '<option value="' . esc_attr($v) . '"' . ($v === $selected ? ' selected' : '') . '>' . Ui::e($label) . '</option>';
        }
        return $out;
    }
}
