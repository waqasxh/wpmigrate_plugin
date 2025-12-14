<?php
class WPMB_Admin_Page
{
    private const SLUG = 'wpmb-migrate';

    public static function init()
    {
        add_action('admin_menu', [self::class, 'register']);
        add_action('admin_post_wpmb_create_backup', [self::class, 'handle_create_backup']);
        add_action('admin_post_wpmb_restore_backup', [self::class, 'handle_restore_backup']);
        add_action('admin_post_wpmb_upload_backup', [self::class, 'handle_upload_backup']);
        add_action('admin_post_wpmb_delete_backup', [self::class, 'handle_delete_backup']);

        // AJAX handlers
        add_action('wp_ajax_wpmb_create_backup_ajax', [self::class, 'ajax_create_backup']);
        add_action('wp_ajax_wpmb_restore_backup_ajax', [self::class, 'ajax_restore_backup']);
        add_action('wp_ajax_wpmb_check_operation_status', [self::class, 'ajax_check_operation_status']);
        add_action('wp_ajax_wpmb_clear_logs', [self::class, 'ajax_clear_logs']);
        add_action('wp_ajax_wpmb_get_logs', [self::class, 'ajax_get_logs']);
        add_action('wp_ajax_wpmb_clear_lock', [self::class, 'ajax_clear_lock']);

        add_action('admin_enqueue_scripts', [self::class, 'enqueue_scripts']);
    }

    public static function register()
    {
        add_menu_page(
            __('WP Migrate Lite', 'wpmb'),
            __('WP Migrate Lite', 'wpmb'),
            'manage_options',
            self::SLUG,
            [self::class, 'render'],
            'dashicons-migrate',
            58
        );
    }

    public static function enqueue_scripts($hook)
    {
        if (strpos($hook, self::SLUG) === false) {
            return;
        }

        wp_enqueue_script(
            'wpmb-admin',
            plugins_url('assets/admin.js', dirname(__FILE__)),
            ['jquery'],
            '1.0.1',
            true
        );

        wp_localize_script('wpmb-admin', 'wpmbAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpmb_ajax'),
            'strings' => [
                'backupInProgress' => __('Creating backup... This may take several minutes. Please do not close this page.', 'wpmb'),
                'restoreInProgress' => __('Restoring backup... This may take several minutes. Please do not close this page.', 'wpmb'),
                'confirmRestore' => __('Restore this backup? Current site files and database will be replaced.', 'wpmb'),
                'confirmDelete' => __('Delete this backup file? This cannot be undone.', 'wpmb'),
                'confirmClearLogs' => __('Clear all log files? This cannot be undone.', 'wpmb'),
                'operationComplete' => __('Operation completed!', 'wpmb'),
                'operationFailed' => __('Operation failed. Check logs for details.', 'wpmb'),
            ],
        ]);

        wp_enqueue_style(
            'wpmb-admin',
            plugins_url('assets/admin.css', dirname(__FILE__)),
            [],
            '1.0.0'
        );
    }

    public static function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Write a page view log entry to ensure logging is working
        WPMB_Log::write('Admin page viewed', [
            'user' => wp_get_current_user()->user_login,
            'time' => gmdate('Y-m-d H:i:s'),
        ]);

        $archives = WPMB_Backup_Manager::list_archives();
        $logs = self::tail_logs(20);

        $notice = '';
        if (isset($_GET['wpmb_notice'])) {
            $notice = sanitize_text_field(wp_unslash($_GET['wpmb_notice']));
        }
        $notice_type = isset($_GET['wpmb_notice_type']) ? sanitize_text_field(wp_unslash($_GET['wpmb_notice_type'])) : 'updated';
