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
 * Government IDs and driver's licences. A document belongs to the customer company and can be
 * used on any of its orders until it expires, so customers upload each one once.
 *
 * Files are kept in the private folder. Opening one always goes through file(), which checks
 * permission and records the view. Canpas checks each document (ok / rejected) before confirming
 * an order. After the retention period the files are deleted and only the record stays.
 */
final class Documents
{
    public const TYPES = [
        'gov_id' => 'Government ID',
        'license' => 'Driver\'s licence',
    ];

    public const STATUS_LABELS = [
        'pending' => 'Waiting for Canpas check',
        'ok' => 'Checked',
        'rejected' => 'Rejected',
        'deleted' => 'Deleted (retention)',
    ];

    /**
     * Customer uploads a document for their company.
     *
     * @param array<string,mixed> $in doc_type, person_name, id_number, expiry_date
     * @param array<string,array<string,mixed>> $files front (required), back (required for licences)
     */
    public static function upload(Actor $actor, array $in, array $files): string
    {
        $actor->require('cf_upload_documents');
        $companyId = (string) $actor->companyId;
        if ($companyId === '') {
            throw new DomainError('forbidden', 'Only customer accounts upload documents here.');
        }
        $type = (string) ($in['doc_type'] ?? '');
        if (!isset(self::TYPES[$type])) {
            throw new DomainError('invalid_input', 'Choose the kind of document.', ['field' => 'doc_type']);
        }
        $person = sanitize_text_field(wp_unslash((string) ($in['person_name'] ?? '')));
        if (mb_strlen($person) < 3) {
            throw new DomainError('invalid_input', 'Enter the full name shown on the document.', ['field' => 'person_name']);
        }
        $number = strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', (string) ($in['id_number'] ?? '')) ?? '');
        if (strlen($number) < 4 || strlen($number) > 40) {
            throw new DomainError('invalid_input', 'Enter the document number.', ['field' => 'id_number']);
        }
        $expiry = self::expiry((string) ($in['expiry_date'] ?? ''), $type === 'license');
        if (empty($files['front']['tmp_name'] ?? '')) {
            throw new DomainError('file_missing', 'Attach a photo or scan of the front.', ['field' => 'front']);
        }
        if ($type === 'license' && empty($files['back']['tmp_name'] ?? '')) {
            throw new DomainError('file_missing', 'Attach a photo or scan of the back of the licence.', ['field' => 'back']);
        }

        $folder = 'documents/' . $companyId;
        $front = Files::storeUpload($files['front'], $folder);
        $back = null;
        try {
            if (!empty($files['back']['tmp_name'] ?? '')) {
                $back = Files::storeUpload($files['back'], $folder);
            }
        } catch (DomainError $e) {
            Files::delete($front['path']);
            throw new DomainError($e->reason, $e->getMessage(), ['field' => 'back']);
        }

        $id = Uuid::v7();
        $keepDays = (int) Settings::get('document_retention_days');
        try {
            Db::transaction(static function () use ($id, $companyId, $type, $person, $number, $expiry, $front, $back, $actor, $keepDays): void {
                Db::insert('documents', [
                    'id' => $id, 'company_id' => $companyId, 'doc_type' => $type,
                    'person_name' => mb_substr($person, 0, 120), 'id_number' => $number, 'expiry_date' => $expiry,
                    'front_file' => $front['path'], 'front_name' => $front['name'],
                    'back_file' => $back['path'] ?? null, 'back_name' => $back['name'] ?? null,
                    'status' => 'pending', 'uploaded_by' => $actor->userId,
                    'delete_after' => Db::todayManila()->modify('+' . $keepDays . ' days')->format('Y-m-d'),
                    'created_at' => Db::now(),
                ], ['id', 'company_id']);
                Audit::log($companyId, $actor->userId, 'document.upload', 'document', $id, ['type' => $type]);
            });
        } catch (\Throwable $e) {
            Files::delete($front['path']);
            Files::delete($back['path'] ?? null);
            throw $e;
        }
        return $id;
    }

    /** Canpas marks a document as checked. */
    public static function accept(Actor $actor, string $docId): void
    {
        self::review($actor, $docId, 'ok', '');
    }

