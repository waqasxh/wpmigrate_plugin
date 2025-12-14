<?php
class WPMB_CLI_Command
{
    public static function register()
    {
        WP_CLI::add_command('wpmb backup', [self::class, 'backup']);
        WP_CLI::add_command('wpmb restore', [self::class, 'restore']);
        WP_CLI::add_command('wpmb list', [self::class, 'listing']);
    }

    public static function backup($args, $assocArgs)
    {
        $options = [
            'label' => $assocArgs['label'] ?? 'cli',
            'include_files' => !WP_CLI\Utils::get_flag_value($assocArgs, 'skip-files', false),
            'include_database' => !WP_CLI\Utils::get_flag_value($assocArgs, 'skip-db', false),
            'retention' => isset($assocArgs['retention']) ? (int) $assocArgs['retention'] : 10,
        ];

        try {
            $result = WPMB_Backup_Manager::create($options);
            WP_CLI::success('Backup created: ' . $result['id']);
            WP_CLI::line('Path: ' . $result['path']);
            WP_CLI::line('Download URL: ' . $result['download_url']);
        } catch (Throwable $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    public static function restore($args, $assocArgs)
    {
        $options = [
            'archive_id' => $args[0] ?? ($assocArgs['id'] ?? null),
            'archive_path' => $assocArgs['path'] ?? null,
            'source_url' => $assocArgs['url'] ?? null,
            'drop_tables' => !WP_CLI\Utils::get_flag_value($assocArgs, 'keep-tables', false),
            'safety_backup' => !WP_CLI\Utils::get_flag_value($assocArgs, 'no-backup', false),
        ];

        try {
            $summary = WPMB_Restore_Manager::restore($options);
            WP_CLI::success('Restore complete from ' . ($summary['id'] ?? 'archive'));
        } catch (Throwable $e) {
            WP_CLI::error($e->getMessage());
        }
    }

    public static function listing()
    {
        $archives = WPMB_Backup_Manager::list_archives();
        if (!$archives) {
            WP_CLI::line('No backups available.');
            return;
        }

        $items = [];
        foreach ($archives as $archive) {
            $items[] = [
                'id' => $archive['id'],
                'created' => $archive['created_at_gmt'],
                'size' => size_format($archive['filesize']),
            ];
        }

        WP_CLI\Utils::format_items('table', $items, ['id', 'created', 'size']);
    }
}
