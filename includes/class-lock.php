<?php
class WPMB_Lock
{
    public static function acquire($key, $ttl = 600)
    {
        $lockKey = 'wpmb_lock_' . sanitize_key($key);
        if (get_transient($lockKey)) {
            throw new RuntimeException(sprintf('WP Migrate Lite is busy with %s. Try again later.', $key));
        }
        set_transient($lockKey, time(), $ttl);
        return $lockKey;
    }

    public static function release($lockKey)
    {
        delete_transient($lockKey);
    }
}
