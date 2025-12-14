<?php
class WPMB_Log
{
    private static $log_dir = null;
    private static $initialized = false;

    private static function init()
    {
        if (self::$initialized) {
            return true;
        }

        try {
            self::$log_dir = WPMB_Paths::logs_dir();
        } catch (Throwable $e) {
            // Fallback to wp-content if WPMB_Paths fails
            if (defined('WP_CONTENT_DIR')) {
                self::$log_dir = WP_CONTENT_DIR . '/wpmb-backups/logs';
            } else {
                error_log('[WP Migrate Lite] Cannot determine log directory');
                return false;
            }
        }

        // Ensure directory exists
        if (!is_dir(self::$log_dir)) {
            if (function_exists('wp_mkdir_p')) {
                $created = wp_mkdir_p(self::$log_dir);
            } else {
                $created = @mkdir(self::$log_dir, 0755, true);
            }

            if (!$created) {
                error_log('[WP Migrate Lite] Failed to create log directory: ' . self::$log_dir);
                return false;
            }
        }

        // Test write permissions
        $test_file = self::$log_dir . '/test-' . uniqid() . '.tmp';
        if (@file_put_contents($test_file, 'test') === false) {
            error_log('[WP Migrate Lite] Log directory not writable: ' . self::$log_dir);
            return false;
        }
        @unlink($test_file);

        self::$initialized = true;
        return true;
    }

    public static function write($message, array $context = [])
    {
        // Build log line
        $timestamp = gmdate('Y-m-d H:i:s');
        $line = sprintf('[%s UTC] %s', $timestamp, $message);

        if (!empty($context)) {
            if (function_exists('wp_json_encode')) {
                $line .= ' ' . wp_json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            } else {
                $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
        }

        // Always log to PHP error log as backup
        error_log('[WP Migrate Lite] ' . $message . (empty($context) ? '' : ' ' . json_encode($context)));

        // Try to write to file
        if (!self::init()) {
            return;
        }

        $filename = 'wpmb-' . gmdate('Y-m-d') . '.log';
        $filepath = self::$log_dir . DIRECTORY_SEPARATOR . $filename;

        // Write to file with error handling
        $result = @file_put_contents($filepath, $line . PHP_EOL, FILE_APPEND | LOCK_EX);

        if ($result === false) {
            error_log('[WP Migrate Lite] Failed to write to log file: ' . $filepath);
            // Try alternative location
            $alt_path = sys_get_temp_dir() . '/wpmb-' . gmdate('Y-m-d') . '.log';
            @file_put_contents($alt_path, $line . PHP_EOL, FILE_APPEND);
            error_log('[WP Migrate Lite] Wrote to fallback log: ' . $alt_path);
        }
    }

    public static function get_log_dir()
    {
        self::init();
        return self::$log_dir;
    }

    public static function get_recent_entries($count = 1000)
    {
        if (!self::init()) {
            return [];
        }

        $files = glob(self::$log_dir . '/wpmb-*.log');
        if (!$files) {
            return [];
        }

        rsort($files);
        $entries = [];

        foreach ($files as $file) {
            $lines = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines) {
                $entries = array_merge($entries, $lines);
            }
        }

        // Return most recent entries up to $count, newest first
        $entries = array_reverse($entries);
        return $count > 0 ? array_slice($entries, 0, $count) : $entries;
    }
}
