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
        $safetyBackupPath = null;
        $restoreSuccessful = false;

        try {
            global $wpdb;
            $path = self::resolve_archive_path($options);
            if (!$path || !file_exists($path)) {
                WPMB_Log::write('Restore failed - archive not found', ['requested_path' => $options['archive_path'] ?? '', 'archive_id' => $options['archive_id'] ?? '']);
                throw new RuntimeException('Backup archive not found. Please verify the file exists and try again.');
            }

            WPMB_Log::write('Restore started', [
                'source' => basename($path),
                'safety_backup' => (bool) $options['safety_backup'],
                'drop_tables' => (bool) $options['drop_tables'],
                'filesize' => size_format(filesize($path)),
            ]);

            // Create safety backup before making any changes
            if ($options['safety_backup']) {
                WPMB_Log::write('Creating safety backup before restore - this ensures we can rollback if anything fails');
                try {
                    $snapshot = WPMB_Backup_Manager::create(['label' => 'pre-restore-safety']);
                    $safetyBackupPath = $snapshot['path'] ?? null;
                    WPMB_Log::write('Safety backup captured successfully', [
                        'archive' => $snapshot['id'] ?? basename($snapshot['path']),
                        'filesize' => size_format($snapshot['filesize'] ?? 0),
                        'path' => $safetyBackupPath,
                    ]);
                } catch (Exception $e) {
                    WPMB_Log::write('CRITICAL: Safety backup failed - aborting restore', ['error' => $e->getMessage()]);
                    throw new RuntimeException('Cannot create safety backup. Restore aborted to protect your data. Error: ' . $e->getMessage());
                }
            }

            $tempDir = trailingslashit(WPMB_Paths::temp_dir()) . uniqid('restore_', true);
            if (!wp_mkdir_p($tempDir)) {
                WPMB_Log::write('Restore failed - unable to create temp directory', ['temp_dir' => $tempDir]);
                throw new RuntimeException('Unable to create temporary workspace. Check directory permissions.');
            }

            WPMB_Log::write('Extracting archive', ['temp_dir' => $tempDir]);
            $zip = new ZipArchive();
            if ($zip->open($path) !== true) {
                WPMB_Log::write('Restore failed - cannot open archive');
                throw new RuntimeException('Cannot open backup archive. The file may be corrupted. Please download and upload it again.');
            }

            if (!$zip->extractTo($tempDir)) {
                $zip->close();
                WPMB_Log::write('Restore failed - extraction error');
                throw new RuntimeException('Failed to extract backup archive. The file may be corrupted or incomplete.');
            }

            WPMB_Log::write('Archive extracted successfully', ['num_files' => $zip->numFiles]);

            $manifestContent = $zip->getFromName('manifest.json');
            $zip->close();

            if (!$manifestContent) {
                throw new RuntimeException('Backup manifest is missing. This backup may be corrupted or incomplete.');
            }

            $manifest = json_decode($manifestContent, true);
            if (!is_array($manifest)) {
                throw new RuntimeException('Backup manifest is corrupted. Cannot proceed with restore.');
            }

            // Database restoration
            if (!empty($manifest['include_database']) && file_exists($tempDir . '/database.sql')) {
                WPMB_Log::write('Starting database import', [
                    'sql_file' => 'database.sql',
                    'source_prefix' => $manifest['table_prefix'] ?? 'unknown',
                    'target_prefix' => $wpdb->prefix,
                    'drop_existing' => (bool) $options['drop_tables'],
                ]);

                try {
                    $importer = new WPMB_Database_Importer();
                    $knownTables = $manifest['tables'] ?? [];
                    $importer->import($tempDir . '/database.sql', $knownTables, (bool) $options['drop_tables']);

                    WPMB_Log::write('Database imported, processing table prefixes');
                    $sourcePrefix = $manifest['table_prefix'] ?? '';
                    $tableInventory = $knownTables ?: $importer->list_tables();
                    $importer->ensure_prefix($tableInventory, $sourcePrefix, $wpdb->prefix);

                    $manifest['table_prefix'] = $wpdb->prefix;
                    $manifest['tables'] = $importer->list_tables($wpdb->prefix . '%');
                    WPMB_Log::write('Table prefix updated', ['imported_tables' => count($manifest['tables'])]);

                    // Verify critical tables exist
                    $required = ['options', 'users', 'usermeta', 'posts', 'postmeta'];
                    $missing = [];
                    foreach ($required as $suffix) {
                        if (!$importer->table_exists($wpdb->prefix . $suffix)) {
                            $missing[] = $wpdb->prefix . $suffix;
                        }
                    }

                    if ($missing) {
                        WPMB_Log::write('Restore failed - missing required tables', ['missing' => implode(', ', $missing)]);
                        throw new RuntimeException('Critical WordPress tables are missing after import: ' . implode(', ', $missing) . '. The backup may be incomplete.');
                    }

                    WPMB_Log::write('All required WordPress tables present');

                    // Replace URLs from source to target environment
                    $oldSiteUrl = $manifest['site_url'] ?? '';
                    $oldHomeUrl = $manifest['home_url'] ?? '';
                    $newSiteUrl = site_url();
                    $newHomeUrl = home_url();

                    WPMB_Log::write('Checking URL replacement', [
                        'old_site_url' => $oldSiteUrl,
                        'new_site_url' => $newSiteUrl,
                        'old_home_url' => $oldHomeUrl,
                        'new_home_url' => $newHomeUrl,
                    ]);

                    if ($oldSiteUrl && $oldSiteUrl !== $newSiteUrl) {
                        WPMB_Log::write('Replacing site URLs in database', [
                            'from' => $oldSiteUrl,
                            'to' => $newSiteUrl,
                        ]);
                        WPMB_Replace::run($oldSiteUrl, $newSiteUrl);
                    }

                    if ($oldHomeUrl && $oldHomeUrl !== $newHomeUrl && $oldHomeUrl !== $oldSiteUrl) {
                        WPMB_Log::write('Replacing home URLs in database', [
                            'from' => $oldHomeUrl,
                            'to' => $newHomeUrl,
                        ]);
                        WPMB_Replace::run($oldHomeUrl, $newHomeUrl);
                    }

                    // Update options table directly for safety
                    WPMB_Log::write('Updating WordPress options directly');
                    $wpdb->update(
                        $wpdb->options,
                        ['option_value' => $newSiteUrl],
                        ['option_name' => 'siteurl']
                    );
                    $wpdb->update(
                        $wpdb->options,
                        ['option_value' => $newHomeUrl],
                        ['option_name' => 'home']
                    );

                    // Repair and optimize database tables
                    WPMB_Log::write('Repairing and optimizing database tables');
                    $repaired = 0;
                    $optimized = 0;
                    foreach ($manifest['tables'] as $table) {
                        $result = $wpdb->query("REPAIR TABLE `{$table}`");
                        if ($result !== false) {
                            $repaired++;
                        }
                        $result = $wpdb->query("OPTIMIZE TABLE `{$table}`");
                        if ($result !== false) {
                            $optimized++;
                        }
                    }
                    WPMB_Log::write('Database maintenance completed', [
                        'tables_repaired' => $repaired,
                        'tables_optimized' => $optimized,
                    ]);
                } catch (Exception $e) {
                    WPMB_Log::write('CRITICAL: Database import failed', [
                        'error' => $e->getMessage(),
                        'will_rollback' => $safetyBackupPath !== null,
                    ]);
                    throw new RuntimeException('Database import failed: ' . $e->getMessage() . ($safetyBackupPath ? ' Your site will be restored to its previous state.' : ''));
                }
            }

            // File restoration
            if (!empty($manifest['include_files']) && is_dir($tempDir . '/wp-content')) {
                WPMB_Log::write('Starting file restoration', ['source_dir' => $tempDir . '/wp-content', 'target_dir' => WP_CONTENT_DIR]);
                try {
                    WPMB_File_Archiver::copy_directory($tempDir . '/wp-content', WP_CONTENT_DIR);
                    WPMB_Log::write('Files restored successfully');
                } catch (Exception $e) {
                    WPMB_Log::write('WARNING: File restoration failed', ['error' => $e->getMessage()]);
                    // Don't throw - database is already restored, files are less critical
                    WPMB_Log::write('Continuing despite file restoration failure - database was restored successfully');
                }
            }

            $restoreSuccessful = true;
            WPMB_Log::write('Restore completed successfully', [
                'source' => basename($path),
                'drop_tables' => (bool) $options['drop_tables'],
                'urls_replaced' => ($oldSiteUrl ?? '') !== $newSiteUrl,
            ]);

            return $manifest;
        } catch (Exception $e) {
            WPMB_Log::write('RESTORE FAILED - Initiating rollback procedure', [
                'error' => $e->getMessage(),
                'has_safety_backup' => $safetyBackupPath !== null && file_exists($safetyBackupPath),
            ]);

            // Attempt to rollback using safety backup
            if ($safetyBackupPath && file_exists($safetyBackupPath)) {
                WPMB_Log::write('Rolling back to safety backup', ['safety_backup' => basename($safetyBackupPath)]);
                try {
                    self::rollback_from_safety_backup($safetyBackupPath);
                    WPMB_Log::write('Rollback completed successfully - your site has been restored to its previous state');
                    throw new RuntimeException(
                        'Restore failed: ' . $e->getMessage() .
                            ' | Your site has been automatically restored to its previous working state using the safety backup.'
                    );
                } catch (Exception $rollbackError) {
                    WPMB_Log::write('CRITICAL: Rollback also failed', ['rollback_error' => $rollbackError->getMessage()]);
                    throw new RuntimeException(
                        'CRITICAL ERROR: Restore failed (' . $e->getMessage() . ') AND rollback failed (' . $rollbackError->getMessage() . '). ' .
                            'A safety backup exists at: ' . basename($safetyBackupPath) . '. Please restore it manually or contact support.'
                    );
                }
            } else {
                throw new RuntimeException('Restore failed: ' . $e->getMessage() . ' | No safety backup was available.');
            }
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

    /**
     * Rollback to safety backup after a failed restore
     */
    private static function rollback_from_safety_backup($safetyBackupPath)
    {
        global $wpdb;

        WPMB_Log::write('ROLLBACK: Starting automatic rollback from safety backup', [
            'safety_backup' => basename($safetyBackupPath),
        ]);

        // Extract safety backup
        $tempDir = trailingslashit(WPMB_Paths::temp_dir()) . uniqid('rollback_', true);
        if (!wp_mkdir_p($tempDir)) {
            throw new RuntimeException('Cannot create rollback workspace');
        }

        $zip = new ZipArchive();
        if ($zip->open($safetyBackupPath) !== true) {
            throw new RuntimeException('Cannot open safety backup archive');
        }

        if (!$zip->extractTo($tempDir)) {
            $zip->close();
            throw new RuntimeException('Failed to extract safety backup');
        }

        $manifestContent = $zip->getFromName('manifest.json');
        $zip->close();

        if (!$manifestContent) {
            throw new RuntimeException('Safety backup manifest missing');
        }

        $manifest = json_decode($manifestContent, true);
        if (!is_array($manifest)) {
            throw new RuntimeException('Safety backup manifest corrupted');
        }

        // Restore database from safety backup
        if (!empty($manifest['include_database']) && file_exists($tempDir . '/database.sql')) {
            WPMB_Log::write('ROLLBACK: Restoring database from safety backup');

            $importer = new WPMB_Database_Importer();
            $knownTables = $manifest['tables'] ?? [];

            // Drop all current tables first
            $currentTables = $importer->list_tables();
            $wpdb->query('SET FOREIGN_KEY_CHECKS=0');
            foreach ($currentTables as $table) {
                $wpdb->query('DROP TABLE IF EXISTS `' . $table . '`');
            }

            // Import safety backup database
            $importer->import($tempDir . '/database.sql', $knownTables, false);

            $sourcePrefix = $manifest['table_prefix'] ?? '';
            $tableInventory = $knownTables ?: $importer->list_tables();
            $importer->ensure_prefix($tableInventory, $sourcePrefix, $wpdb->prefix);

            $wpdb->query('SET FOREIGN_KEY_CHECKS=1');

            WPMB_Log::write('ROLLBACK: Database restored from safety backup');
        }

        // Restore files from safety backup (if needed)
        if (!empty($manifest['include_files']) && is_dir($tempDir . '/wp-content')) {
            WPMB_Log::write('ROLLBACK: Restoring files from safety backup');
            try {
                WPMB_File_Archiver::copy_directory($tempDir . '/wp-content', WP_CONTENT_DIR);
                WPMB_Log::write('ROLLBACK: Files restored from safety backup');
            } catch (Exception $e) {
                WPMB_Log::write('ROLLBACK: Warning - file restoration failed, but database was restored', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Cleanup
        WPMB_Paths::cleanup_temp();

        WPMB_Log::write('ROLLBACK: Completed successfully - site restored to previous state');
    }
}
