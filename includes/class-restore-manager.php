<?php
class WPMB_Restore_Manager
{
    public static function restore(array $args = [])
    {
        $options = wp_parse_args($args, [
            'archive_id' => null,
            'archive_path' => null,
            'source_url' => null,
            'drop_tables' => true,
            'safety_backup' => true,
            'label' => 'incoming',
        ]);

        $lock = WPMB_Lock::acquire('restore', HOUR_IN_SECONDS);

        try {
            global $wpdb;
            $path = self::resolve_archive_path($options);
            if (!$path || !file_exists($path)) {
                throw new RuntimeException('Backup archive not found for restore.');
            }

            WPMB_Log::write('Restore started', [
                'source' => basename($path),
                'safety_backup' => (bool) $options['safety_backup'],
            ]);

            if ($options['safety_backup']) {
                $snapshot = WPMB_Backup_Manager::create(['label' => 'pre-restore']);
                WPMB_Log::write('Safety backup captured', [
                    'archive' => $snapshot['id'] ?? basename($snapshot['path']),
                ]);
            }

            $tempDir = trailingslashit(WPMB_Paths::temp_dir()) . uniqid('restore_', true);
            if (!wp_mkdir_p($tempDir)) {
                throw new RuntimeException('Unable to allocate restore workspace.');
            }

            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                throw new RuntimeException('Cannot open archive for restore.');
            }

            if (!$zip->extractTo($tempDir)) {
                $zip->close();
                throw new RuntimeException('Failed to extract archive.');
            }

            $manifestContent = $zip->getFromName('manifest.json');
            $zip->close();

            if (!$manifestContent) {
                throw new RuntimeException('Restore manifest missing.');
            }

            $manifest = json_decode($manifestContent, true);
            if (!is_array($manifest)) {
                throw new RuntimeException('Restore manifest corrupted.');
            }

            if (!empty($manifest['include_database']) && file_exists($tempDir . '/database.sql')) {
                $importer = new WPMB_Database_Importer();
                $knownTables = $manifest['tables'] ?? [];
                $importer->import($tempDir . '/database.sql', $knownTables, (bool) $options['drop_tables']);

                $sourcePrefix = $manifest['table_prefix'] ?? '';
                $tableInventory = $knownTables ?: $importer->list_tables();
                $importer->ensure_prefix($tableInventory, $sourcePrefix, $wpdb->prefix);

                $manifest['table_prefix'] = $wpdb->prefix;
                $manifest['tables'] = $importer->list_tables($wpdb->prefix . '%');

                $required = ['options', 'users', 'usermeta', 'posts', 'postmeta'];
                $missing = [];
                foreach ($required as $suffix) {
                    if (!$importer->table_exists($wpdb->prefix . $suffix)) {
                        $missing[] = $wpdb->prefix . $suffix;
                    }
                }

                if ($missing) {
                    throw new RuntimeException('Restore aborted: required tables missing after import: ' . implode(', ', $missing));
                }
            }

            if (!empty($manifest['include_files']) && is_dir($tempDir . '/wp-content')) {
                WPMB_File_Archiver::copy_directory($tempDir . '/wp-content', WP_CONTENT_DIR);
            }

            WPMB_Log::write('Restore finished', [
                'source' => basename($path),
                'drop_tables' => (bool) $options['drop_tables'],
            ]);

            return $manifest;
        } finally {
            WPMB_Lock::release($lock);
            WPMB_Paths::cleanup_temp();
        }
    }

    private static function resolve_archive_path($options)
    {
        if (!empty($options['archive_path'])) {
            $validated = WPMB_Backup_Manager::validate_path($options['archive_path']);
            if ($validated) {
                return $validated;
            }
        }

        if (!empty($options['archive_id'])) {
            return WPMB_Backup_Manager::resolve_id(sanitize_file_name($options['archive_id']));
        }

        if (!empty($options['source_url'])) {
            $downloaded = download_url(esc_url_raw($options['source_url']));
            if (is_wp_error($downloaded)) {
                throw new RuntimeException('Failed to download remote archive: ' . $downloaded->get_error_message());
            }

            $manifest = WPMB_Backup_Manager::ingest($downloaded, $options['label'] ?: 'remote');
            @unlink($downloaded);
            return $manifest['path'];
        }

        return null;
    }
}
