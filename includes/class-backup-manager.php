<?php
class WPMB_Backup_Manager
{
    private const DEFAULT_LABEL = 'full-site';
    private const DEFAULT_RETENTION = 10;

    public static function create(array $args = [])
    {
        $options = wp_parse_args($args, [
            'label' => self::DEFAULT_LABEL,
            'include_files' => true,
            'include_database' => true,
            'retention' => self::DEFAULT_RETENTION,
        ]);

        if (!$options['include_files'] && !$options['include_database']) {
            throw new InvalidArgumentException('Nothing to backup. Enable files and/or database.');
        }

        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('ZipArchive is required but missing. Enable the PHP zip extension.');
        }

        $lock = WPMB_Lock::acquire('backup', HOUR_IN_SECONDS);

        try {
            $slug = $options['label'] ?: self::DEFAULT_LABEL;
            $archivePath = WPMB_Paths::unique_archive_path($slug);
            $zip = new ZipArchive();

            if ($zip->open($archivePath, ZipArchive::CREATE) !== true) {
                throw new RuntimeException('Unable to initialize backup archive.');
            }

            $manifest = self::build_manifest($archivePath, $options);
            $tables = [];
            $tempFiles = [];

            WPMB_Log::write('Backup started', [
                'label' => $manifest['label'],
                'archive' => basename($archivePath),
            ]);

            if ($options['include_database']) {
                $dbFile = WPMB_Paths::temp_file('wpmb_db');
                $dumper = new WPMB_Database_Dump();
                $tables = $dumper->generate($dbFile);
                $zip->addFile($dbFile, 'database.sql');
                $tempFiles[] = $dbFile;
                $manifest['tables'] = $tables;
            }

            if ($options['include_files']) {
                $archiver = new WPMB_File_Archiver($zip);
                $archiver->add_directory(WP_CONTENT_DIR, 'wp-content');
            }

            $zip->addFromString('manifest.json', wp_json_encode($manifest, JSON_PRETTY_PRINT));

            if (!$zip->close()) {
                throw new RuntimeException('Failed to finalize backup archive.');
            }

            foreach ($tempFiles as $file) {
                @unlink($file);
            }

            $checksum = md5_file($archivePath);
            $size = filesize($archivePath);

            $token = WPMB_Token::issue($archivePath, DAY_IN_SECONDS);
            $downloadUrl = add_query_arg([
                'action' => 'wpmb_download',
                'token' => $token,
            ], admin_url('admin-post.php'));

            $summary = array_merge($manifest, [
                'checksum' => $checksum,
                'filesize' => $size,
                'path' => $archivePath,
                'download_token' => $token,
                'download_url' => $downloadUrl,
            ]);

            WPMB_Log::write('Backup completed', [
                'archive' => basename($archivePath),
                'size' => $size,
                'checksum' => $checksum,
            ]);

            self::enforce_retention((int) $options['retention']);

            return $summary;
        } finally {
            WPMB_Lock::release($lock);
        }
    }

    public static function list_archives()
    {
        $archives = glob(trailingslashit(WPMB_Paths::archives_dir()) . '*.zip');
        if (!$archives) {
            return [];
        }

        $records = [];
        foreach ($archives as $file) {
            $metadata = self::read_manifest($file);
            if (!$metadata) {
                continue;
            }
            $metadata['filesize'] = filesize($file);
            $metadata['checksum'] = md5_file($file);
            $metadata['path'] = $file;
            $records[] = $metadata;
        }

        usort($records, function ($a, $b) {
            return strcmp($b['created_at_gmt'], $a['created_at_gmt']);
        });

        return $records;
    }

    public static function read_manifest($file)
    {
        $zip = new ZipArchive();
        if ($zip->open($file) !== true) {
            return null;
        }

        $json = $zip->getFromName('manifest.json');
        $zip->close();

        if (!$json) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        if (!isset($data['id'])) {
            $data['id'] = basename($file, '.zip');
        }

        return $data;
    }

    public static function delete($id)
    {
        $path = self::resolve_id($id);
        if (!$path) {
            return false;
        }

        return self::delete_by_path($path);
    }

    public static function resolve_id($id)
    {
        $path = trailingslashit(WPMB_Paths::archives_dir()) . $id . '.zip';
        return self::validate_path($path);
    }

    public static function housekeeping()
    {
        WPMB_Token::purge_expired();
        self::enforce_retention(self::DEFAULT_RETENTION);
        WPMB_Paths::cleanup_temp();
    }

    public static function ingest($filePath, $label = 'imported')
    {
        if (!file_exists($filePath)) {
            throw new RuntimeException('Remote archive missing.');
        }

        $dest = WPMB_Paths::unique_archive_path($label);
        if (!copy($filePath, $dest)) {
            throw new RuntimeException('Failed to store imported archive.');
        }

        $manifest = self::read_manifest($dest);
        if (!$manifest) {
            unlink($dest);
            throw new RuntimeException('Imported archive manifest invalid.');
        }

        $manifest['path'] = $dest;
        $manifest['filesize'] = filesize($dest);
        $manifest['checksum'] = md5_file($dest);

        WPMB_Log::write('Backup ingested', [
            'archive' => basename($dest),
            'source' => basename($filePath),
        ]);

        return $manifest;
    }

    private static function build_manifest($archivePath, $options)
    {
        global $wpdb;
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $host = $host ?: 'site';
        $environment = self::environment_slug();

        return [
            'id' => basename($archivePath, '.zip'),
            'label' => $options['label'],
            'created_at_gmt' => gmdate('c'),
            'site_url' => site_url(),
            'home_url' => home_url(),
            'origin_host' => $host,
            'environment' => $environment,
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'table_prefix' => $wpdb->prefix,
            'include_files' => (bool) $options['include_files'],
            'include_database' => (bool) $options['include_database'],
            'tables' => [],
        ];
    }

    private static function enforce_retention($limit)
    {
        $limit = (int) $limit;
        if ($limit <= 0) {
            return;
        }

        $archives = self::list_archives();
        if (count($archives) <= $limit) {
            return;
        }

        $toRemove = array_slice($archives, $limit);
        foreach ($toRemove as $record) {
            if (isset($record['path'])) {
                self::delete_by_path($record['path'], ['message' => 'Backup pruned by retention policy']);
            }
        }
    }

    private static function resolve_id_or_fail($id)
    {
        $path = self::resolve_id($id);
        if (!$path) {
            throw new RuntimeException('Backup archive not found.');
        }
        return $path;
    }

    private static function environment_slug()
    {
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        switch ($env) {
            case 'local':
            case 'development':
                return 'local';
            case 'staging':
                return 'staging';
            default:
                return 'live';
        }
    }

    public static function validate_path($path)
    {
        $normalized = self::normalize_archive_path($path, true);
        return $normalized && file_exists($normalized) ? $normalized : null;
    }

    public static function delete_by_path($path, array $context = [])
    {
        $path = self::validate_path($path);
        if (!$path) {
            return false;
        }

        if (!unlink($path)) {
            return false;
        }

        $message = isset($context['message']) ? $context['message'] : 'Backup deleted';
        unset($context['message']);
        $context['archive'] = basename($path);

        WPMB_Log::write($message, $context);
        return true;
    }

    private static function normalize_archive_path($path, $mustExist = false)
    {
        if (!$path) {
            return null;
        }

        $base = wp_normalize_path(WPMB_Paths::archives_dir());
        $candidate = wp_normalize_path($path);

        if ($mustExist) {
            $real = realpath($candidate);
            if (!$real) {
                return null;
            }
            $candidate = wp_normalize_path($real);
        }

        if (strpos($candidate, $base) !== 0) {
            return null;
        }

        return $candidate;
    }
}
