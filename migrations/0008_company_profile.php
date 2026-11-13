<?php
/**
 * Company profile: business structure and website on the company, registration documents with
 * Canpas review and expiry, and change requests for legal details (name, TIN, address, structure).
 * Existing sign-up permits are copied into company_documents so they stay on record; they have no
 * expiry date, so customers are asked to upload the current permit before requesting quotations.
 */

declare(strict_types=1);

return [
    'version' => '0008_company_profile',
    'statements' => static function (string $p, string $o): array {
        $id = 'id BINARY(16) NOT NULL PRIMARY KEY';
        return [
            "ALTER TABLE {$p}companies
                ADD COLUMN website VARCHAR(191) NULL,
                ADD COLUMN structure VARCHAR(16) NULL",

            "CREATE TABLE {$p}company_documents (
                {$id},
                company_id BINARY(16) NOT NULL,
                kind VARCHAR(8) NOT NULL,
                file VARCHAR(255) NOT NULL,
                file_name VARCHAR(191) NULL,
                expires_on DATE NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'pending',
                status_reason VARCHAR(255) NULL,
                uploaded_by BIGINT UNSIGNED NULL,
                uploaded_at DATETIME(6) NOT NULL,
                reviewed_by BIGINT UNSIGNED NULL,
                reviewed_at DATETIME(6) NULL,
                reminded_at DATETIME(6) NULL,
                KEY ix_cdoc_company (company_id, kind, status),
                KEY ix_cdoc_status (status),
                CONSTRAINT fk_cdoc_company FOREIGN KEY (company_id) REFERENCES {$p}companies(id)
            ) {$o}",

            "CREATE TABLE {$p}company_changes (
                {$id},
                company_id BINARY(16) NOT NULL,
                changes_json TEXT NOT NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'pending',
                reason VARCHAR(255) NULL,
                requested_by BIGINT UNSIGNED NULL,
                requested_at DATETIME(6) NOT NULL,
                reviewed_by BIGINT UNSIGNED NULL,
                reviewed_at DATETIME(6) NULL,
                KEY ix_cchg_company (company_id, status),
                KEY ix_cchg_status (status),
                CONSTRAINT fk_cchg_company FOREIGN KEY (company_id) REFERENCES {$p}companies(id)
            ) {$o}",

            // Legacy sign-up permits (UUID() ids for these one-off rows).
            "INSERT INTO {$p}company_documents (id, company_id, kind, file, file_name, expires_on, status, uploaded_by, uploaded_at, reviewed_by, reviewed_at)
             SELECT UNHEX(REPLACE(UUID(), '-', '')), id, 'permit', permit_file, permit_name, NULL,
                    IF(status = 'pending', 'pending', 'approved'), created_by, created_at, approved_by, approved_at
             FROM {$p}companies WHERE permit_file IS NOT NULL",
        ];
    },
];
