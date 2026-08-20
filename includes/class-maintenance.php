<?php
class WPMB_Maintenance {
    public static function on() {
        file_put_contents(ABSPATH . '.maintenance', '<?php $upgrading = time(); ?>');
        WPMB_Log::write('Maintenance mode ON');
    }

    public static function off() {
        @unlink(ABSPATH . '.maintenance');
        WPMB_Log::write('Maintenance mode OFF');
    }
}
