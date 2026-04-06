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

        // Start with core tables to ensure they are processed first.
        $core_tables = [
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

        // Also include all additional tables with the site prefix (e.g. Elementor,
        // Rank Math, SureMail, WPForms, ActionScheduler, etc.) so that every URL
        // stored by a plugin is updated, not only the WP core tables.
        $all_prefixed = $wpdb->get_col(
            $wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($wpdb->prefix) . '%')
        );

        $extra_tables = array_diff($all_prefixed, $core_tables);

        $tables = array_merge($core_tables, $extra_tables);

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
            return self::replace_in_serialized($value, $old, $new);
        }
        return str_replace($old, $new, $value);
    }

    /**
     * Replace $old with $new inside a serialized string without unserializing it.
     *
     * Avoids class-not-found errors (e.g. Elementor objects) that occur when
     * unserialize() encounters a class that is not loaded in the current context.
     * Iterates over every s:N:"..." token using the declared byte-length so that
     * embedded quotes or semicolons never confuse the parser, then recalculates
     * the length after the replacement.
     */
    private static function replace_in_serialized($data, $old, $new)
    {
        if (strpos($data, $old) === false) {
            return $data;
        }

        $result = '';
        $offset = 0;
        $len    = strlen($data);

        while ($offset < $len) {
            // Find the next serialized-string token: s:<digits>:"
            if (!preg_match('/s:(\d+):"/', $data, $m, PREG_OFFSET_CAPTURE, $offset)) {
                $result .= substr($data, $offset);
                break;
            }

            $tag_start  = (int) $m[0][1];
            $str_length = (int) $m[1][0];
            $str_start  = $tag_start + strlen($m[0][0]);
            $str_end    = $str_start + $str_length;

            // Verify the closing `";` follows the declared byte count.
            if ($str_end + 2 > $len || substr($data, $str_end, 2) !== '";') {
                // Malformed token – copy one character and keep scanning.
                $result .= substr($data, $offset, $tag_start - $offset + 1);
                $offset  = $tag_start + 1;
                continue;
            }

            // Copy everything before this token verbatim.
            $result .= substr($data, $offset, $tag_start - $offset);

            // Perform replacement inside the string value and fix the length.
            $str_value = substr($data, $str_start, $str_length);
            $str_value = str_replace($old, $new, $str_value);
            $result   .= 's:' . strlen($str_value) . ':"' . $str_value . '";';

            $offset = $str_end + 2; // advance past closing `";"
        }

        return $result;
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
