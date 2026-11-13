<?php
/**
 * Special-rate quotations and the email record.
 *
 * companies.special_rates  the Canpas admin lets this company request quotations.
 * orders.quote_round       0 for ordinary bookings; the latest round number for quotations.
 * orders.quote_valid_until a sent quotation holds its items until this time (UTC).
 * quote_rounds             every step of a quotation: request, offer, counter, accept, decline, reoffer,
 *                          with the rates offered per line (lines_json) and the totals.
 * email_log                every email the portal sends, for Canpas's records.
 */

declare(strict_types=1);

return [
    'version' => '0007_quotations',
    'statements' => static function (string $p, string $o): array {
        $id = 'id BINARY(16) NOT NULL PRIMARY KEY';
        $money = 'DECIMAL(12,2) NOT NULL DEFAULT 0';

        return [
            "ALTER TABLE {$p}companies ADD COLUMN special_rates TINYINT(1) NOT NULL DEFAULT 0",

            "ALTER TABLE {$p}orders
                ADD COLUMN quote_round INT UNSIGNED NOT NULL DEFAULT 0,
                ADD COLUMN quote_valid_until DATETIME(6) NULL",

            "CREATE TABLE {$p}quote_rounds (
                {$id},
                order_id BINARY(16) NOT NULL,
                round INT UNSIGNED NOT NULL,
                kind VARCHAR(10) NOT NULL,
                by_user BIGINT UNSIGNED NULL,
                note VARCHAR(1000) NULL,
                lines_json MEDIUMTEXT NULL,
                normal_total {$money},
                total {$money},
                deposit {$money},
                valid_until DATETIME(6) NULL,
                created_at DATETIME(6) NOT NULL,
                KEY ix_round_order (order_id, created_at),
                CONSTRAINT fk_round_order FOREIGN KEY (order_id) REFERENCES {$p}orders(id)
            ) {$o}",

            "CREATE TABLE {$p}email_log (
                {$id},
                order_id BINARY(16) NULL,
                company_id BINARY(16) NULL,
                to_email VARCHAR(190) NOT NULL,
                subject VARCHAR(255) NOT NULL,
                kind VARCHAR(32) NOT NULL DEFAULT 'general',
                sent_ok TINYINT(1) NOT NULL DEFAULT 0,
                created_at DATETIME(6) NOT NULL,
                KEY ix_email_order (order_id, created_at),
                KEY ix_email_company (company_id, created_at),
                CONSTRAINT fk_email_order FOREIGN KEY (order_id) REFERENCES {$p}orders(id),
                CONSTRAINT fk_email_company FOREIGN KEY (company_id) REFERENCES {$p}companies(id)
            ) {$o}",
        ];
    },
];
