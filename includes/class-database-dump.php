<?php
class WPMB_Database_Dump
{
    private $wpdb;
    private $chunkSize;

    public function __construct($chunkSize = 500)
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->chunkSize = max(50, (int) $chunkSize);
    }

    public function generate($filePath)
    {
        WPMB_Log::write('Starting database dump', ['target_file' => basename($filePath)]);

        $handle = fopen($filePath, 'w');
        if (!$handle) {
            WPMB_Log::write('Database dump failed - cannot write to file', ['file' => $filePath]);
            throw new RuntimeException('Unable to write database dump.');
        }

        $this->preface($handle);

        $tables = $this->get_tables();
        WPMB_Log::write('Discovered database tables', ['num_tables' => count($tables)]);

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
        }

        fwrite($handle, "\n");
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
}
