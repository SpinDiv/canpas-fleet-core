<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Support\Db;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Support\Files;
use Canpas\Fleet\Support\Uuid;
use Canpas\Fleet\Tenancy\Actor;

/**
 * Deposits and payments. Phase 1: the customer pays by bank transfer or GCash and uploads the
 * proof; Canpas checks its bank and marks the payment received (or rejects it with a reason).
 * Only received payments count towards the deposit.
 */
final class Payments
{
    public const METHODS = ['bank' => 'Bank transfer', 'gcash' => 'GCash', 'maya' => 'Maya', 'cash' => 'Cash at the office', 'other' => 'Other'];

    public const KINDS = ['deposit' => 'Deposit', 'balance' => 'Final bill payment', 'refund' => 'Refund to customer'];

    public const STATUS_LABELS = [
        'pending' => 'Waiting for Canpas check',
        'received' => 'Received',
        'rejected' => 'Rejected',
    ];

    /** Orders that still take a deposit. */
    private const OPEN = ['requested', 'needs_fix', 'submitted'];

    /**
     * @param array<string,mixed> $in amount, method, reference
     * @param array<string,mixed>|null $proof one entry of $_FILES
     */
    public static function submit(Actor $actor, string $orderId, array $in, ?array $proof): string
    {
        $actor->require('cf_book');
        $o = Orders::row($orderId);
        Orders::assertCustomerAccess($actor, $o);
        if (in_array($o['status'], self::OPEN, true)) {
            $kind = 'deposit';
        } elseif (Billing::outstanding($o) > 0) {
            $kind = 'balance';
        } else {
            throw new DomainError('wrong_status', 'Nothing is due on this order right now.');
        }
        $amount = round((float) str_replace([',', '₱', ' '], '', (string) ($in['amount'] ?? '')), 2);
        if ($amount <= 0 || $amount > 10000000) {
            throw new DomainError('invalid_input', 'Enter the amount you paid.', ['field' => 'amount']);
        }
        if ($kind === 'balance' && $amount > Billing::outstanding($o) + 0.004) {
            throw new DomainError('invalid_input', 'That is more than the ' . number_format(Billing::outstanding($o), 2) . ' still due.', ['field' => 'amount']);
        }

        $method = (string) ($in['method'] ?? '');
        if (!isset(self::METHODS[$method]) || $method === 'cash') {
            throw new DomainError('invalid_input', $method === 'cash' ? 'Cash is recorded by Canpas at the office.' : 'Choose how you paid.', ['field' => 'method']);
        }
        $ref = sanitize_text_field(wp_unslash((string) ($in['reference'] ?? '')));
        if (mb_strlen($ref) < 3) {
            throw new DomainError('invalid_input', 'Enter the transfer, GCash or Maya reference number.', ['field' => 'reference']);
        }
        if ($proof === null || empty($proof['tmp_name'])) {
            throw new DomainError('file_missing', 'Attach the receipt or screenshot of the transfer.', ['field' => 'proof']);
        }
        $file = Files::storeUpload($proof, 'payments/' . $o['company_id']);
        $id = Uuid::v7();
        try {
            Db::transaction(static function () use ($id, $o, $kind, $amount, $method, $ref, $file, $actor): void {
                Db::insert('payments', [
                    'id' => $id, 'order_id' => $o['id'], 'company_id' => $o['company_id'], 'kind' => $kind,
                    'amount' => number_format($amount, 2, '.', ''), 'method' => $method, 'reference' => mb_substr($ref, 0, 80),
                    'proof_file' => $file['path'], 'proof_name' => $file['name'], 'status' => 'pending',
                    'created_by' => $actor->userId, 'created_at' => Db::now(),
                ], ['id', 'order_id', 'company_id']);
                Audit::log($o['company_id'], $actor->userId, 'payment.submit', 'payment', $id, ['order' => $o['ref'], 'amount' => $amount]);
            });
        } catch (\Throwable $e) {
            Files::delete($file['path']);
            throw $e;
        }
        return $id;
    }

    public static function receive(Actor $actor, string $paymentId): void
    {
        self::decide($actor, $paymentId, 'received', '');
    }

    public static function reject(Actor $actor, string $paymentId, string $reason): void
    {
        $reason = sanitize_text_field(wp_unslash($reason));
        if (mb_strlen($reason) < 5) {
            throw new DomainError('reason_required', 'Give a short reason (at least 5 characters). It is shown to the customer.', ['field' => 'reason']);
        }
        self::decide($actor, $paymentId, 'rejected', $reason);
    }

