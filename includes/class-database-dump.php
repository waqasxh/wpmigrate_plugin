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
        $handle = fopen($filePath, 'w');
        if (!$handle) {
            throw new RuntimeException('Unable to write database dump.');
        }

        $this->preface($handle);

        $tables = $this->get_tables();
        foreach ($tables as $table) {
            $this->dump_table($handle, $table);
        }

        fwrite($handle, "\nSET FOREIGN_KEY_CHECKS=1;\n");
        fclose($handle);

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
            throw new RuntimeException(sprintf('Unable to read CREATE TABLE for %s', $table));
        }
        fwrite($handle, $create[1] . ";\n\n");

        $count = (int) $this->wpdb->get_var('SELECT COUNT(*) FROM ' . $tableEsc);
        if ($count === 0) {
            return;
        }

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
                    $safe = str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value);
                    $escaped[] = "'" . $safe . "'";
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
