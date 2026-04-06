<?php
class WPMB_Database_Importer
{
    private $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function import($sqlFile, array $tables, $dropExisting = true, array $skipTables = [])
    {
        if (!file_exists($sqlFile)) {
            WPMB_Log::write('Database import failed - SQL file not found', ['sql_file' => $sqlFile]);
            throw new RuntimeException('Database archive missing.');
        }

        $filesize = filesize($sqlFile);
        WPMB_Log::write('Starting SQL import', [
            'sql_file' => basename($sqlFile),
            'filesize' => size_format($filesize),
            'num_known_tables' => count($tables),
            'drop_existing' => $dropExisting,
            'skip_tables' => $skipTables,
        ]);

        $this->wpdb->query('SET FOREIGN_KEY_CHECKS=0');

        if ($dropExisting) {
            WPMB_Log::write('Dropping existing tables', ['num_tables' => count($tables)]);
            foreach ($tables as $table) {
                if (in_array($table, $skipTables, true)) {
                    continue;
                }
                $this->wpdb->query('DROP TABLE IF EXISTS ' . $this->escape_identifier($table));
            }

            WPMB_Log::write('Dropping tables with current prefix', ['prefix' => $this->wpdb->prefix]);
            $this->drop_tables_with_prefix($this->wpdb->prefix, $skipTables);
        }

        $handle = fopen($sqlFile, 'r');
        if (!$handle) {
            WPMB_Log::write('Database import failed - cannot open SQL file');
            throw new RuntimeException('Unable to open database dump for reading.');
        }

        WPMB_Log::write('Executing SQL statements');
        $statement = '';
        $statements_executed = 0;
        $line_number = 0;
        $statement_start_line = 0;

        while (($line = fgets($handle)) !== false) {
            $line_number++;
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0) {
                continue;
            }

            if ($statement === '') {
                $statement_start_line = $line_number;
            }

            $statement .= $line;
            if (substr(rtrim($line), -1) === ';') {
                if ($skipTables && $this->statement_targets_table($statement, $skipTables)) {
                    WPMB_Log::write('Skipped SQL statement for preserved table', [
                        'statement_preview' => substr(ltrim($statement), 0, 100),
                    ]);
                } else {
                    try {
                        $this->run_statement($statement, $statement_start_line);
                        $statements_executed++;
                    } catch (RuntimeException $e) {
                        WPMB_Log::write('SQL import failed at statement', [
                            'line' => $statement_start_line,
                            'statement_preview' => substr($statement, 0, 200),
                            'error' => $e->getMessage(),
                        ]);
                        throw $e;
                    }
                }
                $statement = '';

                // Log progress every 100 statements
                if ($statements_executed % 100 === 0) {
                    WPMB_Log::write('SQL import progress', [
                        'statements_executed' => $statements_executed,
                        'lines_processed' => $line_number,
                    ]);
                }
            }
        }

        fclose($handle);
        $this->wpdb->query('SET FOREIGN_KEY_CHECKS=1');