    private static function decide(Actor $actor, string $paymentId, string $status, string $reason): void
    {
        $actor->require('cf_confirm_payments');
        $p = self::row($paymentId);
        if ($p['status'] !== 'pending') {
            throw new DomainError('wrong_status', 'This payment has already been ' . $p['status'] . '.');
        }
        Db::transaction(static function () use ($actor, $p, $status, $reason): void {
            Db::exec(Db::prepare(
                'UPDATE ' . Db::t('payments') . ' SET status = %s, status_reason = %s, received_by = %d, received_at = %s, updated_at = %s WHERE id = UNHEX(%s) AND status = %s',
                $status, $reason !== '' ? mb_substr($reason, 0, 255) : null, $actor->userId, Db::now(), Db::now(), $p['id'], 'pending'
            ));
            Audit::log($p['company_id'], $actor->userId, 'payment.' . ($status === 'received' ? 'receive' : 'reject'), 'payment', $p['id'],
                ['amount' => (float) $p['amount']] + ($reason !== '' ? ['reason' => $reason] : []));
        });
        if ($status === 'received' && $p['kind'] === 'balance') {
            Billing::settleIfDone($actor, $p['order_id']);
        }
    }

    /**
     * Canpas records a final-bill payment it received directly (cash at the office, or a transfer
     * it already sees in its account).
     *
     * @param array<string,mixed> $in amount, method, reference
     */
    public static function recordReceived(Actor $actor, string $orderId, array $in): string
    {
        $actor->require('cf_confirm_payments');
        $o = Orders::row($orderId);
        $due = Billing::outstanding($o);
        if ($due <= 0) {
            throw new DomainError('wrong_status', 'Nothing is due on this order.');
        }
        [$amount, $method, $ref] = self::money($in, $due);
        $id = self::staffEntry($actor, $o, 'balance', $amount, $method, $ref, '');
        Billing::settleIfDone($actor, $o['id']);
        return $id;
    }

    /**
     * Canpas records a refund it paid back to the customer: the deposit left over after the final
     * bill, or the deposit of a cancelled or declined order.
     *
     * @param array<string,mixed> $in amount, method, reference, reason
     */
    public static function recordRefund(Actor $actor, string $orderId, array $in): string
    {
        $actor->require('cf_settle_deposits');
        $o = Orders::row($orderId);
        $max = self::refundable($o);
        if ($max <= 0) {
            throw new DomainError('wrong_status', 'There is nothing to refund on this order.');
        }
        [$amount, $method, $ref] = self::money($in, $max);
        $reason = sanitize_text_field(wp_unslash((string) ($in['reason'] ?? '')));
        if (in_array($o['status'], ['cancelled', 'declined', 'expired'], true) && mb_strlen($reason) < 5) {
            throw new DomainError('reason_required', 'Say why the deposit is refunded (and any amount kept, per the agreement).', ['field' => 'reason']);
        }
        $id = self::staffEntry($actor, $o, 'refund', $amount, $method, $ref, $reason);
        Billing::settleIfDone($actor, $o['id']);
        return $id;
    }

    /** What Canpas still owes back on an order. */
    public static function refundable(array $o): float
    {
        $refunded = self::totals((string) $o['id'], 'refund')['received'];
        if (in_array($o['status'], ['cancelled', 'declined', 'expired'], true)) {
            return max(0.0, round(self::totals((string) $o['id'])['received'] - $refunded, 2));
        }
        $inv = Billing::forOrder((string) $o['id']);
        if ($inv === null || $inv['status'] === 'draft') {
            return 0.0;
        }
        return max(0.0, round((float) $inv['refund_due'] - $refunded, 2));
    }

    /** @return array{0:float,1:string,2:string} */
    private static function money(array $in, float $max): array
    {
        $amount = round((float) str_replace([',', '₱', ' '], '', (string) ($in['amount'] ?? '')), 2);
        if ($amount <= 0) {
            throw new DomainError('invalid_input', 'Enter the amount.', ['field' => 'amount']);
        }
        if ($amount > $max + 0.004) {
            throw new DomainError('invalid_input', 'That is more than the ' . number_format($max, 2) . ' due.', ['field' => 'amount']);
        }
        $method = (string) ($in['method'] ?? '');
        if (!isset(self::METHODS[$method])) {
            throw new DomainError('invalid_input', 'Choose how it was paid.', ['field' => 'method']);
        }
        $ref = sanitize_text_field(wp_unslash((string) ($in['reference'] ?? '')));
        if ($method !== 'cash' && mb_strlen($ref) < 3) {
            throw new DomainError('invalid_input', 'Enter the transfer or GCash reference number.', ['field' => 'reference']);
        }
        return [$amount, $method, $ref !== '' ? $ref : 'Cash'];
    }

