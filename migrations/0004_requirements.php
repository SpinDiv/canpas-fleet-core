<?php
/**
 * Milestone 3: requirements before an order is confirmed.
 *
 * documents          IDs and driver's licences, owned by the company and reused until they expire.
 *                    Files live in the private folder; the row keeps only their relative path.
 * file_views         every time someone opens a private file (who, when, which file).
 * payments           deposits and payments with their proof, confirmed by Canpas.
 * agreement_versions the rental agreement text; each signature records the version it was signed on.
 * orders (new cols)  which ID and licence are attached, the signature, submission and confirmation.
 */

declare(strict_types=1);

return [
    'version' => '0004_requirements',
    'statements' => static function (string $p, string $o): array {
        $id = 'id BINARY(16) NOT NULL PRIMARY KEY';
        $money = 'DECIMAL(12,2) NOT NULL DEFAULT 0';

        return [
            "CREATE TABLE {$p}documents (
                {$id},
                company_id BINARY(16) NOT NULL,
                doc_type VARCHAR(16) NOT NULL,
                person_name VARCHAR(120) NOT NULL,
                id_number VARCHAR(40) NOT NULL,
                expiry_date DATE NULL,
                front_file VARCHAR(255) NULL,
                front_name VARCHAR(190) NULL,
                back_file VARCHAR(255) NULL,
                back_name VARCHAR(190) NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'pending',
                status_reason VARCHAR(255) NULL,
                reviewed_by BIGINT UNSIGNED NULL,
                reviewed_at DATETIME(6) NULL,
                uploaded_by BIGINT UNSIGNED NOT NULL,
                delete_after DATE NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NULL,
                KEY ix_doc_company (company_id, doc_type, status),
                KEY ix_doc_status (status, created_at),
                KEY ix_doc_delete (delete_after),
                CONSTRAINT fk_doc_company FOREIGN KEY (company_id) REFERENCES {$p}companies(id)
            ) {$o}",

            "CREATE TABLE {$p}file_views (
                {$id},
                object_type VARCHAR(16) NOT NULL,
                object_id BINARY(16) NOT NULL,
                side VARCHAR(8) NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                ip_hash CHAR(64) NULL,
                created_at DATETIME(6) NOT NULL,
                KEY ix_view_object (object_type, object_id, created_at),
                KEY ix_view_user (user_id, created_at)
            ) {$o}",

            "CREATE TABLE {$p}payments (
                {$id},
                order_id BINARY(16) NOT NULL,
                company_id BINARY(16) NOT NULL,
                kind VARCHAR(12) NOT NULL DEFAULT 'deposit',
                amount {$money},
                method VARCHAR(12) NOT NULL,
                reference VARCHAR(80) NOT NULL,
                proof_file VARCHAR(255) NULL,
                proof_name VARCHAR(190) NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'pending',
                status_reason VARCHAR(255) NULL,
                received_by BIGINT UNSIGNED NULL,
                received_at DATETIME(6) NULL,
                created_by BIGINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NULL,
                KEY ix_pay_order (order_id),
                KEY ix_pay_status (status, created_at),
                CONSTRAINT fk_pay_order FOREIGN KEY (order_id) REFERENCES {$p}orders(id),
                CONSTRAINT fk_pay_company FOREIGN KEY (company_id) REFERENCES {$p}companies(id)
            ) {$o}",

            "CREATE TABLE {$p}agreement_versions (
                {$id},
                version INT UNSIGNED NOT NULL,
                title VARCHAR(160) NOT NULL,
                body MEDIUMTEXT NOT NULL,
                active TINYINT(1) NOT NULL DEFAULT 0,
                created_by BIGINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                UNIQUE KEY uq_agreement_version (version)
            ) {$o}",

            "ALTER TABLE {$p}orders
                ADD COLUMN license_required TINYINT(1) NOT NULL DEFAULT 0 AFTER needs_driver,
                ADD COLUMN gov_id_doc BINARY(16) NULL,
                ADD COLUMN license_doc BINARY(16) NULL,
                ADD COLUMN agreement_version_id BINARY(16) NULL,
                ADD COLUMN agreement_file VARCHAR(255) NULL,
                ADD COLUMN agreement_sha256 CHAR(64) NULL,
                ADD COLUMN signed_name VARCHAR(120) NULL,
                ADD COLUMN signed_by BIGINT UNSIGNED NULL,
                ADD COLUMN signed_at DATETIME(6) NULL,
                ADD COLUMN signed_ip_hash CHAR(64) NULL,
                ADD COLUMN submitted_at DATETIME(6) NULL,
                ADD COLUMN fix_note VARCHAR(500) NULL,
                ADD COLUMN confirmed_by BIGINT UNSIGNED NULL,
                ADD COLUMN confirmed_at DATETIME(6) NULL,
                ADD CONSTRAINT fk_order_gov_id FOREIGN KEY (gov_id_doc) REFERENCES {$p}documents(id),
                ADD CONSTRAINT fk_order_license FOREIGN KEY (license_doc) REFERENCES {$p}documents(id),
                ADD CONSTRAINT fk_order_agreement FOREIGN KEY (agreement_version_id) REFERENCES {$p}agreement_versions(id)",

            // Existing orders: a licence is needed for self-drive trucks and for heavy equipment.
            "UPDATE {$p}orders o SET o.license_required = 1
             WHERE (o.kind = 'truck' AND o.needs_driver = 0)
                OR EXISTS (SELECT 1 FROM {$p}order_lines l JOIN {$p}equipment_items i ON i.id = l.item_id
                           WHERE l.order_id = o.id AND i.category = 'heavy')",
        ];
    },
];
