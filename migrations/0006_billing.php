<?php
/**
 * Milestone 5: closing orders and the money.
 *
 * invoices       the final bill of an order: booked price plus adjustments, the deposit applied,
 *                and what is still to pay or refund. Issued bills are locked.
 * invoice_lines  every line of a bill. Lines added or waived after booking keep who, when and why.
 * maintenance    service, repairs and registration/insurance renewals for trucks and heavy units,
 *                with cost and the next due km or date.
 * payments       kind now also 'balance' (customer pays the rest) and 'refund' (Canpas pays back).
 */

declare(strict_types=1);

return [
    'version' => '0006_billing',
    'statements' => static function (string $p, string $o): array {
        $id = 'id BINARY(16) NOT NULL PRIMARY KEY';
        $money = 'DECIMAL(12,2) NOT NULL DEFAULT 0';

        return [
            "CREATE TABLE {$p}invoices (
                {$id},
                ref VARCHAR(20) NOT NULL,
                order_id BINARY(16) NOT NULL,
                company_id BINARY(16) NOT NULL,
                status VARCHAR(10) NOT NULL DEFAULT 'draft',
                booked_total {$money},
                total {$money},
                deposit_received {$money},
                deposit_applied {$money},
                balance_due {$money},
                refund_due {$money},
                issued_by BIGINT UNSIGNED NULL,
                issued_at DATETIME(6) NULL,
                settled_at DATETIME(6) NULL,
                created_by BIGINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NULL,
                UNIQUE KEY uq_invoice_ref (ref),
                UNIQUE KEY uq_invoice_order (order_id),
                KEY ix_invoice_company (company_id, status),
                KEY ix_invoice_issued (status, issued_at),
                CONSTRAINT fk_invoice_order FOREIGN KEY (order_id) REFERENCES {$p}orders(id),
                CONSTRAINT fk_invoice_company FOREIGN KEY (company_id) REFERENCES {$p}companies(id)
            ) {$o}",

            "CREATE TABLE {$p}invoice_lines (
                {$id},
                invoice_id BINARY(16) NOT NULL,
                kind VARCHAR(16) NOT NULL,
                description VARCHAR(255) NOT NULL,
                qty DECIMAL(12,2) NOT NULL DEFAULT 1,
                unit_price {$money},
                amount {$money},
                source VARCHAR(8) NOT NULL DEFAULT 'system',
                reason VARCHAR(255) NULL,
                created_by BIGINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                sort INT NOT NULL DEFAULT 0,
                KEY ix_line_invoice (invoice_id, sort),
                CONSTRAINT fk_line_invoice FOREIGN KEY (invoice_id) REFERENCES {$p}invoices(id)
            ) {$o}",

            "CREATE TABLE {$p}maintenance (
                {$id},
                truck_id BINARY(16) NULL,
                unit_id BINARY(16) NULL,
                kind VARCHAR(16) NOT NULL,
                done_on DATE NOT NULL,
                odometer INT UNSIGNED NULL,
                cost {$money},
                vendor VARCHAR(120) NULL,
                note VARCHAR(500) NULL,
                receipt_file VARCHAR(255) NULL,
                receipt_name VARCHAR(190) NULL,
                next_due_km INT UNSIGNED NULL,
                next_due_date DATE NULL,
                created_by BIGINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                KEY ix_maint_truck (truck_id, done_on),
                KEY ix_maint_unit (unit_id, done_on),
                CONSTRAINT fk_maint_truck FOREIGN KEY (truck_id) REFERENCES {$p}trucks(id),
                CONSTRAINT fk_maint_unit FOREIGN KEY (unit_id) REFERENCES {$p}equipment_units(id)
            ) {$o}",

            "ALTER TABLE {$p}trucks
                ADD COLUMN next_service_km INT UNSIGNED NULL AFTER odometer_km,
                ADD COLUMN next_service_date DATE NULL AFTER next_service_km",

            "ALTER TABLE {$p}orders ADD COLUMN closed_at DATETIME(6) NULL",
        ];
    },
];
