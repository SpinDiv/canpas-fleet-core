<?php
/**
 * Plugin Name: Canpas Fleet Core
 * Description: Truck and equipment rental, dispatch and fleet system for Canpas Philippines.
 * Version: 0.2.0
 * Author: Spindiv Kinetics
 * Author URI: https://spindiv.com
 * Requires PHP: 8.2
 * Requires at least: 6.6
 * Text Domain: canpas-fleet
 *
 * All code lives in ./src (namespace Canpas\Fleet). This file only defines paths,
 * wires the autoloader and registers the boot hook and the WP-CLI command.
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('CANPAS_FLEET_VERSION')) {
    define('CANPAS_FLEET_VERSION', '0.2.0');
    define('CANPAS_FLEET_FILE', __FILE__);
    define('CANPAS_FLEET_DIR', __DIR__ . '/');
    // Private files (IDs, licences, signed PDFs, photos) live outside the web root, never in this folder.
    if (!defined('CANPAS_FLEET_PRIVATE_DIR')) {
        define('CANPAS_FLEET_PRIVATE_DIR', dirname(rtrim(ABSPATH, '/')) . '/canpas-private/');
    }

    spl_autoload_register(static function (string $class): void {
        $prefix = 'Canpas\\Fleet\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $file = CANPAS_FLEET_DIR . 'src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    });

    add_action('init', [\Canpas\Fleet\Plugin::class, 'boot'], 5);

    if (defined('WP_CLI') && WP_CLI) {
        \WP_CLI::add_command('canpas', \Canpas\Fleet\Cli\Command::class);
    }
}
