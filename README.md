# WP Migrate Lite

WP Migrate Lite is a point-and-click backup and restore utility that installs as a WordPress admin plugin. It creates verifiable archives containing the database dump and `wp-content`, applies safety snapshots before restores, and keeps a concise event log so migrations between live and local sites stay predictable.

## Feature Highlights

- Sidebar admin page with one-click backup, upload, and restore controls
- Archives named with timestamp, environment (live/local/staging), and hostname for quick identification
- Automatic SQL dumps with chunked inserts and serialized-data handling
- Safety backup captured before every restore and conflict-free locking to block concurrent runs
- Daily housekeeping clears temp files, expires download tokens, and enforces retention (10 archives by default)
- Recent activity log visible in the UI plus downloadable ZIP tokens for off-site storage

## Using the Admin Console

1. Open **WP Migrate** in the WordPress dashboard.
2. Run **Create Backup** on the source site (live or local). The job captures database + `wp-content` while skipping `wp-config.php` so credentials stay untouched.
3. Use **Download** to retrieve the ZIP and move it to the target machine’s `wp-content/wpmb-backups/archives` directory (or use **Upload Backup** in the UI).
4. Refresh the page; the archive appears in **Available Backups** with host and environment metadata.
5. Click **Restore** to apply the snapshot. The plugin first records a safety backup of the current state, then imports SQL and syncs `wp-content`.

You can repeat the same flow in reverse to push local changes back to live—generate a local archive, download it, and upload it within the live dashboard before restoring.

## Operational Notes

- Requires the PHP Zip extension and filesystem write access to `wp-content/wpmb-backups`.
- The plugin excludes its own backup directories from new archives to prevent recursion.
- `wp-config.php` is never overwritten; existing credentials remain intact across migrations.
- All actions log to `wp-content/wpmb-backups/logs/wpmb-YYYY-MM-DD.log`, and the latest entries surface in the admin panel.
- A safety backup labeled `pre-restore` is created automatically before any restore, providing an immediate rollback point.

## Housekeeping

The scheduled task `wpmb_daily_housekeeping` prunes expired download tokens, clears temporary files, and trims the archive list down to the configured retention. Adjust the retention value via the code paths that call the backup manager if you need to preserve more snapshots.
