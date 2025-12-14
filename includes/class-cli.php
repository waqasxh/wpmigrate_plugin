<?php
class WPM_CLI {

    public static function register() {
        WP_CLI::add_command('wpm migrate', [self::class, 'run']);
    }

    public static function run($args, $assoc) {
        [$old, $new] = $args;
        $dry = isset($assoc['dry-run']);

        if (WPM_Env::get() === 'production' && empty($assoc['force'])) {
            WP_CLI::error('Use --force on production');
        }

        WPM_Logger::log("CLI migrate started (dry=$dry)");
        WPM_Maintenance::on();
        WPM_Replace::run($old, $new, $dry);
        WPM_Maintenance::off();

        WP_CLI::success($dry ? 'Dry-run complete' : 'Migration complete');
    }
}
