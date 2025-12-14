<?php
class WPMB_Paths
{
    private const BASE_DIR = 'wpmb-backups';

    public static function ensure_directories()
    {
        $paths = [
            self::base_dir(),
            self::archives_dir(),
            self::logs_dir(),
            self::temp_dir(),
        ];

        foreach ($paths as $path) {
            if (!self::mkdir($path)) {
                throw new RuntimeException(sprintf('WP Migrate Blueprint cannot create %s. Check filesystem permissions.', $path));
            }
        }
    }

    public static function base_dir()
    {
        return trailingslashit(WP_CONTENT_DIR) . self::BASE_DIR;
    }

    public static function archives_dir()
    {
        return trailingslashit(self::base_dir()) . 'archives';
    }

    public static function logs_dir()
    {
        return trailingslashit(self::base_dir()) . 'logs';
    }

    public static function temp_dir()
    {
        return trailingslashit(self::base_dir()) . 'temp';
    }

    public static function unique_archive_path($slug)
    {
        $sanitized = sanitize_title($slug);
        if ($sanitized === '') {
            $sanitized = 'snapshot';
        }
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $host = $host ? sanitize_title($host) : 'site';

        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        switch ($env) {
            case 'local':
            case 'development':
                $env_slug = 'local';
                break;
            case 'staging':
                $env_slug = 'staging';
                break;
            default:
                $env_slug = 'live';
        }

        $filename = sprintf('%s-%s-%s-%s.zip', gmdate('Ymd-His'), $host, $env_slug, $sanitized);
        $filename = wp_unique_filename(self::archives_dir(), $filename);
        return trailingslashit(self::archives_dir()) . $filename;
    }

    public static function resolve_archive($id)
    {
        $path = trailingslashit(self::archives_dir()) . $id . '.zip';
        return file_exists($path) ? $path : null;
    }

    public static function temp_file($prefix = 'wpmb')
    {
        $file = wp_tempnam($prefix);
        if (!$file) {
            throw new RuntimeException('Unable to allocate temporary storage.');
        }
        return $file;
    }

    public static function cleanup_temp()
    {
        $dir = self::temp_dir();
        foreach (glob($dir . '/*') as $file) {
            if (is_dir($file)) {
                self::rrmdir($file);
            } else {
                @unlink($file);
            }
        }
    }

    public static function rrmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                self::rrmdir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }

    private static function mkdir($path)
    {
        if (is_dir($path)) {
            return true;
        }
        if (function_exists('wp_mkdir_p')) {
            return wp_mkdir_p($path);
        }
        return mkdir($path, 0755, true);
    }
}