        WPMB_Log::write('SQL import completed', [
            'total_statements' => $statements_executed,
            'total_lines' => $line_number,
        ]);
    }

    public function ensure_prefix(array $tables, $sourcePrefix, $targetPrefix, array $skipTables = [])
    {
        $sourcePrefix = (string) $sourcePrefix;
        $targetPrefix = (string) $targetPrefix;

        if ($sourcePrefix === '') {
            WPMB_Log::write('Detecting table prefix from tables');
            $sourcePrefix = $this->detect_prefix($tables);
            WPMB_Log::write('Detected source prefix', ['prefix' => $sourcePrefix ?: 'none']);
        }

        if ($sourcePrefix === '' || $targetPrefix === '' || $sourcePrefix === $targetPrefix) {
            WPMB_Log::write('Prefix conversion not needed', [
                'source_prefix' => $sourcePrefix,
                'target_prefix' => $targetPrefix,
            ]);
            return;
        }

        WPMB_Log::write('Preparing table prefix conversion', [
            'source_prefix' => $sourcePrefix,
            'target_prefix' => $targetPrefix,
            'num_tables' => count($tables),
        ]);

        $renames = [];
        foreach ($tables as $table) {
            if (strpos($table, $sourcePrefix) !== 0) {
                continue;
            }

            if (in_array($table, $skipTables, true)) {
                continue;
            }

            $target = $targetPrefix . substr($table, strlen($sourcePrefix));
            $renames[$table] = $target;
        }

        if (!$renames) {
            WPMB_Log::write('No tables to rename');
            return;
        }

        WPMB_Log::write('Renaming tables', ['num_renames' => count($renames)]);

        foreach ($renames as $target) {
            $this->wpdb->query('DROP TABLE IF EXISTS ' . $this->escape_identifier($target));
        }

        $pairs = [];
        foreach ($renames as $source => $target) {
            $pairs[] = $this->escape_identifier($source) . ' TO ' . $this->escape_identifier($target);
        }

        $sql = 'RENAME TABLE ' . implode(', ', $pairs);
        $result = $this->wpdb->query($sql);
        if ($result === false) {
            WPMB_Log::write('Table rename failed', ['error' => $this->wpdb->last_error]);
            throw new RuntimeException(sprintf('Failed to update table prefixes: %s', $this->wpdb->last_error));
        }

        WPMB_Log::write('Table prefix conversion completed successfully', ['renamed_tables' => count($renames)]);
    }

    public function list_tables($like = null)
    {
        if ($like !== null) {
            return $this->wpdb->get_col($this->wpdb->prepare('SHOW TABLES LIKE %s', $like));
        }

        return $this->wpdb->get_col('SHOW TABLES');
    }

    public function drop_tables_with_prefix($prefix, array $except = [])
    {
        $prefix = (string) $prefix;
        if ($prefix === '') {
            return;
        }

        $tables = $this->list_tables($prefix . '%');
        foreach ($tables as $table) {
            if (in_array($table, $except, true)) {
                continue;
            }
            $this->wpdb->query('DROP TABLE IF EXISTS ' . $this->escape_identifier($table));
        }
    }

    public function table_exists($table)
    {
        $table = (string) $table;
        if ($table === '') {
            return false;
        }

        $like = $this->wpdb->prepare('SHOW TABLES LIKE %s', $table);
        return (bool) $this->wpdb->get_var($like);
    }

    private function detect_prefix(array $tables)
    {
        foreach ($tables as $table) {
            $pos = strpos($table, '_');
            if ($pos !== false) {
                return substr($table, 0, $pos + 1);
            }
        }

        $existing = $this->list_tables();
        foreach ($existing as $table) {
            $pos = strpos($table, '_');
            if ($pos !== false) {
                return substr($table, 0, $pos + 1);
            }
        }

        return '';
    }

    private function run_statement($sql, $line_number = null)
    {
        $result = $this->wpdb->query($sql);
        if ($result === false) {
            $error_msg = sprintf('MySQL error during import: %s', $this->wpdb->last_error);
            if ($line_number !== null) {
                $error_msg = sprintf('MySQL error at line %d: %s', $line_number, $this->wpdb->last_error);
            }
            throw new RuntimeException($error_msg);
        }
    }

    /**
     * After table-level prefix renaming, fix any option_name values in wp_options
     * and meta_key values in wp_usermeta that still carry the old source prefix.
     *
     * Examples:
     *   www227201_user_roles  → wp_user_roles  (role definitions; WP looks up $prefix.'user_roles')
     *   www227201_capabilities → wp_capabilities (user caps; WP looks up $prefix.'capabilities')
     *   www227201_user_level   → wp_user_level
     *
     * Without this step, admin login works but WordPress cannot resolve any roles or
     * capabilities, making the site appear broken and blocking wp-admin access.
     *
     * @param string $sourcePrefix    Original table prefix from the backup (e.g. 'www227201_').
     * @param string $targetPrefix    Table prefix in use on this install (e.g. 'wp_').
     * @param bool   $includeUsermeta Also fix wp_usermeta.meta_key rows. Pass false when
     *                                the target's user tables were preserved so we do not
     *                                overwrite capability rows that are already correct.
     */
    public function fix_prefix_in_data($sourcePrefix, $targetPrefix, $includeUsermeta = true)
    {
        if ($sourcePrefix === '' || $targetPrefix === '' || $sourcePrefix === $targetPrefix) {
            WPMB_Log::write('Prefix-in-data fix skipped - prefixes identical or empty', [
                'source' => $sourcePrefix,
                'target' => $targetPrefix,
            ]);
            return;
        }

        WPMB_Log::write('Fixing prefix-keyed rows in options / usermeta', [
            'source_prefix' => $sourcePrefix,
            'target_prefix' => $targetPrefix,
            'fix_usermeta'  => $includeUsermeta,
        ]);

        // --- wp_options: rename option_name rows that start with the source prefix ---
        $options_table = $targetPrefix . 'options';
        if ($this->table_exists($options_table)) {
            $like = $this->wpdb->esc_like($sourcePrefix) . '%';
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT option_id, option_name FROM `{$options_table}` WHERE option_name LIKE %s",
                    $like
                )
            );
            $count = 0;
            foreach ($rows as $row) {
                $newName = $targetPrefix . substr($row->option_name, strlen($sourcePrefix));
                $this->wpdb->update(
                    $options_table,
                    ['option_name' => $newName],
                    ['option_id'   => $row->option_id]
                );
                $count++;
            }
            WPMB_Log::write('Fixed option_name prefixes in options table', [
                'table' => $options_table,
                'count' => $count,
            ]);
        }

        if (!$includeUsermeta) {
            WPMB_Log::write('Usermeta prefix fix skipped - target user tables preserved');
            return;
        }

        // --- wp_usermeta: rename meta_key rows that start with the source prefix ---
        $usermeta_table = $targetPrefix . 'usermeta';
        if ($this->table_exists($usermeta_table)) {
            $like = $this->wpdb->esc_like($sourcePrefix) . '%';
            $rows = $this->wpdb->get_results(
                $this->wpdb->prepare(
                    "SELECT umeta_id, meta_key FROM `{$usermeta_table}` WHERE meta_key LIKE %s",
                    $like
                )
            );
            $count = 0;
            foreach ($rows as $row) {
                $newKey = $targetPrefix . substr($row->meta_key, strlen($sourcePrefix));
                $this->wpdb->update(
                    $usermeta_table,
                    ['meta_key' => $newKey],
                    ['umeta_id' => $row->umeta_id]
                );
                $count++;
            }
            WPMB_Log::write('Fixed meta_key prefixes in usermeta table', [
                'table' => $usermeta_table,
                'count' => $count,
            ]);
        }
    }

    private function escape_identifier($value)
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    /**
     * Returns true if the SQL statement operates on one of the given table names.
     * Matches DROP TABLE, CREATE TABLE, INSERT INTO, LOCK TABLES, ALTER TABLE.
     */
    private function statement_targets_table($statement, array $tableNames)
    {
        foreach ($tableNames as $table) {
            $t = preg_quote($table, '/');
            if (preg_match(
                '/(?:DROP\s+TABLE|CREATE\s+TABLE|INSERT\s+INTO|LOCK\s+TABLES|ALTER\s+TABLE)\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?[`\'"]*' . $t . '[`\'"]*[\s(,;]/i',
                $statement
            )) {
                return true;
            }
        }
        return false;
    }
}
