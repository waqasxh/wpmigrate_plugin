<?php

/**
 * Plugin Name: WP Migrate Lite
 * Description: Full-stack backup and restore toolkit for zero-downtime WordPress migrations.
 * Version: 1.2.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Author: NextGen Digital
 * Author URI: https://nextgendigital.uk/
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

// Registers `wp wpmb backup|restore|list`. Must happen here, at plugin
// top-level scope, not inside a plugins_loaded/cli_init callback - both were
// tried and empirically failed to register in time on this host's WP-CLI
// (2.12.0): a plugins_loaded-time direct call and a cli_init-hooked call both
// left `has_action('cli_init', ...)` false after normal bootstrap, so WP-CLI
// dispatch never saw the command. Top-level registration runs while
// WordPress is still including active plugins, ahead of any hook-timing
// ambiguity, matching WP-CLI's own documented integration pattern.
if (defined('WP_CLI') && WP_CLI) {
    WPMB_CLI_Command::register();
}
