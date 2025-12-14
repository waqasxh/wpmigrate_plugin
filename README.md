# WP Migrate Lite

WP Migrate Lite is a point-and-click backup and restore utility that installs as a WordPress admin plugin. It creates verifiable archives containing the database dump and `wp-content`, applies safety snapshots before restores, and keeps a comprehensive event log so migrations between live and local sites stay predictable.

## Feature Highlights

- Sidebar admin page with one-click backup, upload, and restore controls
- Archives named with timestamp, environment (live/local/staging), and hostname for quick identification
- Automatic SQL dumps with proper MySQL escaping and chunked inserts
- **Automatic URL replacement** - live URLs converted to local (and vice versa) during restore
- **Database repair and optimization** after import to ensure table integrity
- **Safety backup with automatic rollback** - if restore fails, your site automatically reverts to previous state
- **Comprehensive logging system** with multiple fallbacks for complete visibility
- **Async background backups** - admin requests hand off to a non-blocking worker with live status polling
- **mysqldump acceleration** - automatically switches to the native CLI exporter when available for large datasets
- Conflict-free locking to block concurrent operations
- Daily housekeeping clears temp files, expires download tokens, and enforces retention (10 archives by default)
- Recent activity log visible in the UI plus downloadable ZIP tokens for off-site storage

## Using the Admin Console

1. Open **WP Migrate** in the WordPress dashboard.
2. Run **Create Backup** on the source site (live or local). The job captures database + `wp-content` while skipping `wp-config.php` so credentials stay untouched.
3. Use **Download** to retrieve the ZIP and move it to the target machine's `wp-content/wpmb-backups/archives` directory (or use **Upload Backup** in the UI).
4. Refresh the page; the archive appears in **Available Backups** with host and environment metadata.
5. Click **Restore** to apply the snapshot. The plugin automatically:
   - Creates a safety backup of your current state
   - Imports the SQL database with proper table prefix conversion
   - **Replaces all old URLs with new environment URLs**
   - **Repairs and optimizes all database tables**
   - Syncs `wp-content` files
   - **Automatically rolls back if any step fails**

You can repeat the same flow in reverse to push local changes back to live—generate a local archive, download it, and upload it within the live dashboard before restoring.

## Operational Notes

- Requires the PHP Zip extension and filesystem write access to `wp-content/wpmb-backups`.
- The plugin excludes its own backup directories from new archives to prevent recursion.
- `wp-config.php` is never overwritten; existing credentials remain intact across migrations.
- Backup requests answer immediately and continue on the server via authenticated loopback, preventing browser timeouts on large sites.
- When `mysqldump` is installed and `proc_open`/`shell_exec` are permitted, database exports use the native CLI for maximum throughput. Override the binary path with the `wpmb_mysqldump_binary` filter if needed.
- All actions log to `wp-content/wpmb-backups/logs/wpmb-YYYY-MM-DD.log`, and the latest entries surface in the admin panel.
- A safety backup labeled `pre-restore` is created automatically before any restore, providing an immediate rollback point.
- If restore fails, the plugin automatically restores your previous state and displays a clear error message.

## Recent Fixes & Enhancements

### ✅ SQL Escaping Fix (Critical)

Fixed critical bug where database dumps used improper escaping, causing SQL syntax errors during import. Now uses `$wpdb->_real_escape()` for proper MySQL character escaping, especially important for serialized PHP data.

**What to do:** Create fresh backups after this update - old backups may have improperly escaped SQL.

### ✅ Automatic URL Replacement

When restoring between environments, the plugin now automatically:

- Detects source site URLs from backup manifest
- Replaces all occurrences in database (including serialized data)
- Updates WordPress core options (siteurl, home)
- Processes ALL tables, not just posts and options
- Logs all replacement operations for verification

**Fixes:** "One or more database tables are unavailable" error caused by URL mismatches.

### ✅ Database Repair & Optimization

After every restore, the plugin now:

- Runs `REPAIR TABLE` on all imported tables
- Runs `OPTIMIZE TABLE` to rebuild indexes
- Validates all WordPress core tables exist
- Logs repair results

**Fixes:** Corrupted indexes and missing tables after import.

### ✅ Automatic Rollback on Failure

Restore operations now include three-level safety:

1. **Safety backup** - automatic backup before restore begins
2. **Failure detection** - catches any errors during restore
3. **Automatic rollback** - restores previous state if anything fails

**Result:** Your site never stays in a broken state. If restore fails, you're automatically back to where you started.

### ✅ Enhanced Logging System

Complete rebuild of the logging system with:

- 300+ comprehensive log statements throughout all operations
- Multiple fallback mechanisms (file, PHP error_log, temp directory)
- Directory permission validation
- Progress updates every 100 items
- Line-number tracking for SQL errors
- User-friendly error messages with clear instructions

### ✅ Async Backups & CLI Dump (Performance)

- Backup AJAX calls now dispatch to a background worker, eliminating front-end timeouts while still enforcing operation locks and capability checks.
- Database dumping prefers `mysqldump` (with safe environment handling) whenever the host permits it, falling back to the PHP chunked exporter otherwise.
- Large-table exports paginate by primary key and log periodic progress so you can confirm long-running tables (e.g., Action Scheduler) are advancing.
- Log output includes "Background backup dispatched" and "Using mysqldump" markers to make troubleshooting effortless.

## Logging

### Log File Location

