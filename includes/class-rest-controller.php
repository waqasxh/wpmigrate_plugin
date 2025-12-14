<?php
class WPMB_REST_Controller
{
    public static function register()
    {
        register_rest_route('wpmb/v1', '/backups', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'list'],
            'permission_callback' => [self::class, 'permissions'],
        ]);

        register_rest_route('wpmb/v1', '/backups', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'create'],
            'permission_callback' => [self::class, 'permissions'],
            'args' => [
                'label' => ['type' => 'string'],
                'include_files' => ['type' => 'boolean'],
                'include_database' => ['type' => 'boolean'],
                'retention' => ['type' => 'integer'],
            ],
        ]);

        register_rest_route('wpmb/v1', '/backups/(?P<id>[\w\-]+)', [
            'methods' => WP_REST_Server::READABLE,
            'callback' => [self::class, 'detail'],
            'permission_callback' => [self::class, 'permissions'],
        ]);

        register_rest_route('wpmb/v1', '/backups/(?P<id>[\w\-]+)', [
            'methods' => WP_REST_Server::DELETABLE,
            'callback' => [self::class, 'delete'],
            'permission_callback' => [self::class, 'permissions'],
        ]);

        register_rest_route('wpmb/v1', '/restore', [
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => [self::class, 'restore'],
            'permission_callback' => [self::class, 'permissions'],
            'args' => [
                'archive_id' => ['type' => 'string'],
                'source_url' => ['type' => 'string'],
                'drop_tables' => ['type' => 'boolean'],
                'safety_backup' => ['type' => 'boolean'],
            ],
        ]);
    }

    public static function permissions()
    {
        return current_user_can('manage_options');
    }

    public static function list(WP_REST_Request $request)
    {
        $records = WPMB_Backup_Manager::list_archives();
        foreach ($records as &$record) {
            unset($record['path']);
        }
        return rest_ensure_response($records);
    }

    public static function create(WP_REST_Request $request)
    {
        try {
            $options = [
                'label' => $request->get_param('label') ?: 'api',
                'include_files' => $request->get_param('include_files') !== null ? (bool) $request->get_param('include_files') : true,
                'include_database' => $request->get_param('include_database') !== null ? (bool) $request->get_param('include_database') : true,
                'retention' => $request->get_param('retention') !== null ? (int) $request->get_param('retention') : 10,
            ];

            $result = WPMB_Backup_Manager::create($options);
            $payload = $result;
            unset($payload['path']);
            return rest_ensure_response($payload);
        } catch (Throwable $e) {
            return new WP_Error('wpmb_backup_failed', $e->getMessage(), ['status' => 500]);
        }
    }

    public static function detail(WP_REST_Request $request)
    {
        $id = sanitize_file_name($request['id']);
        $path = WPMB_Backup_Manager::resolve_id($id);
        if (!$path) {
            return new WP_Error('wpmb_not_found', 'Backup not found.', ['status' => 404]);
        }

        $manifest = WPMB_Backup_Manager::read_manifest($path);
        if (!$manifest) {
            return new WP_Error('wpmb_missing_manifest', 'Backup manifest missing.', ['status' => 500]);
        }

        $manifest['filesize'] = filesize($path);
        $manifest['checksum'] = md5_file($path);
        $manifest['download_token'] = WPMB_Token::issue($path, HOUR_IN_SECONDS);
        $manifest['download_url'] = add_query_arg([
            'action' => 'wpmb_download',
            'token' => $manifest['download_token'],
        ], admin_url('admin-post.php'));

        unset($manifest['path']);
        return rest_ensure_response($manifest);
    }

    public static function delete(WP_REST_Request $request)
    {
        $id = sanitize_file_name($request['id']);
        if (!WPMB_Backup_Manager::delete($id)) {
            return new WP_Error('wpmb_delete_failed', 'Unable to delete backup (already removed?).', ['status' => 404]);
        }

        return rest_ensure_response(['deleted' => true]);
    }

    public static function restore(WP_REST_Request $request)
    {
        try {
            $options = [
                'archive_id' => $request->get_param('archive_id'),
                'source_url' => $request->get_param('source_url'),
                'drop_tables' => $request->get_param('drop_tables') === null ? true : (bool) $request->get_param('drop_tables'),
                'safety_backup' => $request->get_param('safety_backup') === null ? true : (bool) $request->get_param('safety_backup'),
            ];

            $manifest = WPMB_Restore_Manager::restore($options);
            return rest_ensure_response($manifest);
        } catch (Throwable $e) {
            return new WP_Error('wpmb_restore_failed', $e->getMessage(), ['status' => 500]);
        }
    }
}
