<?php
class WPMB_Log
{
    public static function write($message, array $context = [])
    {
        try {
            $dir = WPMB_Paths::logs_dir();
        } catch (Throwable $e) {
            return;
        }

        if (!is_dir($dir) && !wp_mkdir_p($dir)) {
            error_log('[WP Migrate Lite] Unable to initialise log directory: ' . $dir);
            return;
        }

        $line = sprintf('[%s] %s', gmdate('Y-m-d H:i:s'), $message);
        if ($context) {
            $line .= ' ' . wp_json_encode($context);
        }

        $file = $dir . DIRECTORY_SEPARATOR . 'wpmb-' . gmdate('Y-m-d') . '.log';
        if (@file_put_contents($file, $line . PHP_EOL, FILE_APPEND) === false) {
            error_log('[WP Migrate Lite] Failed to write log entry: ' . $line);
        }
    }
}
