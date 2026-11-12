<?php
/**
 * Milestone 2: orders, their lines and the stock ledger.
 *
 * An order belongs to one company. Its lines carry a copy of the rates used at booking (rates_json),
 * so later rate changes never change it. Stock on hand for an item is the sum of its stock movements.
 */

declare(strict_types=1);

return [
    'version' => '0003_orders',
    'statements' => static function (string $p, string $o): array {
        $id = 'id BINARY(16) NOT NULL PRIMARY KEY';
        $ts = 'created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NULL';
        $money = 'DECIMAL(12,2) NOT NULL DEFAULT 0';

        return [
            "CREATE TABLE {$p}orders (
                {$id},
                ref VARCHAR(20) NOT NULL,
                company_id BINARY(16) NOT NULL,
                created_by BIGINT UNSIGNED NOT NULL,
                kind VARCHAR(12) NOT NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'requested',
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                days INT UNSIGNED NOT NULL,
                fulfilment VARCHAR(12) NOT NULL DEFAULT 'delivery',
                needs_driver TINYINT(1) NOT NULL DEFAULT 0,
                site_address VARCHAR(255) NULL,
                site_lat DECIMAL(9,6) NULL,
                site_lng DECIMAL(9,6) NULL,
                distance_km DECIMAL(8,1) NULL,
                distance_source VARCHAR(12) NULL,
                subtotal {$money},
                deposit_amount {$money},
                total {$money},
                hold_expires_at DATETIME(6) NULL,
                notes VARCHAR(500) NULL,
                status_reason VARCHAR(255) NULL,
                status_changed_by BIGINT UNSIGNED NULL,
                status_changed_at DATETIME(6) NULL,
                {$ts},
                UNIQUE KEY uq_order_ref (ref),
                KEY ix_order_company (company_id, created_at),
                KEY ix_order_status_dates (status, start_date, end_date),
                CONSTRAINT fk_order_company FOREIGN KEY (company_id) REFERENCES {$p}companies(id)
            ) {$o}",

            "CREATE TABLE {$p}order_lines (
                {$id},
                order_id BINARY(16) NOT NULL,
                line_type VARCHAR(12) NOT NULL,
                truck_type_id BINARY(16) NULL,
                item_id BINARY(16) NULL,
                description VARCHAR(255) NOT NULL,
                qty INT UNSIGNED NOT NULL DEFAULT 1,
                charged_days INT UNSIGNED NOT NULL DEFAULT 0,
                unit_price {$money},
                amount {$money},
                deposit {$money},
                rates_json TEXT NOT NULL,
                breakdown_json TEXT NULL,
                sort SMALLINT UNSIGNED NOT NULL DEFAULT 0,
                created_at DATETIME(6) NOT NULL,
                KEY ix_line_order (order_id),
                KEY ix_line_item (item_id),
                KEY ix_line_truck_type (truck_type_id),
                CONSTRAINT fk_line_order FOREIGN KEY (order_id) REFERENCES {$p}orders(id),
                CONSTRAINT fk_line_item FOREIGN KEY (item_id) REFERENCES {$p}equipment_items(id),
                CONSTRAINT fk_line_truck_type FOREIGN KEY (truck_type_id) REFERENCES {$p}truck_types(id)
            ) {$o}",

            "CREATE TABLE {$p}stock_movements (
                {$id},
                item_id BINARY(16) NOT NULL,
                qty INT NOT NULL,
                reason VARCHAR(16) NOT NULL,
                order_id BINARY(16) NULL,
                note VARCHAR(255) NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                KEY ix_stock_item (item_id),
                CONSTRAINT fk_stock_item FOREIGN KEY (item_id) REFERENCES {$p}equipment_items(id)
            ) {$o}",
        ];
    },
];
