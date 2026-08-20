<?php
class WPMB_Lock
{
    public static function acquire($key, $ttl = 600)
    {
        $lockKey = 'wpmb_lock_' . sanitize_key($key);
        if (get_transient($lockKey)) {
            WPMB_Log::write('Lock acquisition failed - operation already in progress', [
                'operation' => $key,
                'lock_key' => $lockKey,
            ]);
            throw new RuntimeException(sprintf('WP Migrate Lite is busy with %s. Try again later.', $key));
        }
        set_transient($lockKey, time(), $ttl);
        self::write_marker($key);
        WPMB_Log::write('Lock acquired', [
            'operation' => $key,
            'ttl_seconds' => $ttl,
        ]);
        return $lockKey;
    }

    public static function release($lockKey)
    {
        delete_transient($lockKey);
        self::delete_marker(self::key_from_lock_key($lockKey));
        WPMB_Log::write('Lock released', ['lock_key' => $lockKey]);
    }

    public static function is_locked($key)
    {
        $lockKey = 'wpmb_lock_' . sanitize_key($key);
        return (bool) get_transient($lockKey);
    }

    public static function force_release($key)
    {
        $lockKey = 'wpmb_lock_' . sanitize_key($key);
        delete_transient($lockKey);
        self::delete_marker($key);
        WPMB_Log::write('Lock force-released', [
            'operation' => $key,
            'lock_key' => $lockKey,
        ]);
    }

    /**
     * A plain filesystem marker mirroring the transient's held/not-held
     * state, purely for poll.php to read without needing a WordPress
     * bootstrap or DB connection - that's what lets it report status even
     * while WordPress's own maintenance mode is blocking every normal
     * request.
     */
    private static function write_marker($key)
    {
        @file_put_contents(self::marker_path($key), (string) time());
    }

    private static function delete_marker($key)
    {
        @unlink(self::marker_path($key));
    }

    private static function marker_path($key)
    {
        return trailingslashit(WPMB_Paths::base_dir()) . 'status-' . sanitize_key($key) . '.lock';
    }

    private static function key_from_lock_key($lockKey)
    {
        return preg_replace('/^wpmb_lock_/', '', $lockKey);
    }
}
