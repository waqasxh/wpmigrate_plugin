<?php
class WPM_Env {
    public static function get() {
        return defined('WP_ENVIRONMENT_TYPE')
            ? WP_ENVIRONMENT_TYPE
            : 'production';
    }
}