?>
        <div class="wrap">
            <h1><?php esc_html_e('WP Migrate Lite', 'wpmb'); ?></h1>

            <?php if ($notice) : ?>
                <div class="<?php echo esc_attr($notice_type); ?> notice">
                    <p><?php echo esc_html($notice); ?></p>
                </div>
            <?php endif; ?>

            <?php
            // Handle test log request
            if (isset($_GET['test_log']) && check_admin_referer('test_log', '_wpnonce')) {
                WPMB_Log::write('Test log entry - manual trigger', [
                    'timestamp' => time(),
                    'user' => wp_get_current_user()->user_login,
                    'test_data' => 'This is a test to verify logging is working',
                ]);
                echo '<div class="notice notice-success"><p><strong>Test log written!</strong> Check the logs below. If nothing appears, check PHP error log for details.</p></div>';
            }

            // Check for active locks
            $backup_locked = WPMB_Lock::is_locked('backup');
            $restore_locked = WPMB_Lock::is_locked('restore');
            if ($backup_locked || $restore_locked) {
                $lock_type = $backup_locked ? 'backup' : 'restore';
                ?>
                <div class="notice notice-warning">
                    <p>
                        <strong>⚠️ <?php echo esc_html(ucfirst($lock_type)); ?> operation in progress or stale lock detected.</strong><br>
                        If no operation is running, this may be a stale lock from a previous operation that didn't complete.
                        <button type="button" class="button button-small" id="wpmb-clear-lock" style="margin-left:10px;">
                            Clear Lock
                        </button>
                    </p>
                </div>
                <?php
            }
            ?>

            <div class="wpmb-panels">
                <div class="wpmb-panel">
                    <h2><?php esc_html_e('Create Backup', 'wpmb'); ?></h2>
                    <form id="wpmb-backup-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wpmb_create_backup'); ?>
                        <input type="hidden" name="action" value="wpmb_create_backup" />
                        <p><?php esc_html_e('Creates a full site archive (database + wp-content). Labels are auto-generated from the site domain and timestamp.', 'wpmb'); ?></p>
                        <div id="wpmb-backup-status" style="display:none;margin:10px 0;padding:10px;background:#fff8e5;border-left:4px solid #ffb900;"></div>
                        <p><button type="submit" class="button button-primary" id="wpmb-backup-btn"><?php esc_html_e('Run Backup', 'wpmb'); ?></button></p>
                    </form>
                </div>

                <div class="wpmb-panel">
                    <h2><?php esc_html_e('Upload Backup', 'wpmb'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" enctype="multipart/form-data">
                        <?php wp_nonce_field('wpmb_upload_backup'); ?>
                        <input type="hidden" name="action" value="wpmb_upload_backup" />
                        <p>
                            <label for="wpmb-archive"><?php esc_html_e('Select ZIP Archive', 'wpmb'); ?></label><br />
                            <input type="file" id="wpmb-archive" name="archive" accept="application/zip" required />
                        </p>
                        <p><?php esc_html_e('Use this to import an archive from another environment.', 'wpmb'); ?></p>
                        <p><button type="submit" class="button"><?php esc_html_e('Upload', 'wpmb'); ?></button></p>
                    </form>
                </div>
            </div>

            <h2><?php esc_html_e('Available Backups', 'wpmb'); ?></h2>
            <?php if (!$archives) : ?>
                <p><?php esc_html_e('No backups found yet.', 'wpmb'); ?></p>
            <?php else : ?>
                <table class="widefat striped">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Label', 'wpmb'); ?></th>
                            <th><?php esc_html_e('Created (UTC)', 'wpmb'); ?></th>
                            <th><?php esc_html_e('Origin', 'wpmb'); ?></th>
                            <th><?php esc_html_e('Size', 'wpmb'); ?></th>
                            <th><?php esc_html_e('Actions', 'wpmb'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archives as $archive) :
                            $download_token = WPMB_Token::issue($archive['path'], HOUR_IN_SECONDS);
                            $download_url = add_query_arg([
                                'action' => 'wpmb_download',
                                'token' => $download_token,
                            ], admin_url('admin-post.php'));
                        ?>
                            <tr>
                                <td><?php echo esc_html($archive['label'] ?? $archive['id']); ?></td>
                                <td><?php echo esc_html($archive['created_at_gmt'] ?? ''); ?></td>
                                <td><?php echo esc_html(self::origin_from_manifest($archive)); ?></td>
                                <td><?php echo esc_html(size_format($archive['filesize'] ?? 0)); ?></td>
                                <td>
                                    <a class="button" href="<?php echo esc_url($download_url); ?>"><?php esc_html_e('Download', 'wpmb'); ?></a>
                                    <form style="display:inline" class="wpmb-restore-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('wpmb_restore_backup'); ?>
                                        <input type="hidden" name="action" value="wpmb_restore_backup" />
                                        <input type="hidden" name="archive_id" value="<?php echo esc_attr($archive['id']); ?>" />
                                        <input type="hidden" name="archive_path" value="<?php echo esc_attr($archive['path']); ?>" />
                                        <button type="submit" class="button button-secondary wpmb-restore-btn">
                                            <?php esc_html_e('Restore', 'wpmb'); ?>
                                        </button>
                                    </form>
                                    <form style="display:inline" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" onsubmit="return confirm('<?php echo esc_js(__('Delete this backup file? This cannot be undone.', 'wpmb')); ?>');">
                                        <?php wp_nonce_field('wpmb_delete_backup'); ?>
                                        <input type="hidden" name="action" value="wpmb_delete_backup" />
                                        <input type="hidden" name="archive_id" value="<?php echo esc_attr($archive['id']); ?>" />
                                        <input type="hidden" name="archive_path" value="<?php echo esc_attr($archive['path']); ?>" />
                                        <button type="submit" class="button"><?php esc_html_e('Delete', 'wpmb'); ?></button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>

            <h2><?php esc_html_e('Recent Logs', 'wpmb'); ?></h2>
            <?php
            $log_dir = WPMB_Log::get_log_dir();
            $log_file = $log_dir . '/wpmb-' . gmdate('Y-m-d') . '.log';
            ?>
            <p style="color:#666;font-size:12px;">
                Log directory: <code><?php echo esc_html($log_dir); ?></code><br>
                Today's log: <code><?php echo esc_html(basename($log_file)); ?></code>
                <?php if (file_exists($log_file)): ?>
                    (<?php echo esc_html(size_format(filesize($log_file))); ?>)
                <?php else: ?>
                    <span style="color:orange;">(not created yet)</span>
                <?php endif; ?>
            </p>
            <?php if (!$logs) : ?>
                <div class="notice notice-warning inline">
                    <p><strong><?php esc_html_e('No log entries found.', 'wpmb'); ?></strong></p>
                    <p><?php esc_html_e('Logs will appear here after you create a backup or restore. If logs never appear, check:', 'wpmb'); ?></p>
                    <ul style="list-style:disc;margin-left:2em;">
                        <li>Write permissions on: <code><?php echo esc_html($log_dir); ?></code></li>
                        <li>PHP error log for "[WP Migrate Lite]" messages</li>
                        <li>Temp fallback logs in: <code><?php echo esc_html(sys_get_temp_dir()); ?></code></li>
                    </ul>
                    <p>
                        <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=' . self::SLUG . '&test_log=1'), 'test_log')); ?>" class="button">
                            <?php esc_html_e('Test Logging', 'wpmb'); ?>
                        </a>
                    </p>
                </div>
            <?php else : ?>
                <pre id="wpmb-logs" style="background:#1e1e1e;color:#dcdcdc;padding:1em;max-height:300px;overflow:auto;border:1px solid #ccc;"><?php echo esc_html(implode("\n", $logs)); ?></pre>
                <p style="margin-top:0.5em;">
                    <button type="button" class="button button-small" id="wpmb-refresh-logs">
                        <?php esc_html_e('Refresh Logs', 'wpmb'); ?>
                    </button>
                    <button type="button" class="button button-small" id="wpmb-clear-logs">
                        <?php esc_html_e('Clear All Logs', 'wpmb'); ?>
                    </button>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin.php?page=' . self::SLUG . '&test_log=1'), 'test_log')); ?>" class="button button-small">
                        <?php esc_html_e('Test Logging', 'wpmb'); ?>
                    </a>
                    <span style="color:#666;margin-left:1em;font-size:12px;">
                        Showing last <?php echo count($logs); ?> entries
                    </span>
                </p>
            <?php endif; ?>
        </div>
<?php
    }

    public static function handle_create_backup()
    {
        self::guard('wpmb_create_backup');

        $label = self::default_label();

        try {
            WPMB_Backup_Manager::create([
                'label' => $label,
                'include_files' => true,
                'include_database' => true,
            ]);
            self::redirect_with_notice(__('Backup created successfully.', 'wpmb'));
        } catch (Throwable $e) {
            WPMB_Log::write('Backup request failed', ['error' => $e->getMessage()]);
            self::redirect_with_notice($e->getMessage(), 'error');
        }
    }

    public static function handle_restore_backup()
    {
        self::guard('wpmb_restore_backup');

        $archive_id = isset($_POST['archive_id']) ? sanitize_text_field(wp_unslash($_POST['archive_id'])) : '';
        $archive_path_field = isset($_POST['archive_path']) ? wp_unslash($_POST['archive_path']) : '';
        $archive_path = $archive_path_field ? WPMB_Backup_Manager::validate_path($archive_path_field) : null;

        if (!$archive_path && $archive_id) {
            $archive_path = WPMB_Backup_Manager::resolve_id($archive_id);
        }

        if (!$archive_path) {
            self::redirect_with_notice(__('Backup archive not found. Refresh this page and try again.', 'wpmb'), 'error');
        }

        WPMB_Log::write('Restore operation initiated by user', [
            'user' => wp_get_current_user()->user_login,
            'archive' => basename($archive_path),
            'archive_size' => size_format(filesize($archive_path)),
        ]);

        try {
            WPMB_Restore_Manager::restore([
                'archive_id' => $archive_id,
                'archive_path' => $archive_path,
                'drop_tables' => true,
                'safety_backup' => true,
            ]);

            WPMB_Log::write('Restore operation completed successfully by user', [
                'user' => wp_get_current_user()->user_login,
                'archive' => basename($archive_path),
            ]);

            self::redirect_with_notice(
                __('✓ Restore completed successfully! Your site has been updated with the backup data.', 'wpmb')
            );
        } catch (Throwable $e) {
            WPMB_Log::write('Restore operation failed', [
                'user' => wp_get_current_user()->user_login,
                'error' => $e->getMessage(),
                'archive' => basename($archive_path),
            ]);

            // Check if this was a rollback error (contains specific text)
            $errorMsg = $e->getMessage();
            $isRolledBack = strpos($errorMsg, 'restored to its previous') !== false;
            $isCritical = strpos($errorMsg, 'CRITICAL ERROR') !== false;

            if ($isCritical) {
                // Critical error - both restore and rollback failed
                $userMessage = '⚠️ CRITICAL: ' . $errorMsg . ' Please check the logs for details and contact support if needed.';
                $noticeType = 'error';
            } elseif ($isRolledBack) {
                // Restore failed but rollback succeeded
                $userMessage = '⚠️ ' . $errorMsg . ' Your site is still working normally.';
                $noticeType = 'error';
            } else {
                // Regular restore failure
                $userMessage = '⚠️ Restore failed: ' . $errorMsg . ' Please check the logs for details.';
                $noticeType = 'error';
            }

            self::redirect_with_notice($userMessage, $noticeType);
        }
    }

    public static function handle_upload_backup()
    {
        self::guard('wpmb_upload_backup');

        WPMB_Log::write('Backup upload initiated', ['user' => wp_get_current_user()->user_login]);

        if (empty($_FILES['archive']['tmp_name'])) {
            WPMB_Log::write('Upload failed - no file received');
            self::redirect_with_notice(__('No file uploaded.', 'wpmb'), 'error');
        }

        $file = $_FILES['archive'];
        $tmp = $file['tmp_name'];

        WPMB_Log::write('Processing uploaded file', [
            'original_name' => $file['name'],
            'size' => size_format($file['size']),
            'type' => $file['type'],
        ]);

        if (!is_uploaded_file($tmp)) {
            WPMB_Log::write('Upload failed - integrity check failed');
            self::redirect_with_notice(__('Upload failed integrity check.', 'wpmb'), 'error');
        }

        $destination = WPMB_Paths::temp_file('wpmb_upload');
        if (!move_uploaded_file($tmp, $destination)) {
            WPMB_Log::write('Upload failed - cannot move file', ['destination' => $destination]);
            self::redirect_with_notice(__('Unable to move uploaded file.', 'wpmb'), 'error');
        }

        try {
            $manifest = WPMB_Backup_Manager::ingest($destination, self::environment_label('imported'));
            WPMB_Log::write('Backup uploaded successfully', [
                'archive_id' => $manifest['id'] ?? 'unknown',
                'filesize' => size_format($manifest['filesize'] ?? 0),
            ]);
            self::redirect_with_notice(sprintf(
                /* translators: %s Archive ID */
                __('Backup %s uploaded.', 'wpmb'),
                $manifest['id'] ?? basename($manifest['path'])
            ));
        } catch (Throwable $e) {
            WPMB_Log::write('Backup upload failed', ['error' => $e->getMessage()]);
            self::redirect_with_notice($e->getMessage(), 'error');
        } finally {
            @unlink($destination);
        }
    }

    public static function handle_delete_backup()
    {
        self::guard('wpmb_delete_backup');

        $archive_id = isset($_POST['archive_id']) ? sanitize_text_field(wp_unslash($_POST['archive_id'])) : '';
        $archive_path_field = isset($_POST['archive_path']) ? wp_unslash($_POST['archive_path']) : '';
        $archive_path = $archive_path_field ? WPMB_Backup_Manager::validate_path($archive_path_field) : null;

        if (!$archive_path && $archive_id) {
            $archive_path = WPMB_Backup_Manager::resolve_id($archive_id);
        }

        if (!$archive_path) {
            WPMB_Log::write('Delete failed - archive not found', ['archive_id' => $archive_id]);
            self::redirect_with_notice(__('Backup archive could not be located. It may have already been removed.', 'wpmb'), 'error');
        }

        WPMB_Log::write('Deleting backup', [
            'archive_id' => $archive_id,
            'archive' => basename($archive_path),
            'user' => wp_get_current_user()->user_login,
        ]);

        if (WPMB_Backup_Manager::delete_by_path($archive_path)) {
            self::redirect_with_notice(__('Backup deleted.', 'wpmb'));
        }

        WPMB_Log::write('Delete failed - unable to remove file', ['archive' => basename($archive_path)]);
        self::redirect_with_notice(__('Unable to delete backup file.', 'wpmb'), 'error');
    }

    private static function guard($nonce_action)
    {
        if (!current_user_can('manage_options')) {
            wp_die(__('Insufficient permissions.', 'wpmb'));
        }
        check_admin_referer($nonce_action);
    }

    private static function redirect_with_notice($message, $type = 'updated')
    {
        $url = add_query_arg([
            'page' => self::SLUG,
            'wpmb_notice' => $message,
            'wpmb_notice_type' => $type,
        ], admin_url('admin.php'));

        wp_safe_redirect($url);
        exit;
    }

    private static function tail_logs($lines = 10)
    {
        // Use the improved log retrieval method
        $entries = WPMB_Log::get_recent_entries($lines);

        // Fallback to old method if new one fails
        if (empty($entries)) {
            try {
                $log_dir = WPMB_Paths::logs_dir();
                $files = glob($log_dir . '/*.log');
                if (!$files) {
                    return ['[No log files found. Check directory: ' . $log_dir . ']'];
                }

                rsort($files);
                $latest = $files[0];
                $content = @file($latest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                if (!$content) {
                    return ['[Log file exists but could not be read: ' . basename($latest) . ']'];
                }

                return array_slice($content, -absint($lines));
            } catch (Throwable $e) {
                return ['[Error reading logs: ' . $e->getMessage() . ']'];
            }
        }

        return $entries;
    }

    private static function origin_from_manifest(array $manifest)
    {
        if (!empty($manifest['site_url'])) {
            $host = wp_parse_url($manifest['site_url'], PHP_URL_HOST);
            if ($host) {
                return $host;
            }
        }
        return __('Unknown', 'wpmb');
    }

    private static function default_label()
    {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $host = $host ? sanitize_title($host) : 'site';
        return sprintf('%s-%s', $host, gmdate('Ymd-His'));
    }

    private static function environment_label($suffix)
    {
        $env = function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production';
        switch ($env) {
            case 'local':
            case 'development':
                $prefix = 'local';
                break;
            case 'staging':
                $prefix = 'staging';
                break;
            default:
                $prefix = 'live';
        }

        return $prefix . '-' . sanitize_title($suffix);
    }

    // AJAX Handlers

    public static function ajax_create_backup()
    {
        check_ajax_referer('wpmb_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'wpmb')]);
        }

        $label = self::default_label();

        try {
            $result = WPMB_Backup_Manager::create([
                'label' => $label,
                'include_files' => true,
                'include_database' => true,
            ]);

            wp_send_json_success([
                'message' => __('✓ Backup created successfully!', 'wpmb'),
                'archive' => $result['id'] ?? basename($result['path']),
                'size' => size_format($result['filesize'] ?? 0),
            ]);
        } catch (Throwable $e) {
            WPMB_Log::write('Backup request failed via AJAX', ['error' => $e->getMessage()]);
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function ajax_restore_backup()
    {
        check_ajax_referer('wpmb_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'wpmb')]);
        }

        $archive_id = isset($_POST['archive_id']) ? sanitize_text_field(wp_unslash($_POST['archive_id'])) : '';
        $archive_path_field = isset($_POST['archive_path']) ? wp_unslash($_POST['archive_path']) : '';
        $archive_path = $archive_path_field ? WPMB_Backup_Manager::validate_path($archive_path_field) : null;

        if (!$archive_path && $archive_id) {
            $archive_path = WPMB_Backup_Manager::resolve_id($archive_id);
        }

        if (!$archive_path) {
            wp_send_json_error(['message' => __('Backup archive not found.', 'wpmb')]);
        }

        WPMB_Log::write('Restore operation initiated by user via AJAX', [
            'user' => wp_get_current_user()->user_login,
            'archive' => basename($archive_path),
        ]);

        try {
            WPMB_Restore_Manager::restore([
                'archive_id' => $archive_id,
                'archive_path' => $archive_path,
                'drop_tables' => true,
                'safety_backup' => true,
            ]);

            wp_send_json_success([
                'message' => __('✓ Restore completed successfully! Your site has been updated with the backup data.', 'wpmb'),
            ]);
        } catch (Throwable $e) {
            WPMB_Log::write('Restore operation failed via AJAX', [
                'user' => wp_get_current_user()->user_login,
                'error' => $e->getMessage(),
            ]);

            $errorMsg = $e->getMessage();
            $isRolledBack = strpos($errorMsg, 'restored to its previous') !== false;
            $isCritical = strpos($errorMsg, 'CRITICAL ERROR') !== false;

            if ($isCritical) {
                $userMessage = '⚠️ CRITICAL: ' . $errorMsg;
            } elseif ($isRolledBack) {
                $userMessage = '⚠️ ' . $errorMsg . ' Your site is still working normally.';
            } else {
                $userMessage = '⚠️ Restore failed: ' . $errorMsg;
            }

            wp_send_json_error(['message' => $userMessage]);
        }
    }

    public static function ajax_get_logs()
    {
        check_ajax_referer('wpmb_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'wpmb')]);
        }

        $logs = self::tail_logs(20);
        wp_send_json_success(['logs' => implode("\n", $logs)]);
    }

    public static function ajax_clear_logs()
    {
        check_ajax_referer('wpmb_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'wpmb')]);
        }

        try {
            $log_dir = WPMB_Paths::logs_dir();
            $files = glob($log_dir . '/*.log');
            $deleted = 0;

            if ($files) {
                foreach ($files as $file) {
                    if (unlink($file)) {
                        $deleted++;
                    }
                }
            }

            WPMB_Log::write('Logs cleared by user', [
                'user' => wp_get_current_user()->user_login,
                'files_deleted' => $deleted,
            ]);

            wp_send_json_success([
                'message' => sprintf(__('%d log file(s) cleared successfully.', 'wpmb'), $deleted),
            ]);
        } catch (Throwable $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    public static function ajax_check_operation_status()
    {
        check_ajax_referer('wpmb_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'wpmb')]);
        }

        // Check if any operation is in progress
        $backup_lock = WPMB_Lock::is_locked('backup');
        $restore_lock = WPMB_Lock::is_locked('restore');

        wp_send_json_success([
            'backup_in_progress' => $backup_lock,
            'restore_in_progress' => $restore_lock,
        ]);
    }

    public static function ajax_clear_lock()
    {
        check_ajax_referer('wpmb_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Insufficient permissions.', 'wpmb')]);
        }

        // Clear both backup and restore locks
        WPMB_Lock::force_release('backup');
        WPMB_Lock::force_release('restore');

        WPMB_Log::write('Locks manually cleared by user', [
            'user' => wp_get_current_user()->user_login,
        ]);

        wp_send_json_success([
            'message' => __('All locks cleared successfully. You can now run operations.', 'wpmb'),
        ]);
    }
}
