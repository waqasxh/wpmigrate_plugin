<?php
class WPMB_Replace
{
    public static function run($old, $new, $dry = false)
    {
        global $wpdb;

        if (empty($old) || $old === $new) {
            WPMB_Log::write('URL replacement skipped - URLs are identical', ['old' => $old, 'new' => $new]);
            return;
        }

        $tables = [
            $wpdb->options,
            $wpdb->posts,
            $wpdb->postmeta,
            $wpdb->comments,
            $wpdb->commentmeta,
            $wpdb->terms,
            $wpdb->term_taxonomy,
            $wpdb->term_relationships,
            $wpdb->termmeta,
            $wpdb->usermeta,
        ];

        $total_replacements = 0;
        $total_rows_updated = 0;

        foreach ($tables as $table) {
            if (!self::table_exists($table)) {
                continue;
            }

            $primary_key = self::get_primary_key($table);
            if (!$primary_key) {
                WPMB_Log::write('Skipping table - no primary key found', ['table' => $table]);
                continue;
            }

            $page = 0;
            $page_size = 1000;
            $table_replacements = 0;
            $table_rows_updated = 0;

            while (true) {
                $offset = $page * $page_size;
                $rows = $wpdb->get_results(
                    $wpdb->prepare("SELECT * FROM `{$table}` LIMIT %d OFFSET %d", $page_size, $offset),
                    ARRAY_A
                );

                if (!$rows) {
                    break;
                }

                foreach ($rows as $row) {
                    $updates = [];
                    $row_has_changes = false;

                    foreach ($row as $col => $val) {
                        if ($col === $primary_key || !is_string($val) || strpos($val, $old) === false) {
                            continue;
                        }

                        $updated = self::replace_value($val, $old, $new);

                        if ($updated !== $val) {
                            $updates[$col] = $updated;
                            $row_has_changes = true;
                            $table_replacements++;
                        }
                    }

                    if ($row_has_changes && !$dry) {
                        $result = $wpdb->update(
                            $table,
                            $updates,
                            [$primary_key => $row[$primary_key]]
                        );
                        if ($result !== false) {
                            $table_rows_updated++;
                        }
                    }
                }

                $page++;

                // Safety break after 10000 rows per table
                if ($page * $page_size >= 10000) {
                    WPMB_Log::write('Table processing limit reached', ['table' => $table, 'rows_processed' => $page * $page_size]);
                    break;
                }
            }

            if ($table_replacements > 0) {
                WPMB_Log::write('URL replacements in table', [
                    'table' => $table,
                    'replacements' => $table_replacements,
                    'rows_updated' => $table_rows_updated,
                ]);
                $total_replacements += $table_replacements;
                $total_rows_updated += $table_rows_updated;
            }
        }

        WPMB_Log::write('URL replacement completed', [
            'old_url' => $old,
            'new_url' => $new,
            'total_replacements' => $total_replacements,
            'total_rows_updated' => $total_rows_updated,
        ]);
    }

    private static function replace_value($value, $old, $new)
    {
        if (is_serialized($value)) {
            $data = @unserialize($value);
            if ($data !== false) {
                $data = self::walk($data, $old, $new);
                return serialize($data);
            }
        }
        return str_replace($old, $new, $value);
    }

    private static function walk($data, $old, $new)
    {
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = self::walk($v, $old, $new);
            }
        } elseif (is_object($data)) {
            foreach (get_object_vars($data) as $k => $v) {
                $data->$k = self::walk($v, $old, $new);
            }
        } elseif (is_string($data)) {
            $data = str_replace($old, $new, $data);
        }
        return $data;
    }

    private static function table_exists($table)
    {
        global $wpdb;
        $result = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        return $result === $table;
    }

    private static function get_primary_key($table)
    {
        global $wpdb;

        // Common WordPress primary keys
        $common_keys = ['ID', 'term_id', 'term_taxonomy_id', 'comment_ID', 'meta_id', 'umeta_id', 'link_id'];

        $result = $wpdb->get_results("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'", ARRAY_A);
        if ($result && isset($result[0]['Column_name'])) {
            return $result[0]['Column_name'];
        }

        // Fallback to common keys
        $columns = $wpdb->get_col("SHOW COLUMNS FROM `{$table}`");
        foreach ($common_keys as $key) {
            if (in_array($key, $columns, true)) {
                return $key;
            }
        }

        return null;
    }
}
