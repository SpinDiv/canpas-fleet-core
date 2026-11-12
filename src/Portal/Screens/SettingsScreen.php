<?php

declare(strict_types=1);

namespace Canpas\Fleet\Portal\Screens;

use Canpas\Fleet\Domain\Settings;
use Canpas\Fleet\Portal\Layout;
use Canpas\Fleet\Portal\Ui;
use Canpas\Fleet\Tenancy\Actor;

/** System settings (admin only). */
final class SettingsScreen
{
    private const GROUPS = [
        'Yard' => ['yard_address', 'yard_lat', 'yard_lng'],
        'Bookings and billing' => ['hold_hours', 'km_tolerance_pct', 'driver_km_basis', 'trips_per_rental'],
        'Payments' => ['payment_instructions'],
        'Notifications and privacy' => ['notify_email', 'document_retention_days'],
    ];

    public static function render(Actor $actor): void
    {
        $values = Settings::all();
        $fields = [];
        foreach (self::GROUPS as $keys) {
            foreach ($keys as $k) {
                [$label, $type, , $min, $max] = Settings::FIELDS[$k];
                $fields[$k] = [$label, $type, false, $min, $max];
            }
        }
        $form = Ui::form('settings', $fields, $values, [], 'Save settings');
        $help = '<ul class="list small">'
            . '<li><span>The yard location is where every route distance starts. Paste the latitude and longitude from Google Maps (right-click the yard, click the numbers).</span></li>'
            . '<li><span>The hold time is how long a requested truck or item is kept for a customer while they complete the requirements.</span></li>'
            . '<li><span>Km difference: if the odometer shows more than this % above the quoted distance, the final bill uses the actual km.</span></li>'
            . '<li><span>Rental agreement template and email wording arrive with requirements in milestone 3.</span></li>'
            . '</ul>';
        Layout::page($actor, 'settings', 'Settings', '<div class="split">' . Ui::card('', $form) . Ui::card('How these are used', $help) . '</div>', 'Account');
    }
}
