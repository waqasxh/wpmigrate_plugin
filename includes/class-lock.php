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
        WPMB_Log::write('Lock acquired', [
            'operation' => $key,
            'ttl_seconds' => $ttl,
        ]);
        return $lockKey;
    }

    public static function release($lockKey)
    {
        delete_transient($lockKey);
        WPMB_Log::write('Lock released', ['lock_key' => $lockKey]);
    }

    public static function is_locked($key)
    {
        $lockKey = 'wpmb_lock_' . sanitize_key($key);
        return (bool) get_transient($lockKey);
    }
}
