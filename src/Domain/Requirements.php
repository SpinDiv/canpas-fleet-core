<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Portal\Router;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Mail;
use Canpas\Fleet\Tenancy\Actor;

/**
 * What has to be in before Canpas confirms an order, and the steps between:
 *
 *   requested  --customer sends-->  submitted  --Canpas confirms-->  confirmed
 *                                       |  ^
 *                       Canpas sends back  |  customer fixes and sends again
 *                                       v  |
 *                                    needs_fix
 *
 * Confirming needs all of: the government ID checked; the driver's licence checked and valid
 * until the rental end (when the order needs one); deposits received that cover the deposit;
 * and the agreement signed. Canpas can decline an order with a reason at any of these steps.
 */
final class Requirements
{
    public const LABELS = [
        'gov_id' => 'Government ID of the person signing',
        'license' => 'Driver\'s or operator\'s licence',
        'deposit' => 'Deposit',
        'agreement' => 'Signed rental agreement',
    ];

    /** Customer attaches one of the company's documents to an order. */
    public static function attach(Actor $actor, string $orderId, string $type, string $docId): void
    {
        $actor->require('cf_upload_documents');
        $o = Orders::row($orderId);
        Orders::assertCustomerAccess($actor, $o);
        self::assertEditable($o);
        if (!isset(Documents::TYPES[$type])) {
            throw new DomainError('invalid_input', 'Unknown document type.');
        }
        if ($type === 'license' && !(int) $o['license_required']) {
            throw new DomainError('invalid_input', 'This order does not need a licence.');
        }
        $d = Documents::get($actor, $docId);
        if ($d['company_id'] !== $o['company_id'] || $d['doc_type'] !== $type) {
            throw new DomainError('not_found', 'Document not found.');
        }
        if (in_array($d['status'], ['rejected', 'deleted'], true)) {
            throw new DomainError('document_rejected', 'This document was rejected or deleted. Upload a new one.');
        }
        if ($type === 'license' && ($d['expiry_date'] === null || $d['expiry_date'] < $o['end_date'])) {
            throw new DomainError('license_expires', 'This licence expires before the rental ends (' . $o['end_date'] . '). Upload a licence that is valid for the whole rental.');
        }
        if ($d['expiry_date'] !== null && $d['expiry_date'] < $o['start_date']) {
            throw new DomainError('document_expired', 'This ID expires before the rental starts. Upload a valid one.');
        }
        $col = $type === 'license' ? 'license_doc' : 'gov_id_doc';
        Db::transaction(static function () use ($actor, $o, $d, $col, $type): void {
            Db::exec(Db::prepare('UPDATE ' . Db::t('orders') . " SET {$col} = UNHEX(%s), updated_at = %s WHERE id = UNHEX(%s)", $d['id'], Db::now(), $o['id']));
            Audit::log($o['company_id'], $actor->userId, 'order.attach_document', 'order', $o['id'], ['type' => $type, 'document' => $d['id']]);
        });
    }

    /**
     * State of each requirement for an order.
     *
     * @return array<string, array{state:string, note:string}> state: missing | pending | ok | rejected
     */
    public static function checklist(array $o): array
    {
        $out = [];
        foreach (['gov_id' => 'gov_id_doc', 'license' => 'license_doc'] as $type => $col) {
            if ($type === 'license' && !(int) $o['license_required']) {
                continue;
            }
            if (empty($o[$col])) {
                $out[$type] = ['state' => 'missing', 'note' => ''];
                continue;
            }
            $d = Documents::row((string) $o[$col]);
            $state = match ($d['status']) {
                'ok' => 'ok',
                'pending' => 'pending',
                default => 'rejected',
            };
            if ($type === 'license' && ($d['expiry_date'] === null || $d['expiry_date'] < $o['end_date'])) {
                $state = 'rejected';
                $d['status_reason'] = 'Expires before the rental ends';
            }
            $out[$type] = ['state' => $state, 'note' => (string) ($d['status_reason'] ?? ''), 'doc' => $d];
        }
        $t = Payments::totals((string) $o['id']);
        $due = (float) $o['deposit_amount'];
        $out['deposit'] = [
            'state' => $t['received'] + 0.004 >= $due ? 'ok' : ($t['received'] + $t['pending'] + 0.004 >= $due ? 'pending' : 'missing'),
            'note' => '', 'received' => $t['received'], 'pending' => $t['pending'], 'due' => $due,
        ];
        $out['agreement'] = ['state' => empty($o['signed_at']) ? 'missing' : 'ok', 'note' => ''];
        return $out;
    }

