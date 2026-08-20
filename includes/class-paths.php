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

        self::ensure_directory_protected();
    }

    /**
     * base_dir() lives under wp-content, which web servers serve as static
     * files by default - without this, full site backups (database + files)
     * are downloadable by anyone who knows or guesses the filename, no
     * authentication required. Filenames are predictable
     * (YYYYMMDD-HHMMSS-host-env-label.zip), so this isn't theoretical.
     * Self-healing like the other auto-installed files: rewritten whenever
     * it's missing or stale, e.g. after a restore rolls wp-content back to
     * an older snapshot that predates this fix.
     *
     * poll.php is deliberately exempted - it's the token-gated status
     * endpoint that must stay reachable over plain HTTP even while this
     * exact directory holds files that should otherwise never be served.
     */
    private static function ensure_directory_protected()
    {
        $target = trailingslashit(self::base_dir()) . '.htaccess';
        $contents = <<<'HTACCESS'
# Auto-installed by WP Migrate Lite. This directory holds full site backups
# (database + files) and operation logs - nothing here should ever be served
# directly over HTTP. Downloads go through the plugin's token-gated handler
# instead. poll.php is the one intentional exception: a status endpoint with
# its own token check, needed so progress can be checked even while
# WordPress's native maintenance mode is blocking normal requests.
<IfModule mod_authz_core.c>
    Require all denied
    <Files "poll.php">
        Require all granted
    </Files>
</IfModule>
<IfModule !mod_authz_core.c>
    Order allow,deny
    Deny from all
    <Files "poll.php">
        Order deny,allow
        Allow from all
    </Files>
</IfModule>
HTACCESS;

        if (file_exists($target) && file_get_contents($target) === $contents) {
            return;
        }

        @file_put_contents($target, $contents);
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
