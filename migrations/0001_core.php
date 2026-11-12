<?php
/**
 * Foundation schema: customer companies, who belongs to which company (or to Canpas staff),
 * reference-number counters and the audit trail. Order, stock, trip and billing tables
 * arrive in later migrations with the modules that use them.
 *
 * Conventions: ids are UUIDv7 in BINARY(16); money is DECIMAL(12,2); times are UTC DATETIME(6);
 * foreign keys RESTRICT (nothing financial or audited cascades).
 */

declare(strict_types=1);

return [
    'version' => '0001_core',
    'statements' => static function (string $p, string $o): array {
        $id = 'id BINARY(16) NOT NULL PRIMARY KEY';
        $ts = 'created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NULL';

        return [
            "CREATE TABLE {$p}sequences (
                name VARCHAR(32) NOT NULL PRIMARY KEY,
                value BIGINT UNSIGNED NOT NULL
            ) {$o}",

            // A customer company. Every order, document and payment belongs to one.
            "CREATE TABLE {$p}companies (
                {$id},
                name VARCHAR(191) NOT NULL,
                tin VARCHAR(32) NULL,
                address VARCHAR(255) NULL,
                contact_name VARCHAR(191) NULL,
                contact_email VARCHAR(191) NULL,
                contact_phone VARCHAR(32) NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'pending',
                status_reason VARCHAR(255) NULL,
                billing_cycle VARCHAR(16) NOT NULL DEFAULT 'per_order',
                approved_by BIGINT UNSIGNED NULL,
                approved_at DATETIME(6) NULL,
                {$ts},
                UNIQUE KEY uq_company_name (name),
                KEY ix_company_status (status)
            ) {$o}",

            // One row per login. Staff rows have no company; customer rows always do.
            "CREATE TABLE {$p}memberships (
                {$id},
                user_id BIGINT UNSIGNED NOT NULL,
                company_id BINARY(16) NULL,
                role VARCHAR(32) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                {$ts},
                UNIQUE KEY uq_membership_user (user_id),
                KEY ix_membership_company (company_id),
                CONSTRAINT fk_membership_company FOREIGN KEY (company_id) REFERENCES {$p}companies(id)
            ) {$o}",

            // Append-only. Ids and codes only, never names, ID numbers or document contents.
            "CREATE TABLE {$p}audit_log (
                {$id},
                company_id BINARY(16) NULL,
                actor_user_id BIGINT UNSIGNED NOT NULL,
                action VARCHAR(64) NOT NULL,
                object_type VARCHAR(32) NOT NULL,
                object_id BINARY(16) NULL,
                ip_hash CHAR(64) NULL,
                meta_json TEXT NULL,
                created_at DATETIME(6) NOT NULL,
                KEY ix_audit_object (object_type, object_id),
                KEY ix_audit_actor (actor_user_id, created_at),
                KEY ix_audit_company (company_id, created_at)
            ) {$o}",
        ];
    },
];
