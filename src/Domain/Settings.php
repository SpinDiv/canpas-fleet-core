<?php

declare(strict_types=1);

namespace Canpas\Fleet\Domain;

use Canpas\Fleet\Audit\Audit;
use Canpas\Fleet\Support\DomainError;
use Canpas\Fleet\Tenancy\Actor;

/**
 * System settings, stored as one WordPress option. Every value is validated here,
 * so the rest of the system can trust what it reads.
 */
final class Settings
{
    public const OPTION = 'canpas_fleet_settings';

    /** key => [label, type, default, min, max] */
    public const FIELDS = [
        'home_page' => ['The site\'s home page opens', 'enum:portal|website', 'portal', null, null],
        'yard_address' => ['Yard address', 'text', '', null, null],
        'yard_lat' => ['Yard latitude', 'float', '', -90, 90],
        'yard_lng' => ['Yard longitude', 'float', '', -180, 180],
        'hold_hours' => ['Hold a booking for (hours)', 'int', 24, 1, 72],
        'km_tolerance_pct' => ['Km difference allowed before re-billing (%)', 'int', 5, 0, 50],
        'driver_km_basis' => ['Driver flat-rate km counted on', 'enum:round_trip|one_way', 'round_trip', null, null],
        'trips_per_rental' => ['Truck trips per equipment rental', 'enum:1|2', '2', null, null],
        'delivery_truck_type' => ['Truck type used to price equipment delivery', 'id', '', null, null],
        'payment_instructions' => ['Payment instructions (bank, GCash)', 'textarea', '', null, null],
        'notify_email' => ['Email for new sign-ups and alerts', 'email', '', null, null],
        'document_retention_days' => ['Delete customer IDs after (days)', 'int', 1095, 30, 3650],
        'fuel_price_per_liter' => ['Fuel price for shortfalls (₱ per litre)', 'float', 0, 0, 500],
        'service_interval_km' => ['Truck service every (km)', 'int', 10000, 1000, 100000],
    ];

    /** @return array<string, mixed> */
    public static function all(): array
    {
        $stored = get_option(self::OPTION, []);
        $out = [];
        foreach (self::FIELDS as $key => [, , $default]) {
            $out[$key] = is_array($stored) && array_key_exists($key, $stored) ? $stored[$key] : $default;
        }
        if ($out['notify_email'] === '') {
            $out['notify_email'] = (string) get_option('admin_email');
        }
        return $out;
    }

    public static function get(string $key): mixed
    {
        return self::all()[$key] ?? null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed> the saved settings
     */
    public static function save(Actor $actor, array $input): array
    {
        $actor->require('cf_manage_settings');
        $current = self::all();
        $clean = [];
        foreach (self::FIELDS as $key => [$label, $type, , $min, $max]) {
            $v = array_key_exists($key, $input) ? $input[$key] : $current[$key];
            $clean[$key] = self::clean($key, $label, $type, $v, $min, $max);
        }
        update_option(self::OPTION, $clean, false);
        Audit::log(null, $actor->userId, 'settings.update', 'settings', null, ['keys' => implode(',', array_keys($input))]);
        return $clean;
    }

    private static function clean(string $key, string $label, string $type, mixed $v, ?float $min, ?float $max): mixed
    {
        $v = is_string($v) ? trim(wp_unslash($v)) : $v;
        if (str_starts_with($type, 'enum:')) {
            $allowed = explode('|', substr($type, 5));
            if (!in_array((string) $v, $allowed, true)) {
                throw new DomainError('invalid_setting', "{$label}: choose one of the listed options.", ['field' => $key]);
            }
            return (string) $v;
        }
        switch ($type) {
            case 'int':
            case 'float':
                if (!is_numeric($v)) {
                    if ($type === 'float' && $v === '') {
                        return '';
                    }
                    throw new DomainError('invalid_setting', "{$label} must be a number.", ['field' => $key]);
                }
                $n = $type === 'int' ? (int) $v : (float) $v;
                if (($min !== null && $n < $min) || ($max !== null && $n > $max)) {
                    throw new DomainError('invalid_setting', "{$label} must be between {$min} and {$max}.", ['field' => $key]);
                }
                return $n;
            case 'id':
                $v = strtolower((string) $v);
                if ($v !== '' && !preg_match('/^[0-9a-f]{32}$/', $v)) {
                    throw new DomainError('invalid_setting', "{$label}: choose one of the options.", ['field' => $key]);
                }
                return $v;
            case 'email':
                if ($v !== '' && !is_email((string) $v)) {
                    throw new DomainError('invalid_setting', "{$label} is not a valid email address.", ['field' => $key]);
                }
                return sanitize_email((string) $v);
            case 'textarea':
                return sanitize_textarea_field((string) $v);
            default:
                return sanitize_text_field((string) $v);
        }
    }
}
