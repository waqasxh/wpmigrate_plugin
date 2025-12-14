<?php
class WPM_Replace {

    public static function run($old, $new, $dry = false) {
        global $wpdb;

        $tables = [
            $wpdb->options,
            $wpdb->posts,
            $wpdb->postmeta
        ];

        foreach ($tables as $table) {
            $rows = $wpdb->get_results("SELECT * FROM $table LIMIT 5000", ARRAY_A);

            foreach ($rows as $row) {
                foreach ($row as $col => $val) {

                    if (!is_string($val) || strpos($val, $old) === false) continue;

                    $updated = self::replace_value($val, $old, $new);

                    if ($updated !== $val) {
                        WPM_Logger::log("[$table.$col] match found");

                        if (!$dry && isset($row['ID'])) {
                            $wpdb->update(
                                $table,
                                [$col => $updated],
                                ['ID' => $row['ID']]
                            );
                        }
                    }
                }
            }
        }
    }

    private static function replace_value($value, $old, $new) {
        if (is_serialized($value)) {
            $data = maybe_unserialize($value);
            $data = self::walk($data, $old, $new);
            return serialize($data);
        }
        return str_replace($old, $new, $value);
    }

    private static function walk($data, $old, $new) {
        if (is_array($data)) {
            foreach ($data as $k => $v) {
                $data[$k] = self::walk($v, $old, $new);
            }
        } elseif (is_string($data)) {
            $data = str_replace($old, $new, $data);
        }
        return $data;
    }
}