    /** Canpas rejects a document; the reason is shown to the customer. */
    public static function reject(Actor $actor, string $docId, string $reason): void
    {
        $reason = sanitize_text_field(wp_unslash($reason));
        if (mb_strlen($reason) < 5) {
            throw new DomainError('reason_required', 'Give a short reason (at least 5 characters). It is shown to the customer.', ['field' => 'reason']);
        }
        self::review($actor, $docId, 'rejected', $reason);
    }

    private static function review(Actor $actor, string $docId, string $status, string $reason): void
    {
        $actor->require('cf_manage_orders');
        $d = self::row($docId);
        if ($d['status'] === 'deleted') {
            throw new DomainError('wrong_status', 'This document has been deleted.');
        }
        Db::transaction(static function () use ($actor, $d, $status, $reason): void {
            Db::exec(Db::prepare(
                'UPDATE ' . Db::t('documents') . ' SET status = %s, status_reason = %s, reviewed_by = %d, reviewed_at = %s, updated_at = %s WHERE id = UNHEX(%s)',
                $status, $reason !== '' ? mb_substr($reason, 0, 255) : null, $actor->userId, Db::now(), Db::now(), $d['id']
            ));
            Audit::log($d['company_id'], $actor->userId, 'document.' . ($status === 'ok' ? 'accept' : 'reject'), 'document', $d['id'], $reason !== '' ? ['reason' => $reason] : []);
        });
    }

    /**
     * Permission check and view log before a file is streamed.
     *
     * @return array{path:string,name:string}
     */
    public static function file(Actor $actor, string $docId, string $side): array
    {
        $d = self::row($docId);
        self::assertCanSee($actor, $d);
        $side = $side === 'back' ? 'back' : 'front';
        if (empty($d[$side . '_file'])) {
            throw new DomainError('not_found', $d['status'] === 'deleted' ? 'This file was deleted after the retention period.' : 'No file for this side.');
        }
        self::logView($actor, 'document', $d['id'], $side);
        Audit::log($d['company_id'], $actor->userId, 'document.view', 'document', $d['id'], ['side' => $side]);
        return ['path' => (string) $d[$side . '_file'], 'name' => (string) ($d[$side . '_name'] ?: $side)];
    }

    /** Record that someone opened a private file. */
    public static function logView(Actor $actor, string $type, string $objectId, ?string $side = null): void
    {
        $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
        Db::insert('file_views', [
            'id' => Uuid::v7(), 'object_type' => $type, 'object_id' => $objectId, 'side' => $side,
            'user_id' => $actor->userId, 'ip_hash' => $ip !== '' ? hash_hmac('sha256', $ip, wp_salt('auth')) : null, 'created_at' => Db::now(),
        ], ['id', 'object_id']);
    }

    // ----- Reading -----------------------------------------------------------------------------

    /** @return array<string,mixed> */
    public static function get(Actor $actor, string $docId): array
    {
        $d = self::row($docId);
        self::assertCanSee($actor, $d);
        return $d;
    }

