<?php

declare(strict_types=1);

namespace Canpas\Fleet;

/**
 * Boot entry point. Each module registers its own hooks here as it is built.
 * Kept small so a broken module never blocks the rest of the site.
 */
final class Plugin
{
    public static function boot(): void
    {
        Portal\Router::register();
        Portal\Login::register();
        Portal\PublicPages::register();
        add_action('rest_api_init', [Http\Rest::class, 'register']);

        // Hourly housekeeping: release expired holds.
        add_action('canpas_fleet_hourly', [Domain\Orders::class, 'expireHolds']);
        if (!wp_next_scheduled('canpas_fleet_hourly')) {
            wp_schedule_event(time() + 300, 'hourly', 'canpas_fleet_hourly');
        }
    }

    /** Public URL of a file inside this plugin. Works from wp-content/plugins and from the Novamira sandbox. */
    public static function url(string $relative): string
    {
        $dir = wp_normalize_path(CANPAS_FLEET_DIR);
        $content = wp_normalize_path(WP_CONTENT_DIR);
        $base = str_starts_with($dir, $content)
            ? content_url(substr($dir, strlen($content)))
            : plugins_url('', CANPAS_FLEET_FILE) . '/';
        return trailingslashit($base) . ltrim($relative, '/');
    }
}
