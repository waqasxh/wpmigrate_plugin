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

    public static function render()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $archives = WPMB_Backup_Manager::list_archives();
        $logs = self::tail_logs();

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

            <div class="wpmb-panels">
                <div class="wpmb-panel">
                    <h2><?php esc_html_e('Create Backup', 'wpmb'); ?></h2>
                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                        <?php wp_nonce_field('wpmb_create_backup'); ?>
                        <input type="hidden" name="action" value="wpmb_create_backup" />
                        <p><?php esc_html_e('Creates a full site archive (database + wp-content). Labels are auto-generated from the site domain and timestamp.', 'wpmb'); ?></p>
                        <p><button type="submit" class="button button-primary"><?php esc_html_e('Run Backup', 'wpmb'); ?></button></p>
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
                                    <form style="display:inline" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('wpmb_restore_backup'); ?>
                                        <input type="hidden" name="action" value="wpmb_restore_backup" />
                                        <input type="hidden" name="archive_id" value="<?php echo esc_attr($archive['id']); ?>" />
                                        <input type="hidden" name="archive_path" value="<?php echo esc_attr($archive['path']); ?>" />
                                        <button type="submit" class="button button-secondary" onclick="return confirm('<?php echo esc_js(__('Restore this backup? Current site files and database will be replaced.', 'wpmb')); ?>');">
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
            <?php if (!$logs) : ?>
                <p><?php esc_html_e('No log entries available yet.', 'wpmb'); ?></p>
            <?php else : ?>
                <pre style="background:#1e1e1e;color:#dcdcdc;padding:1em;max-height:200px;overflow:auto;"><?php echo esc_html(implode("\n", $logs)); ?></pre>
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

        try {
            WPMB_Restore_Manager::restore([
                'archive_id' => $archive_id,
                'archive_path' => $archive_path,
                'drop_tables' => true,
                'safety_backup' => true,
            ]);
            self::redirect_with_notice(__('Restore completed.', 'wpmb'));
        } catch (Throwable $e) {
            WPMB_Log::write('Restore request failed', ['error' => $e->getMessage()]);
            self::redirect_with_notice($e->getMessage(), 'error');
        }
    }

    public static function handle_upload_backup()
    {
        self::guard('wpmb_upload_backup');

        if (empty($_FILES['archive']['tmp_name'])) {
            self::redirect_with_notice(__('No file uploaded.', 'wpmb'), 'error');
        }

        $file = $_FILES['archive'];
        $tmp = $file['tmp_name'];
        if (!is_uploaded_file($tmp)) {
            self::redirect_with_notice(__('Upload failed integrity check.', 'wpmb'), 'error');
        }

        $destination = WPMB_Paths::temp_file('wpmb_upload');
        if (!move_uploaded_file($tmp, $destination)) {
            self::redirect_with_notice(__('Unable to move uploaded file.', 'wpmb'), 'error');
        }

        try {
            $manifest = WPMB_Backup_Manager::ingest($destination, self::environment_label('imported'));
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
            self::redirect_with_notice(__('Backup archive could not be located. It may have already been removed.', 'wpmb'), 'error');
        }

        if (WPMB_Backup_Manager::delete_by_path($archive_path)) {
            self::redirect_with_notice(__('Backup deleted.', 'wpmb'));
        }

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
        $log_dir = WPMB_Paths::logs_dir();
        $files = glob($log_dir . '/*.log');
        if (!$files) {
            return [];
        }

        rsort($files);
        $latest = $files[0];
        $content = file($latest, FILE_IGNORE_NEW_LINES);
        if (!$content) {
            return [];
        }

        return array_slice($content, -absint($lines));
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
}