    /**
     * Documents the customer can see: all of the company's for a company admin, their own uploads otherwise.
     * Staff see all, optionally by status.
     *
     * @return list<array<string,mixed>>
     */
    public static function list(Actor $actor, ?string $status = null): array
    {
        $where = [];
        if ($actor->can('cf_view_documents')) {
            // staff
        } elseif ($actor->can('cf_upload_documents')) {
            $where[] = Db::prepare('d.company_id = UNHEX(%s)', (string) $actor->companyId);
            if (!$actor->can('cf_view_company_orders')) {
                $where[] = Db::prepare('d.uploaded_by = %d', $actor->userId);
            }
        } else {
            throw new DomainError('forbidden', 'You do not have permission to do this.');
        }
        if ($status !== null && $status !== '') {
            $where[] = Db::prepare('d.status = %s', $status);
        }
        return Db::rows('SELECT d.*, ' . Db::hex('d.id', 'id') . ', ' . Db::hex('d.company_id', 'company_id') . ', c.name AS company_name
            FROM ' . Db::t('documents') . ' d JOIN ' . Db::t('companies') . ' c ON c.id = d.company_id'
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY d.created_at DESC LIMIT 300');
    }

    /**
     * Documents that can be attached to an order: same type, not rejected or deleted, and valid on $validOn.
     *
     * @return list<array<string,mixed>>
     */
    public static function usable(Actor $actor, string $type, string $validOn): array
    {
        return array_values(array_filter(
            self::list($actor),
            static fn(array $d): bool => $d['doc_type'] === $type && in_array($d['status'], ['pending', 'ok'], true)
                && ($d['expiry_date'] === null || $d['expiry_date'] >= $validOn)
        ));
    }

    /** @return array<string,int> */
    public static function counts(): array
    {
        $out = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        foreach (Db::rows('SELECT status, COUNT(*) AS n FROM ' . Db::t('documents') . ' GROUP BY status') as $r) {
            $out[$r['status']] = (int) $r['n'];
        }
        return $out;
    }

    public static function masked(string $number): string
    {
        return strlen($number) <= 4 ? $number : '•••• ' . substr($number, -4);
    }

    // ----- Retention ---------------------------------------------------------------------------

    /**
     * Delete the files of documents past their retention date that no open order still needs.
     * The record stays (marked deleted) so past orders still show what was checked. Runs hourly.
     */
    public static function purge(): int
    {
        $active = implode(',', array_map(static fn(string $s): string => "'" . esc_sql($s) . "'", Orders::ACTIVE));
        $rows = Db::rows(Db::prepare(
            'SELECT ' . Db::hex('d.id', 'id') . ', ' . Db::hex('d.company_id', 'company_id') . ', d.front_file, d.back_file FROM ' . Db::t('documents') . ' d
             WHERE d.status <> %s AND d.delete_after < %s
               AND NOT EXISTS (SELECT 1 FROM ' . Db::t('orders') . ' o WHERE (o.gov_id_doc = d.id OR o.license_doc = d.id) AND o.status IN (' . $active . '))
             LIMIT 500',
            'deleted', Db::todayManila()->format('Y-m-d')
        ));
        foreach ($rows as $d) {
            Files::delete($d['front_file']);
            Files::delete($d['back_file']);
            Db::exec(Db::prepare(
                'UPDATE ' . Db::t('documents') . ' SET status = %s, front_file = NULL, back_file = NULL, id_number = RIGHT(id_number, 4), updated_at = %s WHERE id = UNHEX(%s)',
                'deleted', Db::now(), $d['id']
            ));
            Audit::log($d['company_id'], 0, 'document.purge', 'document', $d['id']);
        }
        return count($rows);
    }

    // ----- internals ---------------------------------------------------------------------------

    /** @return array<string,mixed> */
    public static function row(string $docId): array
    {
        $docId = Uuid::assertHex($docId, 'document');
        $d = Db::row(Db::prepare('SELECT *, ' . Db::hex('id') . ', ' . Db::hex('company_id') . ' FROM ' . Db::t('documents') . ' WHERE id = UNHEX(%s)', $docId));
        if ($d === null) {
            throw new DomainError('not_found', 'Document not found.');
        }
        return $d;
    }

    /** Staff with document access, the company admin, or the person who uploaded it. Others get "not found". */
    private static function assertCanSee(Actor $actor, array $d): void
    {
        if ($actor->can('cf_view_documents')) {
            return;
        }
        $sameCompany = $actor->companyId !== null && $actor->companyId === $d['company_id'];
        if (!$sameCompany || !$actor->can('cf_upload_documents') || (!$actor->can('cf_view_company_orders') && (int) $d['uploaded_by'] !== $actor->userId)) {
            throw new DomainError('not_found', 'Document not found.');
        }
    }

    private static function expiry(string $raw, bool $required): ?string
    {
        if ($raw === '') {
            if ($required) {
                throw new DomainError('invalid_input', 'Enter the expiry date shown on the licence.', ['field' => 'expiry_date']);
            }
            return null;
        }
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if (!$d || $d->format('Y-m-d') !== $raw) {
            throw new DomainError('invalid_input', 'Enter a valid expiry date.', ['field' => 'expiry_date']);
        }
        if ($raw < Db::todayManila()->format('Y-m-d')) {
            throw new DomainError('document_expired', 'This document has already expired. Upload a valid one.', ['field' => 'expiry_date']);
        }
        return $raw;
    }
}
