<?php
class WPM_Maintenance {
    public static function on() {
        file_put_contents(ABSPATH . '.maintenance', '<?php $upgrading = time(); ?>');
        WPM_Logger::log('Maintenance ON');
    }

    public static function off() {
        @unlink(ABSPATH . '.maintenance');
        WPM_Logger::log('Maintenance OFF');
    }
}