    private static function staffEntry(Actor $actor, array $o, string $kind, float $amount, string $method, string $ref, string $reason): string
    {
        $id = Uuid::v7();
        Db::transaction(static function () use ($id, $actor, $o, $kind, $amount, $method, $ref, $reason): void {
            Db::insert('payments', [
                'id' => $id, 'order_id' => $o['id'], 'company_id' => $o['company_id'], 'kind' => $kind,
                'amount' => number_format($amount, 2, '.', ''), 'method' => $method, 'reference' => mb_substr($ref, 0, 80),
                'status' => 'received', 'status_reason' => $reason !== '' ? mb_substr($reason, 0, 255) : null,
                'received_by' => $actor->userId, 'received_at' => Db::now(), 'created_by' => $actor->userId, 'created_at' => Db::now(),
            ], ['id', 'order_id', 'company_id']);
            Audit::log($o['company_id'], $actor->userId, 'payment.' . $kind, 'payment', $id, ['order' => $o['ref'], 'amount' => $amount] + ($reason !== '' ? ['reason' => $reason] : []));
        });
        return $id;
    }

    /** @return array{path:string,name:string} */
    public static function proof(Actor $actor, string $paymentId): array
    {
        $p = self::row($paymentId);
        if (!$actor->can('cf_confirm_payments')) {
            Orders::assertCustomerAccess($actor, Orders::row($p['order_id']));
        }
        if (empty($p['proof_file'])) {
            throw new DomainError('not_found', 'No proof on file.');
        }
        Documents::logView($actor, 'payment', $p['id']);
        return ['path' => (string) $p['proof_file'], 'name' => (string) ($p['proof_name'] ?: 'proof')];
    }

    /** @return array{received:float,pending:float} */
    public static function totals(string $orderId, string $kind = 'deposit'): array
    {
        $out = ['received' => 0.0, 'pending' => 0.0];
        foreach (Db::rows(Db::prepare('SELECT status, SUM(amount) AS s FROM ' . Db::t('payments') . ' WHERE order_id = UNHEX(%s) AND kind = %s GROUP BY status', $orderId, $kind)) as $r) {
            if (isset($out[$r['status']])) {
                $out[$r['status']] = round((float) $r['s'], 2);
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> payments on one order (access already checked by the caller) */
    public static function forOrder(string $orderId): array
    {
        return Db::rows(Db::prepare('SELECT *, ' . Db::hex('id') . ' FROM ' . Db::t('payments') . ' WHERE order_id = UNHEX(%s) ORDER BY created_at', $orderId));
    }

    /** @return list<array<string,mixed>> staff list */
    public static function list(Actor $actor, ?string $status = null): array
    {
        $actor->require('cf_confirm_payments');
        $where = $status !== null && $status !== '' ? Db::prepare(' WHERE p.status = %s', $status) : '';
        return Db::rows('SELECT p.*, ' . Db::hex('p.id', 'id') . ', ' . Db::hex('p.order_id', 'order_id') . ', o.ref, o.deposit_amount, c.name AS company_name
            FROM ' . Db::t('payments') . ' p JOIN ' . Db::t('orders') . ' o ON o.id = p.order_id JOIN ' . Db::t('companies') . ' c ON c.id = p.company_id'
            . $where . ' ORDER BY p.created_at DESC LIMIT 300');
    }

    /** @return array<string,int> */
    public static function counts(): array
    {
        $out = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        foreach (Db::rows('SELECT status, COUNT(*) AS n FROM ' . Db::t('payments') . ' GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private static function row(string $paymentId): array
    {
        $paymentId = Uuid::assertHex($paymentId, 'payment');
        $p = Db::row(Db::prepare('SELECT *, ' . Db::hex('id') . ', ' . Db::hex('order_id') . ', ' . Db::hex('company_id') . ' FROM ' . Db::t('payments') . ' WHERE id = UNHEX(%s)', $paymentId));
        if ($p === null) {
            throw new DomainError('not_found', 'Payment not found.');
        }
        return $p;
    }
}