    /** @return list<string> requirement keys that stop the order from being confirmed */
    public static function gaps(array $o): array
    {
        return array_keys(array_filter(self::checklist($o), static fn(array $r): bool => $r['state'] !== 'ok'));
    }

    /** Customer sends the order to Canpas once everything is provided (documents may still be unchecked). */
    public static function submit(Actor $actor, string $orderId): void
    {
        $actor->require('cf_book');
        $o = Orders::row($orderId);
        Orders::assertCustomerAccess($actor, $o);
        self::assertEditable($o);
        $missing = array_keys(array_filter(self::checklist($o), static fn(array $r): bool => in_array($r['state'], ['missing', 'rejected'], true)));
        if ($missing !== []) {
            throw new DomainError('requirements_missing', 'Still needed: ' . implode(', ', array_map(static fn(string $k): string => self::LABELS[$k], $missing)) . '.', ['missing' => $missing]);
        }
        Db::transaction(static function () use ($actor, $o): void {
            Db::exec(Db::prepare(
                'UPDATE ' . Db::t('orders') . ' SET status = %s, submitted_at = %s, status_changed_by = %d, status_changed_at = %s, updated_at = %s WHERE id = UNHEX(%s) AND status IN (%s, %s)',
                'submitted', Db::now(), $actor->userId, Db::now(), Db::now(), $o['id'], 'requested', 'needs_fix'
            ));
            Audit::log($o['company_id'], $actor->userId, 'order.submit', 'order', $o['id']);
        });
        $to = (string) Settings::get('notify_email');
        if ($to !== '') {
            Mail::send($to, "Order {$o['ref']} is ready for checking", [
                "Order {$o['ref']} has its ID, licence, deposit proof and signed agreement in.",
                'Check the documents and the deposit, then confirm the order.',
            ], ['label' => 'Open the order', 'href' => add_query_arg('id', $o['id'], Router::url('orders'))]);
        }
    }

    /** Canpas sends the order back to the customer with a note on what to fix. */
    public static function sendBack(Actor $actor, string $orderId, string $note): void
    {
        $actor->require('cf_manage_orders');
        $o = Orders::row($orderId);
        if ($o['status'] !== 'submitted') {
            throw new DomainError('wrong_status', 'Only orders waiting for a check can be sent back.');
        }
        $note = sanitize_text_field(wp_unslash($note));
        if (mb_strlen($note) < 5) {
            throw new DomainError('reason_required', 'Tell the customer what to fix (at least 5 characters).', ['field' => 'reason']);
        }
        Db::transaction(static function () use ($actor, $o, $note): void {
            Db::exec(Db::prepare(
                'UPDATE ' . Db::t('orders') . ' SET status = %s, fix_note = %s, status_changed_by = %d, status_changed_at = %s, updated_at = %s WHERE id = UNHEX(%s) AND status = %s',
                'needs_fix', mb_substr($note, 0, 500), $actor->userId, Db::now(), Db::now(), $o['id'], 'submitted'
            ));
            Audit::log($o['company_id'], $actor->userId, 'order.send_back', 'order', $o['id'], ['note' => $note]);
        });
        self::mailCustomer($o, "Order {$o['ref']}: please check your requirements", [
            "Canpas checked order {$o['ref']} and needs something from you:",
            $note,
            'Open the order, fix it and send it again.',
        ]);
    }

