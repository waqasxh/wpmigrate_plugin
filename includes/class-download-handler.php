<?php
class WPMB_Download_Handler
{
    public static function serve()
    {
        $token = isset($_GET['token']) ? sanitize_text_field(wp_unslash($_GET['token'])) : '';
        if (!$token) {
            wp_die(__('Missing download token.', 'wpmb'), __('WP Migrate Blueprint', 'wpmb'), 403);
        }

        $payload = WPMB_Token::consume($token);
        if (!$payload || empty($payload['path']) || !file_exists($payload['path'])) {
            wp_die(__('The requested backup is no longer available.', 'wpmb'), __('WP Migrate Blueprint', 'wpmb'), 404);
        }

        $path = $payload['path'];
        $filename = basename($path);

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($path));

        $handle = fopen($path, 'rb');
        if ($handle) {
            while (!feof($handle)) {
                echo fread($handle, 8192);
                flush();
            }
            fclose($handle);
        }
        exit;
    }
}
