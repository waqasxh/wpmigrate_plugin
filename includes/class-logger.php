<?php
class WPM_Logger {

    public static function log($message) {
        $dir = WP_CONTENT_DIR . '/plugins/wp-migrate-lite/logs';
        if (!file_exists($dir)) mkdir($dir, 0755, true);

        $file = $dir . '/migrate-' . date('Y-m-d') . '.log';
        file_put_contents($file,
            '[' . date('H:i:s') . '] ' . $message . PHP_EOL,
            FILE_APPEND
        );
    }
}
