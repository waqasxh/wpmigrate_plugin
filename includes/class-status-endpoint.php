<?php
/**
 * A standalone, non-WordPress status endpoint (poll.php) that lets the admin
 * UI check backup/restore progress even while WPMB_Maintenance::on() has
 * WordPress's own maintenance mode blocking every normal request - including
 * admin-ajax.php. WordPress checks maintenance mode before regular plugins
 * are loaded (only must-use plugins load early enough to hook
 * enable_maintenance_mode - and even that proved unreliable across hosts),
 * so the only dependable fix is an endpoint that never bootstraps WordPress
 * at all. poll.php is plain, dependency-free PHP that only reads the lock
 * marker file and today's log, guarded by a random per-operation token
 * issued in the same authenticated AJAX response that starts the operation.
 */
class WPMB_Status_Endpoint
{
    public static function ensure_installed()
    {
        $target = trailingslashit(WPMB_Paths::base_dir()) . 'poll.php';
        $contents = self::poll_script_source();

        if (file_exists($target) && file_get_contents($target) === $contents) {
            return;
        }

        if (@file_put_contents($target, $contents) === false) {
            WPMB_Log::write('Failed to install status poll endpoint', ['target' => $target]);
        }
    }

    public static function issue_token($op)
    {
        $token = wp_generate_password(40, false, false);
        $path = trailingslashit(WPMB_Paths::base_dir()) . 'poll-token-' . sanitize_key($op) . '.txt';
        @file_put_contents($path, $token);
        return $token;
    }

    public static function poll_url()
    {
        return trailingslashit(content_url('wpmb-backups')) . 'poll.php';
    }

    private static function poll_script_source()
    {
        return <<<'PHP'
<?php
/**
 * Standalone status endpoint - deliberately does NOT bootstrap WordPress, so
 * it stays reachable even while WordPress's native maintenance mode is
 * blocking every normal request during a backup/restore. Read-only: reports
 * whether an operation is currently locked and how the last one finished,
 * nothing else. Auto-installed and kept in sync by wpmigrate_plugin - safe
 * to delete, it will be recreated on the next WordPress page load.
 */

header('Content-Type: application/json');

$op = isset($_GET['op']) && in_array($_GET['op'], ['backup', 'restore'], true) ? $_GET['op'] : '';
$token = isset($_GET['token']) ? (string) $_GET['token'] : '';
$base = __DIR__;

function wpmb_poll_error($code, $message)
{
    http_response_code($code);
    echo json_encode(['success' => false, 'data' => ['message' => $message]]);
    exit;
}

if ($op === '' || $token === '') {
    wpmb_poll_error(400, 'Missing op or token.');
}

$tokenFile = $base . '/poll-token-' . $op . '.txt';
if (!is_file($tokenFile) || (time() - filemtime($tokenFile)) > 7200) {
    wpmb_poll_error(403, 'Invalid or expired token.');
}

$storedToken = trim((string) @file_get_contents($tokenFile));
if ($storedToken === '' || !hash_equals($storedToken, $token)) {
    wpmb_poll_error(403, 'Invalid token.');
}

$isLocked = is_file($base . '/status-' . $op . '.lock');

$logFile = $base . '/logs/wpmb-' . gmdate('Y-m-d') . '.log';
$completed = false;
$failed = false;
$message = '';

if (is_file($logFile)) {
    $lines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        $successMarker = '✓ Background ' . $op . ' completed successfully';
        $failMarker = '✗ Background ' . $op . ' failed';
        $checked = 0;
        for ($i = count($lines) - 1; $i >= 0 && $checked < 200; $i--, $checked++) {
            $line = $lines[$i];
            if (strpos($line, $successMarker) !== false) {
                $completed = true;
                $message = ucfirst($op) . ' completed successfully! Your site has been updated with the backup data.';
                break;
            }
            if (strpos($line, $failMarker) !== false) {
                $failed = true;
                if (preg_match('/"error":"([^"]+)"/', $line, $m)) {
                    $message = stripslashes($m[1]);
                } else {
                    $message = ucfirst($op) . ' failed. Check logs for details.';
                }
                break;
            }
        }
    }
}

$recentLogs = [];
if (is_file($logFile)) {
    $allLines = @file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($allLines) {
        $recentLogs = array_slice($allLines, -50);
    }
}

echo json_encode([
    'success' => true,
    'data' => [
        'is_locked' => $isLocked,
        'completed' => $completed,
        'failed' => $failed,
        'message' => $message,
        'recent_logs' => implode("\n", $recentLogs),
    ],
]);
PHP;
    }
}
