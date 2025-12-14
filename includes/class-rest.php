<?php
class WPM_REST {

    public static function register() {
        register_rest_route('wpm/v1', '/migrate', [
            'methods'  => 'POST',
            'callback' => [self::class, 'handle'],
            'permission_callback' => fn() => current_user_can('manage_options')
        ]);
    }

    public static function handle($req) {
        $old = $req['old'];
        $new = $req['new'];
        $dry = (bool) $req['dry'];
        $force = (bool) $req['force'];

        if (WPM_Env::get() === 'production' && !$force) {
            return new WP_Error('forbidden', 'Force required', ['status' => 403]);
        }

        WPM_Logger::log("REST migrate started (dry=$dry)");
        WPM_Maintenance::on();
        WPM_Replace::run($old, $new, $dry);
        WPM_Maintenance::off();

        return ['status' => 'ok', 'dry' => $dry];
    }
}
