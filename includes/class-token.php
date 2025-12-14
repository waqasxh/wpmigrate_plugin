<?php
class WPMB_Token
{
    private const TRANSIENT_PREFIX = 'wpmb_token_';
    private const REGISTRY_TRANSIENT = 'wpmb_token_registry';

    public static function issue($filePath, $ttl = HOUR_IN_SECONDS)
    {
        $token = wp_generate_password(32, false, false);
        set_transient(self::TRANSIENT_PREFIX . $token, [
            'path' => $filePath,
            'expires' => time() + (int) $ttl,
        ], $ttl);

        self::remember($token, $ttl);
        return $token;
    }

    public static function consume($token)
    {
        $payload = get_transient(self::TRANSIENT_PREFIX . $token);
        delete_transient(self::TRANSIENT_PREFIX . $token);
        self::forget($token);
        return $payload;
    }

    public static function peek($token)
    {
        $payload = get_transient(self::TRANSIENT_PREFIX . $token);
        if (!$payload) {
            return null;
        }
        if (!file_exists($payload['path'])) {
            delete_transient(self::TRANSIENT_PREFIX . $token);
            self::forget($token);
            return null;
        }
        return $payload;
    }

    public static function purge_expired()
    {
        $tokens = get_transient(self::REGISTRY_TRANSIENT);
        if (!$tokens || !is_array($tokens)) {
            return;
        }
        foreach ($tokens as $token => $expiry) {
            if ($expiry < time() || !get_transient(self::TRANSIENT_PREFIX . $token)) {
                delete_transient(self::TRANSIENT_PREFIX . $token);
                unset($tokens[$token]);
            }
        }
        set_transient(self::REGISTRY_TRANSIENT, $tokens, DAY_IN_SECONDS);
    }

    private static function remember($token, $ttl)
    {
        $tokens = get_transient(self::REGISTRY_TRANSIENT);
        if (!is_array($tokens)) {
            $tokens = [];
        }
        $tokens[$token] = time() + (int) $ttl;
        set_transient(self::REGISTRY_TRANSIENT, $tokens, DAY_IN_SECONDS);
    }

    private static function forget($token)
    {
        $tokens = get_transient(self::REGISTRY_TRANSIENT);
        if (!is_array($tokens)) {
            return;
        }
        unset($tokens[$token]);
        set_transient(self::REGISTRY_TRANSIENT, $tokens, DAY_IN_SECONDS);
    }
}
