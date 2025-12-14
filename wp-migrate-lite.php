<?php

/**
 * Plugin Name: WP Migrate Lite
 * Description: Full-stack backup and restore toolkit for zero-downtime WordPress migrations.
 * Version: 1.1.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: NextGen Digital
 * License: GPL-2.0-or-later
 */

if (!defined('ABSPATH')) {
    exit;
}

spl_autoload_register(function ($class) {
    if (strpos($class, 'WPMB_') !== 0) {
        return;
    }

    $relative = strtolower(str_replace('_', '-', substr($class, 5)));
    $path = __DIR__ . '/includes/class-' . $relative . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});

register_activation_hook(__FILE__, ['WPMB_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['WPMB_Plugin', 'deactivate']);

add_action('plugins_loaded', ['WPMB_Plugin', 'init']);
