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
        'Bookings and billing' => ['hold_hours', 'km_tolerance_pct', 'driver_km_basis', 'trips_per_rental', 'delivery_truck_type'],
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
        $types = [];
        foreach (\Canpas\Fleet\Domain\Catalog::truckTypes(true) as $t) {
            $types[$t['id']] = $t['name'];
        }
        $form = Ui::form('settings', $fields, $values, ['delivery_truck_type' => $types], 'Save settings');
        $google = defined('CANPAS_GOOGLE_SERVER_KEY') && CANPAS_GOOGLE_SERVER_KEY !== ''
            ? Ui::pill('active', 'Google road distances on')
            : Ui::pill('pending', 'Google key not set — distances are estimates');
        $help = '<p>' . $google . '</p><ul class="list small">'
            . '<li><span>Road distances use the Google Routes API once <code>CANPAS_GOOGLE_SERVER_KEY</code> is added to wp-config.php. Until then each quote uses straight-line distance × 1.3, marked as an estimate.</span></li>'
            . '<li><span>The yard location is where every route distance starts. Paste the latitude and longitude from Google Maps (right-click the yard, click the numbers).</span></li>'
            . '<li><span>The hold time is how long a requested truck or item is kept for a customer while they complete the requirements.</span></li>'
            . '<li><span>Km difference: if the odometer shows more than this % above the quoted distance, the final bill uses the actual km.</span></li>'
            . '<li><span>Equipment delivery is priced as a truck trip with a Canpas driver, using the truck type chosen here (or the cheapest one offered), once or twice per rental.</span></li>'
            . '<li><span>Rental agreement template and email wording arrive with requirements in milestone 3.</span></li>'
            . '</ul>';
        Layout::page($actor, 'settings', 'Settings', '<div class="split">' . Ui::card('', $form) . Ui::card('How these are used', $help) . '</div>', 'Account');
    }
}
