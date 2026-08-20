jQuery(document).ready(function ($) {
    'use strict';

    var operationInProgress = false;
    var statusCheckInterval = null;

    // Backup form handler
    $('#wpmb-backup-form').on('submit', function (e) {
        e.preventDefault();

        if (operationInProgress) {
            alert('An operation is already in progress. Please wait.');
            return false;
        }

        var $btn = $('#wpmb-backup-btn');
        var $status = $('#wpmb-backup-status');
        var originalText = $btn.text();

        // Prevent double submission
        $btn.prop('disabled', true).text('Starting Backup...');
        $status.show().html('<strong>Starting backup process...</strong><br><em style="color:#666;">This will run in the background. Do not close this page.</em>');
        operationInProgress = true;

        // Start the backup in background
        $.ajax({
            url: wpmbAdmin.ajaxUrl,
            method: 'POST',
            data: {
                action: 'wpmb_create_backup_ajax',
                nonce: wpmbAdmin.nonce
            },
            timeout: 10000, // 10 seconds - just to start the process
            success: function (response) {
                console.log('Backup start response:', response);

                if (response.success && response.data.started) {
                    $btn.text('Backup Running...');
                    $status.html('<strong>⏳ Backup running in background...</strong><br>This may take several minutes. The page will update when complete.<br><em style="color:#666;">Do not close this page.</em>');

                    // Start polling for status
                    startBackupStatusPolling($btn, $status, originalText);
                    startStatusChecking(); // Start log refresh
                } else {
                    console.error('Backup did not start:', response);
                    operationInProgress = false;
                    $status.css({ background: '#f8d7da', borderColor: '#dc3545' })
                        .html('<strong>✗ Error:</strong> ' + (response.data ? response.data.message : 'Failed to start backup'));
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function (xhr, status, error) {
                console.error('Backup start error:', { xhr: xhr, status: status, error: error, responseText: xhr.responseText });
                operationInProgress = false;
                $status.css({ background: '#f8d7da', borderColor: '#dc3545' })
                    .html('<strong>✗ Error:</strong> Failed to start backup process. ' + error);
                $btn.prop('disabled', false).text(originalText);
                refreshLogs();
            }
        });

        return false;
    });

    // Restore form handler
    $(document).on('submit', '.wpmb-restore-form', function (e) {
        e.preventDefault();

        if (operationInProgress) {
            alert('An operation is already in progress. Please wait.');
            return false;
        }

        if (!confirm(wpmbAdmin.strings.confirmRestore)) {
            return false;
        }

        var $form = $(this);
        var $btn = $form.find('.wpmb-restore-btn');
        var originalText = $btn.text();
        var archiveId = $form.find('input[name="archive_id"]').val();
        var archivePath = $form.find('input[name="archive_path"]').val();

        // Create status area if it doesn't exist
        if ($('#wpmb-restore-status').length === 0) {
            $('<div id="wpmb-restore-status" style="margin:20px 0;padding:15px;background:#fff8e5;border-left:4px solid #ffb900;"></div>')
                .insertBefore('.widefat.striped');
        }

        var $status = $('#wpmb-restore-status');

        $btn.prop('disabled', true).text('Starting Restore...');
        $status.show().html('<strong>Starting restore process...</strong><br><em style="color:#666;">This will run in the background. Do not close this page.</em>');
        operationInProgress = true;

        // Start the restore in background - mirrors the backup flow: this
        // request only has to dispatch the background worker and return, the
        // actual restore runs in its own process so a slow host can't kill it
        // via the web request's execution-time limit.
        $.ajax({
            url: wpmbAdmin.ajaxUrl,
            method: 'POST',
            data: {
                action: 'wpmb_restore_backup_ajax',
                nonce: wpmbAdmin.nonce,
                archive_id: archiveId,
                archive_path: archivePath
            },
            timeout: 10000, // 10 seconds - just to start the process
            success: function (response) {
                console.log('Restore start response:', response);

                if (response.success && response.data.started) {
                    $btn.text('Restoring...');
                    $status.html('<strong>⏳ ' + wpmbAdmin.strings.restoreInProgress + '</strong><br>Creating safety backup, importing database, replacing URLs, and restoring files. This may take several minutes.<br><em style="color:#666;">Do not close this page.</em>');

                    // Restore locks the site with WordPress's own maintenance
                    // mode, which blocks admin-ajax.php too - poll the
                    // standalone poll.php endpoint instead, using the token
                    // this response just issued, so progress stays visible.
                    startRestoreStatusPolling($btn, $status, originalText, response.data.poll_url, response.data.poll_token);
                } else {
                    console.error('Restore did not start:', response);
                    operationInProgress = false;
                    $status.css({ background: '#f8d7da', borderColor: '#dc3545' })
                        .html('<strong>✗ Error:</strong> ' + (response.data ? response.data.message : 'Failed to start restore'));
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function (xhr, status, error) {
                console.error('Restore start error:', { xhr: xhr, status: status, error: error, responseText: xhr.responseText });
                operationInProgress = false;
                $status.css({ background: '#f8d7da', borderColor: '#dc3545' })
                    .html('<strong>✗ Error:</strong> Failed to start restore process. ' + error);
                $btn.prop('disabled', false).text(originalText);
                refreshLogs();
            }
        });

        return false;
    });

    // Refresh logs button
    $('#wpmb-refresh-logs').on('click', function (e) {
        e.preventDefault();
        refreshLogs();
    });
    $('#wpmb-clear-logs').on('click', function (e) {
        e.preventDefault();

        if (!confirm(wpmbAdmin.strings.confirmClearLogs)) {
            return;
        }

        var $btn = $(this);
        var originalText = $btn.text();

        $btn.prop('disabled', true).text('Clearing...');

        $.ajax({
            url: wpmbAdmin.ajaxUrl,
            method: 'POST',
            data: {
                action: 'wpmb_clear_logs',
                nonce: wpmbAdmin.nonce
            },
            success: function (response) {
                if (response.success) {
                    alert(response.data.message);
                    $('#wpmb-logs').text('[No logs - logs were cleared]');
                } else {
                    alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                }
                $btn.prop('disabled', false).text(originalText);
            },
            error: function (xhr, status, error) {
                alert('Failed to clear logs: ' + error);
                console.error('Clear logs error:', xhr.responseText);
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });

    // Clear lock button
    $('#wpmb-clear-lock').on('click', function (e) {
        e.preventDefault();

        if (!confirm('Clear all operation locks? Only do this if no backup or restore is actually running.')) {
            return;
        }

        var $btn = $(this);
        var originalText = $btn.text();

        $btn.prop('disabled', true).text('Clearing...');

        $.ajax({
            url: wpmbAdmin.ajaxUrl,
            method: 'POST',
            data: {
                action: 'wpmb_clear_lock',
                nonce: wpmbAdmin.nonce
            },
            success: function (response) {
                if (response.success) {
                    alert(response.data.message);
                    // Reload page to remove the warning
                    window.location.reload();
                } else {
                    alert('Error: ' + (response.data ? response.data.message : 'Unknown error'));
                    $btn.prop('disabled', false).text(originalText);
                }
            },
            error: function (xhr, status, error) {
                alert('Failed to clear lock: ' + error);
                console.error('Clear lock error:', xhr.responseText);
                $btn.prop('disabled', false).text(originalText);
            }
        });
    });

    // Function to poll backup status
    var backupStatusInterval = null;
    function startBackupStatusPolling($btn, $status, originalText) {
        var pollCount = 0;
        var maxPolls = 360; // 30 minutes (5 second intervals)

        backupStatusInterval = setInterval(function () {
            pollCount++;

            $.ajax({
                url: wpmbAdmin.ajaxUrl,
                method: 'POST',
                data: {
                    action: 'wpmb_check_backup_status',
                    nonce: wpmbAdmin.nonce
                },
                success: function (response) {
                    if (response.success) {
                        if (response.data.completed) {
                            // Backup completed successfully
                            clearInterval(backupStatusInterval);
                            stopStatusChecking();
                            operationInProgress = false;

                            $status.css({ background: '#d4edda', borderColor: '#28a745' })
                                .html('<strong>✓ ' + response.data.message + '</strong>');
                            $btn.prop('disabled', false).text(originalText);

                            // Refresh page after 2 seconds to show new backup
                            setTimeout(function () {
                                window.location.reload();
                            }, 2000);
                        } else if (response.data.failed) {
                            // Backup failed
                            clearInterval(backupStatusInterval);
                            stopStatusChecking();
                            operationInProgress = false;

                            $status.css({ background: '#f8d7da', borderColor: '#dc3545' })
                                .html('<strong>✗ Error:</strong> ' + response.data.message);
                            $btn.prop('disabled', false).text(originalText);
                        } else if (!response.data.is_locked && pollCount > 5) {
                            // Lock released but no completion message - check logs
                            clearInterval(backupStatusInterval);
                            stopStatusChecking();
                            operationInProgress = false;

                            $status.css({ background: '#fff3cd', borderColor: '#ffc107' })
                                .html('<strong>⚠️ Backup status unclear</strong><br>The backup process stopped but status is unknown. Check logs and archives list below.');
                            $btn.prop('disabled', false).text(originalText);
                            refreshLogs();
                            window.location.reload();
                        }

                        // Update status message with time elapsed
                        if (!response.data.completed && !response.data.failed) {
                            var elapsed = Math.floor(pollCount * 5 / 60);
                            $status.html('<strong>⏳ Backup running...</strong><br>' +
                                elapsed + ' minute(s) elapsed. Please wait...<br>' +
                                '<em style=\"color:#666;\">Logs are updating below.</em>');
                        }
                    }

                    // Stop after max polls (30 minutes)
                    if (pollCount >= maxPolls) {
                        clearInterval(backupStatusInterval);
                        stopStatusChecking();
                        operationInProgress = false;

                        $status.css({ background: '#fff3cd', borderColor: '#ffc107' })
                            .html('<strong>⚠️ Polling timed out</strong><br>The backup may still be running. Refresh the page to check status.');
                        $btn.prop('disabled', false).text(originalText);
                    }
                }
            });
        }, 5000); // Poll every 5 seconds
    }

    // Function to poll restore status. Restore holds WordPress's own
    // maintenance mode for its duration, which blocks admin-ajax.php along
    // with everything else, so this talks to the standalone poll.php
    // endpoint (token-gated, no WordPress bootstrap) instead of the usual
    // wp_ajax_ actions the rest of this file uses.
    var restoreStatusInterval = null;
    function startRestoreStatusPolling($btn, $status, originalText, pollUrl, pollToken) {
        var pollCount = 0;
        var maxPolls = 360; // 30 minutes (5 second intervals)

        restoreStatusInterval = setInterval(function () {
            pollCount++;

            $.ajax({
                url: pollUrl,
                method: 'GET',
                data: {
                    op: 'restore',
                    token: pollToken
                },
                success: function (response) {
                    if (response.data && typeof response.data.recent_logs === 'string') {
                        $('#wpmb-logs').text(response.data.recent_logs);
                    }

                    if (response.success) {
                        if (response.data.completed) {
                            // Restore completed successfully
                            clearInterval(restoreStatusInterval);
                            stopStatusChecking();
                            operationInProgress = false;

                            $status.css({ background: '#d4edda', borderColor: '#28a745' })
                                .html('<strong>✓ ' + response.data.message + '</strong>');
                            $btn.prop('disabled', false).text(originalText);

                            // Refresh page after 2 seconds
                            setTimeout(function () {
                                window.location.reload();
                            }, 2000);
                        } else if (response.data.failed) {
                            // Restore failed
                            clearInterval(restoreStatusInterval);
                            stopStatusChecking();
                            operationInProgress = false;

                            $status.css({ background: '#f8d7da', borderColor: '#dc3545' })
                                .html('<strong>✗ Error:</strong> ' + response.data.message);
                            $btn.prop('disabled', false).text(originalText);
                            refreshLogs();
                        } else if (!response.data.is_locked && pollCount > 5) {
                            // Lock released but no completion message - check logs
                            clearInterval(restoreStatusInterval);
                            stopStatusChecking();
                            operationInProgress = false;

                            $status.css({ background: '#fff3cd', borderColor: '#ffc107' })
                                .html('<strong>⚠️ Restore status unclear</strong><br>The restore process stopped but status is unknown. Check logs below.');
                            $btn.prop('disabled', false).text(originalText);
                            refreshLogs();
                            window.location.reload();
                        }

                        // Update status message with time elapsed
                        if (!response.data.completed && !response.data.failed) {
                            var elapsed = Math.floor(pollCount * 5 / 60);
                            $status.html('<strong>⏳ Restore running...</strong><br>' +
                                elapsed + ' minute(s) elapsed. Please wait...<br>' +
                                '<em style=\"color:#666;\">Logs are updating below.</em>');
                        }
                    }

                    // Stop after max polls (30 minutes)
                    if (pollCount >= maxPolls) {
                        clearInterval(restoreStatusInterval);
                        stopStatusChecking();
                        operationInProgress = false;

                        $status.css({ background: '#fff3cd', borderColor: '#ffc107' })
                            .html('<strong>⚠️ Polling timed out</strong><br>The restore may still be running. Refresh the page to check status.');
                        $btn.prop('disabled', false).text(originalText);
                    }
                }
            });
        }, 5000); // Poll every 5 seconds
    }

    // Function to refresh logs
    function refreshLogs() {
        $.ajax({
            url: wpmbAdmin.ajaxUrl,
            method: 'POST',
            data: {
                action: 'wpmb_get_logs',
                nonce: wpmbAdmin.nonce
            },
            success: function (response) {
                if (response.success) {
                    $('#wpmb-logs').text(response.data.logs);
                }
            }
        });
    }

    // Auto-refresh logs every 5 seconds during operations
    function startStatusChecking() {
        // Auto-refresh logs every 5 seconds
        statusCheckInterval = setInterval(function () {
            refreshLogs();
        }, 5000);
    }

    function stopStatusChecking() {
        if (statusCheckInterval) {
            clearInterval(statusCheckInterval);
            statusCheckInterval = null;
        }
    }

    // Prevent accidental page navigation during operations
    $(window).on('beforeunload', function () {
        if (operationInProgress) {
            return 'An operation is in progress. Leaving this page may interrupt it.';
        }
    });
});