All logs are stored in: `wp-content/wpmb-backups/logs/wpmb-YYYY-MM-DD.log`

Each log entry includes:

- Timestamp (UTC)
- Operation description
- Contextual data (JSON format)

### Viewing Logs

**Method 1: WordPress Admin** (Easiest)

1. Go to **WP Migrate Lite** in your WordPress admin menu
2. Scroll down to the **Recent Logs** section
3. The most recent log entries will be displayed
4. Click **Refresh Logs** to see the latest entries
5. Click **Test Logging** to verify the logging system is working

**Method 2: Direct File Access**

Navigate to the log directory and open the file:

```
wp-content/wpmb-backups/logs/wpmb-2025-12-14.log
```

**Method 3: Test Script**

Run the included test script:

```bash
cd wp-content/plugins/wp-migrate-lite
php test-logging.php
```

### Logging System Features

The plugin uses a robust logging system with multiple fallbacks:

1. **Primary:** Writes to daily log files in `wp-content/wpmb-backups/logs/`
2. **Backup:** Always logs to PHP error_log with `[WP Migrate Lite]` prefix
3. **Fallback:** If main directory fails, writes to system temp directory (`/tmp/` or `C:\Windows\Temp\`)
4. **Validation:** Tests directory permissions before first write

Together these guarantees mean you always have an audit trail, even if file logging fails.

### What Gets Logged

**Backup Operations:**

- Backup initialization and configuration
- Database dump progress (table-by-table)
- File archiving progress (every 100 files)
- Archive finalization and checksums
- Download token generation
- Retention policy enforcement

**Restore Operations:**

- Restore initialization with archive details
- Safety backup creation
- Archive extraction progress
- Database import (progress every 100 SQL statements)
- Table prefix conversion
- URL replacement operations (with counts)
- Database repair and optimization results
- File restoration progress
- Rollback operations (if needed)
- Final completion status

**Database Operations:**

- SQL dump generation (table-by-table)
- SQL import execution with line numbers
- SQL errors with statement preview
- Table prefix detection and conversion
- Table repair and optimization results

**Error Conditions:**

- Lock acquisition failures
- Missing files or archives
- SQL import failures with line numbers
- Permission problems
- Rollback triggers and results

### Example Log Output

Successful restore:

```
[2025-12-14 10:45:00 UTC] Restore started {"source":"20251214-103000-mysite-live-full-site.zip","safety_backup":true}
[2025-12-14 10:45:01 UTC] Creating safety backup before restore
[2025-12-14 10:45:05 UTC] Safety backup captured {"archive":"pre-restore.zip","filesize":"12.3 MB"}
[2025-12-14 10:45:06 UTC] Extracting archive {"temp_dir":"\/tmp\/restore_abc123"}
[2025-12-14 10:45:10 UTC] Archive extracted successfully {"num_files":1523}
[2025-12-14 10:45:11 UTC] Starting database import {"sql_file":"database.sql"}
[2025-12-14 10:45:15 UTC] SQL import completed {"total_statements":2543,"total_lines":12567}
[2025-12-14 10:45:16 UTC] URL replacement {"old_site_url":"https:\/\/mysite.com","new_site_url":"http:\/\/localhost\/mysite"}
[2025-12-14 10:45:20 UTC] URL replacement completed {"total_replacements":245}
[2025-12-14 10:45:21 UTC] Database maintenance completed {"tables_repaired":12,"tables_optimized":12}
[2025-12-14 10:45:26 UTC] Restore finished successfully
```

Failed restore with automatic rollback:

```
[2025-12-14 11:00:00 UTC] Restore started {"source":"corrupted.zip"}
[2025-12-14 11:00:01 UTC] Creating safety backup before restore
[2025-12-14 11:00:05 UTC] Safety backup captured {"archive":"pre-restore.zip"}
[2025-12-14 11:00:06 UTC] SQL import failed at statement {"line":165,"error":"Syntax error"}
[2025-12-14 11:00:07 UTC] Restore failed, initiating automatic rollback
[2025-12-14 11:00:08 UTC] Rolling back from safety backup {"archive":"pre-restore.zip"}
[2025-12-14 11:00:12 UTC] Rollback completed successfully
[2025-12-14 11:00:12 UTC] Restore failed with rollback {"error":"SQL import failed","status":"rolled_back"}
```

### Troubleshooting Logging

If logs don't appear in the admin panel:

1. **Check Directory Permissions:**

```bash
chmod 755 wp-content/wpmb-backups
chmod 755 wp-content/wpmb-backups/logs
```

2. **Check PHP Error Log:**

The plugin always logs to PHP error_log as a backup. Look for entries starting with `[WP Migrate Lite]`:

```bash
# Linux/Apache
tail -f /var/log/apache2/error.log | grep "WP Migrate"

# Windows/XAMPP
# Check: C:\xampp\php\logs\php_error_log
```

3. **Check Fallback Logs:**

If main logging fails, check system temp directory:

```bash
# Linux/Mac
ls -la /tmp/wpmb-*.log

# Windows
dir C:\Windows\Temp\wpmb-*.log
```

4. **Enable WordPress Debug:**

In `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Then check: `wp-content/debug.log`

## Housekeeping

The scheduled task `wpmb_daily_housekeeping` prunes expired download tokens, clears temporary files, and trims the archive list down to the configured retention (10 archives by default). Adjust the retention value via the code paths that call the backup manager if you need to preserve more snapshots.

All housekeeping operations are logged for auditing.
