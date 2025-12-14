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
            throw new RuntimeException('Database archive missing.');
        }

        $this->wpdb->query('SET FOREIGN_KEY_CHECKS=0');

        if ($dropExisting) {
            foreach ($tables as $table) {
                $this->wpdb->query('DROP TABLE IF EXISTS ' . $this->escape_identifier($table));
            }

            $this->drop_tables_with_prefix($this->wpdb->prefix);
        }

        $handle = fopen($sqlFile, 'r');
        if (!$handle) {
            throw new RuntimeException('Unable to open database dump for reading.');
        }

        $statement = '';
        while (($line = fgets($handle)) !== false) {
            $trimmed = trim($line);
            if ($trimmed === '' || strpos($trimmed, '--') === 0 || strpos($trimmed, '/*') === 0) {
                continue;
            }

            $statement .= $line;
            if (substr(rtrim($line), -1) === ';') {
                $this->run_statement($statement);
                $statement = '';
            }
        }

        fclose($handle);
        $this->wpdb->query('SET FOREIGN_KEY_CHECKS=1');
    }

    public function ensure_prefix(array $tables, $sourcePrefix, $targetPrefix)
    {
        $sourcePrefix = (string) $sourcePrefix;
        $targetPrefix = (string) $targetPrefix;

        if ($sourcePrefix === '') {
            $sourcePrefix = $this->detect_prefix($tables);
        }

        if ($sourcePrefix === '' || $targetPrefix === '' || $sourcePrefix === $targetPrefix) {
            return;
        }

        $renames = [];
        foreach ($tables as $table) {
            if (strpos($table, $sourcePrefix) !== 0) {
                continue;
            }

            $target = $targetPrefix . substr($table, strlen($sourcePrefix));
            $renames[$table] = $target;
        }

        if (!$renames) {
            return;
        }

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
            throw new RuntimeException(sprintf('Failed to update table prefixes: %s', $this->wpdb->last_error));
        }
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

    private function run_statement($sql)
    {
        $result = $this->wpdb->query($sql);
        if ($result === false) {
            throw new RuntimeException(sprintf('MySQL error during import: %s', $this->wpdb->last_error));
        }
    }

    private function escape_identifier($value)
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }
}
