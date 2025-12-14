<?php
class WPMB_Database_Importer
{
    private $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function import($sqlFile, array $tables, $dropExisting = true)
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
        ]);

        $this->wpdb->query('SET FOREIGN_KEY_CHECKS=0');

        if ($dropExisting) {
            WPMB_Log::write('Dropping existing tables', ['num_tables' => count($tables)]);
            foreach ($tables as $table) {
                $this->wpdb->query('DROP TABLE IF EXISTS ' . $this->escape_identifier($table));
            }

            WPMB_Log::write('Dropping tables with current prefix', ['prefix' => $this->wpdb->prefix]);
            $this->drop_tables_with_prefix($this->wpdb->prefix);
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

    public function ensure_prefix(array $tables, $sourcePrefix, $targetPrefix)
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

    public function drop_tables_with_prefix($prefix)
    {
        $prefix = (string) $prefix;
        if ($prefix === '') {
            return;
        }

        $tables = $this->list_tables($prefix . '%');
        foreach ($tables as $table) {
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

    private function escape_identifier($value)
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }
}
