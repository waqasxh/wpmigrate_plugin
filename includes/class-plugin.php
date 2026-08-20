<?php
class WPMB_Plugin
{
    private static $booted = false;

    public static function init()
    {
        if (self::$booted) {
            return;
        }

        self::$booted = true;

        try {
            WPMB_Paths::ensure_directories();
        } catch (Exception $e) {
            WPMB_Log::write('Initialization failed - directory creation error', ['error' => $e->getMessage()]);
            add_action('admin_notices', function () use ($e) {
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($e->getMessage()));
            });
            return;
        }

        if (!class_exists('ZipArchive')) {
            WPMB_Log::write('Initialization failed - ZipArchive extension missing');
            add_action('admin_notices', function () {
                printf('<div class="notice notice-error"><p>%s</p></div>', esc_html__('WP Migrate Lite requires the PHP Zip extension. Contact your host to enable it.', 'wpmb'));
            });
            return;
        }

        WPMB_Admin_Page::init();
        add_action('admin_post_wpmb_download', ['WPMB_Download_Handler', 'serve']);
        add_action('admin_post_nopriv_wpmb_download', ['WPMB_Download_Handler', 'serve']);
        add_action('init', ['WPMB_Token', 'purge_expired']);

        // See class-status-endpoint.php - the enable_maintenance_mode
        // filter (regular plugin AND mu-plugin, both tried) never fires
        // reliably in time on this host, so status is exposed via a
        // standalone endpoint that doesn't depend on any WordPress hook.
        WPMB_Status_Endpoint::ensure_installed();

        // CLI command registration lives in wp-migrate-lite.php at plugin
        // top-level scope (see comment there) - plugins_loaded fires too late
        // for WP-CLI to see the command.

        if (!wp_next_scheduled('wpmb_daily_housekeeping')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wpmb_daily_housekeeping');
            WPMB_Log::write('Scheduled daily housekeeping task');
        }

        add_action('wpmb_daily_housekeeping', ['WPMB_Backup_Manager', 'housekeeping']);
    }

    public static function activate()
    {
        if (!class_exists('ZipArchive')) {
            wp_die(esc_html__('WP Migrate Lite cannot activate without the PHP Zip extension.', 'wpmb'));
        }

        try {
            WPMB_Paths::ensure_directories();
        } catch (Exception $e) {
            wp_die(esc_html__('WP Migrate Lite cannot initialise storage. Verify write permissions on wp-content.', 'wpmb'));
        }

        WPMB_Status_Endpoint::ensure_installed();

        if (!wp_next_scheduled('wpmb_daily_housekeeping')) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'wpmb_daily_housekeeping');
        }
    }

    public static function deactivate()
    {
        wp_clear_scheduled_hook('wpmb_daily_housekeeping');
    }
}
