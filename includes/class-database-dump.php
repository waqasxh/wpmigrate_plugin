<?php
class WPMB_Database_Dump
{
    private $wpdb;
    private $chunkSize;
    private $progressTracker = [];

    public function __construct($chunkSize = 500)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->chunkSize = max(50, (int) $chunkSize);
    }

    public function generate($filePath)
    {
        WPMB_Log::write('Starting database dump', ['target_file' => basename($filePath)]);
        $tables = $this->get_tables();
        WPMB_Log::write('Discovered database tables', ['num_tables' => count($tables)]);

        if ($this->maybe_cli_dump($filePath, $tables)) {
            WPMB_Log::write('Database dump completed', [
                'num_tables' => count($tables),
                'filesize' => size_format(filesize($filePath)),
                'strategy' => 'mysqldump',
            ]);

            return $tables;
        }

        $handle = fopen($filePath, 'w');
        if (!$handle) {
            WPMB_Log::write('Database dump failed - cannot write to file', ['file' => $filePath]);
            throw new RuntimeException('Unable to write database dump.');
        }

        $this->preface($handle);

        foreach ($tables as $table) {
            $this->dump_table($handle, $table);
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

        WPMB_Log::write('Database dump completed', [
            'num_tables' => count($tables),
            'filesize' => size_format(filesize($filePath)),
        ]);

        return $tables;
    }

    private function preface($handle)
    {
        fwrite($handle, "SET NAMES utf8mb4;\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");
    }

    private function get_tables()
    {
        $tables = $this->wpdb->get_col('SHOW TABLES');
        if (!$tables) {
            WPMB_Log::write('Database dump failed - no tables found');
            throw new RuntimeException('No database tables discovered.');
        }
        return $tables;
    }

    private function dump_table($handle, $table)
    {
        $tableEsc = $this->escape_identifier($table);

        fwrite($handle, sprintf("DROP TABLE IF EXISTS %s;\n", $tableEsc));

        $create = $this->wpdb->get_row('SHOW CREATE TABLE ' . $tableEsc, ARRAY_N);
        if (!$create || empty($create[1])) {
            WPMB_Log::write('Cannot read table structure', ['table' => $table]);
            throw new RuntimeException(sprintf('Unable to read CREATE TABLE for %s', $table));
        }
        fwrite($handle, $create[1] . ";\n\n");

        $count = (int) $this->wpdb->get_var('SELECT COUNT(*) FROM ' . $tableEsc);
        if ($count === 0) {
            WPMB_Log::write('Table dumped (empty)', ['table' => $table]);
            return;
        }

        WPMB_Log::write('Dumping table data', ['table' => $table, 'rows' => $count]);

        $primaryKey = $this->get_numeric_primary_key($table);
        $processed = 0;

        if ($primaryKey) {
            $pkEsc = $this->escape_identifier($primaryKey);
            $lastPk = null;

            while (true) {
                if ($lastPk === null) {
                    $sql = $this->wpdb->prepare(
                        'SELECT * FROM ' . $tableEsc . ' ORDER BY ' . $pkEsc . ' ASC LIMIT %d',
                        $this->chunkSize
                    );
                } else {
                    $sql = $this->wpdb->prepare(
                        'SELECT * FROM ' . $tableEsc . ' WHERE ' . $pkEsc . ' > %d ORDER BY ' . $pkEsc . ' ASC LIMIT %d',
                        $lastPk,
                        $this->chunkSize
                    );
                }

                $rows = $this->wpdb->get_results($sql, ARRAY_A);
                if (!$rows) {
                    break;
                }

                $this->write_insert_block($handle, $tableEsc, $rows);
                $processed += count($rows);
                $this->log_table_progress($table, $processed, $count);

                $lastRow = end($rows);
                if ($lastRow && isset($lastRow[$primaryKey])) {
                    $lastPk = (int) $lastRow[$primaryKey];
                } else {
                    $lastPk = null;
                }
            }
        } else {
            $offset = 0;
            while ($offset < $count) {
                $rows = $this->wpdb->get_results(
                    $this->wpdb->prepare('SELECT * FROM ' . $tableEsc . ' LIMIT %d OFFSET %d', $this->chunkSize, $offset),
                    ARRAY_A
                );

                if (!$rows) {
                    break;
                }

                $this->write_insert_block($handle, $tableEsc, $rows);
                $offset += count($rows);
                $processed += count($rows);
                $this->log_table_progress($table, $processed, $count);
            }
        }

        if (($this->progressTracker[$table] ?? 0) < $count) {
            $this->log_table_progress($table, $count, $count);
        }

        fwrite($handle, "\n");
        WPMB_Log::write('Table dump completed', ['table' => $table, 'rows' => $count]);
    }

    private function write_insert_block($handle, $tableEsc, $rows)
    {
        $columns = array_keys($rows[0]);
        $columnList = implode(', ', array_map([$this, 'escape_identifier'], $columns));

        fwrite($handle, sprintf('INSERT INTO %s (%s) VALUES', $tableEsc, $columnList));
        fwrite($handle, "\n");

        $values = [];
        foreach ($rows as $row) {
            $escaped = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $escaped[] = 'NULL';
                } elseif (is_numeric($value) && !preg_match('/^0[0-9]/', (string) $value)) {
                    $escaped[] = $value;
                } else {
                    // Use proper MySQL escaping via wpdb
                    $escaped[] = "'" . $this->wpdb->_real_escape((string) $value) . "'";
                }
            }
            $values[] = '(' . implode(', ', $escaped) . ')';
        }

        fwrite($handle, implode(",\n", $values));
        fwrite($handle, ";\n");
    }

    private function escape_identifier($value)
    {
        return '`' . str_replace('`', '``', $value) . '`';
    }

    private function get_numeric_primary_key($table)
    {
        $tableEsc = $this->escape_identifier($table);
        $indexes = $this->wpdb->get_results('SHOW INDEX FROM ' . $tableEsc, ARRAY_A);
        if (!$indexes) {
            return null;
        }

        $primary = [];
        foreach ($indexes as $index) {
            if (!isset($index['Key_name']) || strcasecmp($index['Key_name'], 'PRIMARY') !== 0) {
                continue;
            }
            $seq = isset($index['Seq_in_index']) ? (int) $index['Seq_in_index'] : 0;
            $primary[$seq] = $index['Column_name'] ?? null;
        }

        if (count(array_filter($primary)) !== 1) {
            return null;
        }

        $column = array_values(array_filter($primary))[0];
        if (!$column) {
            return null;
        }

        $columnInfo = $this->wpdb->get_row(
            $this->wpdb->prepare('SHOW COLUMNS FROM ' . $tableEsc . ' LIKE %s', $column),
            ARRAY_A
        );

        if (!$columnInfo || empty($columnInfo['Type'])) {
            return null;
        }

        $type = strtolower($columnInfo['Type']);
        if (!preg_match('/tinyint|smallint|mediumint|int|bigint|serial/', $type)) {
            return null;
        }

        return $column;
    }

    private function log_table_progress($table, $processed, $total)
    {
        $interval = max($this->chunkSize * 20, 10000);
        $lastLogged = $this->progressTracker[$table] ?? 0;

        if ($processed < $total && ($processed - $lastLogged) < $interval) {
            return;
        }

        $context = [
            'table' => $table,
            'rows_processed' => $processed,
        ];

        if ($total > 0) {
            $context['rows_total'] = $total;
            $context['progress_pct'] = round(($processed / $total) * 100, 1);
        }

        WPMB_Log::write('Dumping table progress', $context);
        $this->progressTracker[$table] = $processed;
    }

    private function maybe_cli_dump($filePath, array $tables)
    {
        if (!$this->can_use_cli()) {
            return false;
        }

        try {
            $this->run_cli_dump($filePath);
            return true;
        } catch (Throwable $e) {
            WPMB_Log::write('mysqldump fallback triggered', ['error' => $e->getMessage()]);
            return false;
        }
    }

    private function can_use_cli()
    {
        if (!function_exists('proc_open') || !function_exists('proc_close')) {
            return false;
        }

        $disabled = ini_get('disable_functions');
        if ($disabled && preg_match('/\bproc_open\b/', $disabled)) {
            return false;
        }

        if (!function_exists('shell_exec')) {
            return false;
        }

        if ($disabled && preg_match('/\bshell_exec\b/', $disabled)) {
            return false;
        }

        $binary = apply_filters('wpmb_mysqldump_binary', 'mysqldump');
        $whichCommand = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN' ? 'where' : 'command -v';
        $probe = @shell_exec($whichCommand . ' ' . escapeshellarg($binary));

        if (empty($probe)) {
            return false;
        }

        return true;
    }

    private function run_cli_dump($filePath)
    {
        $binary = apply_filters('wpmb_mysqldump_binary', 'mysqldump');
        $creds = $this->mysql_credentials();

        if (!$creds) {
            throw new RuntimeException('Database credentials unavailable.');
        }

        WPMB_Log::write('Using mysqldump for database export', ['binary' => $binary]);

        $command = [$binary];
        $command[] = '--single-transaction';
        $command[] = '--quick';
        $command[] = '--skip-lock-tables';
        $command[] = '--hex-blob';
        $command[] = '--default-character-set=utf8mb4';

        $command[] = '--host=' . escapeshellarg($creds['host']);

        if (!empty($creds['port'])) {
            $command[] = '--port=' . (int) $creds['port'];
        }

        if (!empty($creds['socket'])) {
            $command[] = '--socket=' . escapeshellarg($creds['socket']);
        }

        $command[] = '--user=' . escapeshellarg($creds['user']);

        if (!empty($creds['database'])) {
            $command[] = escapeshellarg($creds['database']);
        }

        $cmdLine = implode(' ', $command);

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', $filePath, 'w'],
            2 => ['pipe', 'w'],
        ];

        $env = is_array($_ENV) ? $_ENV : [];
        $env['MYSQL_PWD'] = $creds['password'];

        $process = proc_open($cmdLine, $descriptorSpec, $pipes, ABSPATH, $env);

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to spawn mysqldump process.');
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $stderr = '';
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
        }

        $status = proc_close($process);

        if ($status !== 0) {
            throw new RuntimeException(trim($stderr) ?: 'mysqldump exited with status ' . $status);
        }
    }

    private function mysql_credentials()
    {
        if (!defined('DB_USER') || !defined('DB_PASSWORD') || !defined('DB_NAME') || !defined('DB_HOST')) {
            return null;
        }

        $host = DB_HOST;
        $socket = null;
        $port = null;

        if (strpos($host, ':') !== false) {
            list($hostPart, $extra) = explode(':', $host, 2);
            if (is_numeric($extra)) {
                $port = (int) $extra;
            } else {
                $socket = $extra;
            }
            $host = $hostPart;
        }

        return [
            'user' => DB_USER,
            'password' => DB_PASSWORD,
            'database' => DB_NAME,
            'host' => $host ?: 'localhost',
            'port' => $port,
            'socket' => $socket,
        ];
    }
}
