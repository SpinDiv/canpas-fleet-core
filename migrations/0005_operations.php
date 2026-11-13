<?php
/**
 * Milestone 4: running the fleet.
 *
 * driver_profiles  licence and phone for each company driver (a WordPress user with the cf_driver role).
 * order_assets     the specific truck or heavy unit given to an order for its dates. No asset can be
 *                  on two active orders whose dates overlap.
 * trips            a truck journey: the rental itself for trucks with a Canpas driver, or the delivery and
 *                  pickup runs for equipment. Holds the driver, odometer and the receiver's signature.
 * trip_events      every step on the road or in the yard (who, when, odometer, photos, note).
 * order_counts     equipment counted out at loading and back in at return (good / damaged / missing).
 * expenses         fuel and trip costs with a receipt photo.
 * orders (cols)    release and return details: odometer out and in, actual km, fuel shortfall, damage.
 */

declare(strict_types=1);

return [
    'version' => '0005_operations',
    'statements' => static function (string $p, string $o): array {
        $id = 'id BINARY(16) NOT NULL PRIMARY KEY';

        return [
            "CREATE TABLE {$p}driver_profiles (
                user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
                phone VARCHAR(32) NULL,
                license_number VARCHAR(40) NULL,
                license_expiry DATE NULL,
                active TINYINT(1) NOT NULL DEFAULT 1,
                updated_by BIGINT UNSIGNED NULL,
                updated_at DATETIME(6) NULL
            ) {$o}",

            "CREATE TABLE {$p}order_assets (
                {$id},
                order_id BINARY(16) NOT NULL,
                line_id BINARY(16) NULL,
                asset_type VARCHAR(8) NOT NULL,
                asset_id BINARY(16) NOT NULL,
                start_date DATE NOT NULL,
                end_date DATE NOT NULL,
                assigned_by BIGINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                KEY ix_asset_dates (asset_type, asset_id, start_date, end_date),
                KEY ix_asset_order (order_id),
                CONSTRAINT fk_asset_order FOREIGN KEY (order_id) REFERENCES {$p}orders(id)
            ) {$o}",

            "CREATE TABLE {$p}trips (
                {$id},
                order_id BINARY(16) NOT NULL,
                kind VARCHAR(10) NOT NULL,
                trip_date DATE NOT NULL,
                truck_id BINARY(16) NULL,
                driver_user_id BIGINT UNSIGNED NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'planned',
                odo_out INT UNSIGNED NULL,
                odo_back INT UNSIGNED NULL,
                receiver_name VARCHAR(120) NULL,
                receiver_signature VARCHAR(255) NULL,
                receiver_at DATETIME(6) NULL,
                created_by BIGINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                updated_at DATETIME(6) NULL,
                UNIQUE KEY uq_trip_kind (order_id, kind),
                KEY ix_trip_driver (driver_user_id, trip_date),
                KEY ix_trip_truck (truck_id, trip_date),
                CONSTRAINT fk_trip_order FOREIGN KEY (order_id) REFERENCES {$p}orders(id),
                CONSTRAINT fk_trip_truck FOREIGN KEY (truck_id) REFERENCES {$p}trucks(id)
            ) {$o}",

            "CREATE TABLE {$p}trip_events (
                {$id},
                order_id BINARY(16) NOT NULL,
                trip_id BINARY(16) NULL,
                step VARCHAR(16) NOT NULL,
                odometer INT UNSIGNED NULL,
                note VARCHAR(500) NULL,
                photos TEXT NULL,
                user_id BIGINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                KEY ix_event_order (order_id, created_at),
                KEY ix_event_trip (trip_id),
                CONSTRAINT fk_event_order FOREIGN KEY (order_id) REFERENCES {$p}orders(id)
            ) {$o}",

            "CREATE TABLE {$p}order_counts (
                {$id},
                order_id BINARY(16) NOT NULL,
                line_id BINARY(16) NOT NULL,
                direction VARCHAR(3) NOT NULL,
                qty_good INT UNSIGNED NOT NULL DEFAULT 0,
                qty_damaged INT UNSIGNED NOT NULL DEFAULT 0,
                qty_missing INT UNSIGNED NOT NULL DEFAULT 0,
                counted_by BIGINT UNSIGNED NOT NULL,
                created_at DATETIME(6) NOT NULL,
                UNIQUE KEY uq_count (order_id, line_id, direction),
                CONSTRAINT fk_count_order FOREIGN KEY (order_id) REFERENCES {$p}orders(id)
            ) {$o}",

            "CREATE TABLE {$p}expenses (
                {$id},
                user_id BIGINT UNSIGNED NOT NULL,
                truck_id BINARY(16) NULL,
                order_id BINARY(16) NULL,
                kind VARCHAR(12) NOT NULL,
                amount DECIMAL(12,2) NOT NULL,
                liters DECIMAL(8,2) NULL,
                odometer INT UNSIGNED NULL,
                receipt_file VARCHAR(255) NULL,
                receipt_name VARCHAR(190) NULL,
                note VARCHAR(255) NULL,
                spent_on DATE NOT NULL,
                created_at DATETIME(6) NOT NULL,
                KEY ix_exp_user (user_id, spent_on),
                KEY ix_exp_truck (truck_id, spent_on),
                KEY ix_exp_order (order_id),
                CONSTRAINT fk_exp_truck FOREIGN KEY (truck_id) REFERENCES {$p}trucks(id),
                CONSTRAINT fk_exp_order FOREIGN KEY (order_id) REFERENCES {$p}orders(id)
            ) {$o}",

            "ALTER TABLE {$p}orders
                ADD COLUMN loaded_at DATETIME(6) NULL,
                ADD COLUMN released_at DATETIME(6) NULL,
                ADD COLUMN handover_name VARCHAR(120) NULL,
                ADD COLUMN handover_signature VARCHAR(255) NULL,
                ADD COLUMN odo_out INT UNSIGNED NULL,
                ADD COLUMN odo_in INT UNSIGNED NULL,
                ADD COLUMN actual_km INT UNSIGNED NULL,
                ADD COLUMN fuel_short_liters DECIMAL(8,2) NULL,
                ADD COLUMN damage_note VARCHAR(500) NULL,
                ADD COLUMN returned_at DATETIME(6) NULL,
                ADD COLUMN returned_by BIGINT UNSIGNED NULL",
        ];
    },
];