    /** Canpas confirms: every requirement must be checked and the deposit received. */
    public static function confirm(Actor $actor, string $orderId): void
    {
        $actor->require('cf_manage_orders');
        $o = Orders::row($orderId);
        if ($o['status'] !== 'submitted') {
            throw new DomainError('wrong_status', 'Only orders waiting for a check can be confirmed.');
        }
        $gaps = self::gaps($o);
        if ($gaps !== []) {
            throw new DomainError('requirements_incomplete', 'Not ready to confirm: ' . implode(', ', array_map(static fn(string $k): string => strtolower(self::LABELS[$k]), $gaps)) . ' still need checking.', ['gaps' => $gaps]);
        }
        Db::transaction(static function () use ($actor, $o): void {
            Db::exec(Db::prepare(
                'UPDATE ' . Db::t('orders') . ' SET status = %s, confirmed_by = %d, confirmed_at = %s, fix_note = NULL, status_reason = NULL, status_changed_by = %d, status_changed_at = %s, updated_at = %s WHERE id = UNHEX(%s) AND status = %s',
                'confirmed', $actor->userId, Db::now(), $actor->userId, Db::now(), Db::now(), $o['id'], 'submitted'
            ));
            Audit::log($o['company_id'], $actor->userId, 'order.confirm', 'order', $o['id']);
        });
        self::mailCustomer($o, "Order {$o['ref']} is confirmed", [
            "Canpas has confirmed order {$o['ref']}.",
            'Your trucks and equipment are reserved for ' . $o['start_date'] . ' to ' . $o['end_date'] . '. Canpas will contact you about delivery or pickup.',
        ]);
    }

    /** Canpas declines an order that is not yet confirmed. */
    public static function decline(Actor $actor, string $orderId, string $reason): void
    {
        $actor->require('cf_manage_orders');
        $o = Orders::row($orderId);
        if (!in_array($o['status'], ['requested', 'submitted', 'needs_fix'], true)) {
            throw new DomainError('wrong_status', 'Only orders that are not yet confirmed can be declined.');
        }
        $reason = sanitize_text_field(wp_unslash($reason));
        if (mb_strlen($reason) < 5) {
            throw new DomainError('reason_required', 'Give a short reason (at least 5 characters). It is shown to the customer.', ['field' => 'reason']);
        }
        Db::transaction(static function () use ($actor, $o, $reason): void {
            Db::exec(Db::prepare(
                'UPDATE ' . Db::t('orders') . ' SET status = %s, status_reason = %s, status_changed_by = %d, status_changed_at = %s, updated_at = %s WHERE id = UNHEX(%s)',
                'declined', mb_substr($reason, 0, 255), $actor->userId, Db::now(), Db::now(), $o['id']
            ));
            Audit::log($o['company_id'], $actor->userId, 'order.decline', 'order', $o['id'], ['reason' => $reason]);
        });
        $paid = Payments::totals((string) $o['id'])['received'];
        self::mailCustomer($o, "Order {$o['ref']} was declined", array_values(array_filter([
            "Canpas could not accept order {$o['ref']}.",
            'Reason: ' . $reason,
            $paid > 0 ? 'Canpas will contact you about returning the deposit you paid.' : '',
        ])));
    }

    // ----- internals ---------------------------------------------------------------------------

    private static function assertEditable(array $o): void
    {
        if (!in_array($o['status'], ['requested', 'needs_fix'], true)) {
            throw new DomainError('wrong_status', $o['status'] === 'submitted'
                ? 'This order is with Canpas for checking. Wait for their reply.'
                : 'This order can no longer be changed.');
        }
        if ($o['status'] === 'requested' && $o['hold_expires_at'] < Db::now()) {
            throw new DomainError('hold_expired', 'The hold on this order has ended. Please book again.');
        }
    }

    /** @param list<string> $paragraphs */
    private static function mailCustomer(array $o, string $subject, array $paragraphs): void
    {
        $u = get_userdata((int) $o['created_by']);
        if ($u) {
            Mail::send($u->user_email, $subject, $paragraphs, ['label' => 'Open your order', 'href' => add_query_arg('id', $o['id'], Router::url('my-orders'))]);
        }
    }
}
