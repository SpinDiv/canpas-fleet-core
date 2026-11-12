<?php
/**
 * Milestone 1: company sign-up details and the rental catalog.
 *
 * Catalog tables hold the CURRENT rates. Orders (milestone 2) copy the rates they use at booking,
 * so editing a rate here never changes an existing order.
 */

declare(strict_types=1);

return [
    'version' => '0002_catalog_signup',
    'statements' => static function (string $p, string $o): array {
        $id = 'id BINARY(16) NOT NULL PRIMARY KEY';
        $ts = 'created_at DATETIME(6) NOT NULL, updated_at DATETIME(6) NULL';
        $money = 'DECIMAL(12,2) NOT NULL DEFAULT 0';

        return [
            "ALTER TABLE {$p}companies
                ADD COLUMN permit_file VARCHAR(255) NULL AFTER billing_cycle,
                ADD COLUMN permit_name VARCHAR(191) NULL AFTER permit_file,
                ADD COLUMN created_by BIGINT UNSIGNED NULL AFTER permit_name",

            // A kind of truck Canpas rents out, with its pricing.
            "CREATE TABLE {$p}truck_types (
                {$id},
                name VARCHAR(100) NOT NULL,
                capacity VARCHAR(100) NULL,
                starting_fee {$money},
                rate_per_km {$money},
                driver_flat_fee {$money},
                driver_flat_km INT UNSIGNED NOT NULL DEFAULT 10,
                driver_rate_per_km {$money},
                daily_rate {$money},
                free_km INT UNSIGNED NOT NULL DEFAULT 0,
                deposit_mode VARCHAR(8) NOT NULL DEFAULT 'percent',
                deposit_value {$money},
                active TINYINT(1) NOT NULL DEFAULT 1,
                {$ts},
                UNIQUE KEY uq_truck_type_name (name)
            ) {$o}",

            // One physical truck.
            "CREATE TABLE {$p}trucks (
                {$id},
                type_id BINARY(16) NOT NULL,
                plate VARCHAR(16) NOT NULL,
                label VARCHAR(100) NULL,
                odometer_km INT UNSIGNED NOT NULL DEFAULT 0,
                registration_expiry DATE NULL,
                insurance_expiry DATE NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'available',
                notes VARCHAR(255) NULL,
                {$ts},
                UNIQUE KEY uq_truck_plate (plate),
                KEY ix_truck_type (type_id),
                CONSTRAINT fk_truck_type FOREIGN KEY (type_id) REFERENCES {$p}truck_types(id)
            ) {$o}",

            // Rentable equipment: formwork, scaffolding, tools (counted by quantity) or heavy equipment (by serial unit).
            "CREATE TABLE {$p}equipment_items (
                {$id},
                category VARCHAR(16) NOT NULL,
                name VARCHAR(150) NOT NULL,
                unit VARCHAR(16) NOT NULL DEFAULT 'pc',
                tracking VARCHAR(8) NOT NULL DEFAULT 'qty',
                rate_day {$money},
                rate_week {$money},
                rate_month {$money},
                min_days INT UNSIGNED NOT NULL DEFAULT 1,
                deposit_mode VARCHAR(8) NOT NULL DEFAULT 'percent',
                deposit_value {$money},
                replacement_cost {$money},
                damage_fee {$money},
                weight_kg DECIMAL(10,2) NOT NULL DEFAULT 0,
                active TINYINT(1) NOT NULL DEFAULT 1,
                {$ts},
                UNIQUE KEY uq_item_name (name),
                KEY ix_item_category (category)
            ) {$o}",

            // One serial-tracked heavy unit (backhoe, crane, generator...).
            "CREATE TABLE {$p}equipment_units (
                {$id},
                item_id BINARY(16) NOT NULL,
                serial VARCHAR(64) NOT NULL,
                hour_meter DECIMAL(10,1) NOT NULL DEFAULT 0,
                inspection_expiry DATE NULL,
                status VARCHAR(16) NOT NULL DEFAULT 'available',
                notes VARCHAR(255) NULL,
                {$ts},
                UNIQUE KEY uq_unit_serial (serial),
                KEY ix_unit_item (item_id),
                CONSTRAINT fk_unit_item FOREIGN KEY (item_id) REFERENCES {$p}equipment_items(id)
            ) {$o}",
        ];
    },
];
