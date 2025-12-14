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

        $btn.prop('disabled', true).text('Restoring...');
        $status.show().html('<strong>' + wpmbAdmin.strings.restoreInProgress + '</strong><br>Creating safety backup, importing database, replacing URLs, and restoring files...<br><em style="color:#666;">Do not close this page or click the button again.</em>');
        operationInProgress = true;

        // Start status checking
        startStatusChecking();

        var ajaxRequest = $.ajax({
            url: wpmbAdmin.ajaxUrl,
            method: 'POST',
            data: {
                action: 'wpmb_restore_backup_ajax',
                nonce: wpmbAdmin.nonce,
                archive_id: archiveId,
                archive_path: archivePath
            },
            timeout: 1800000, // 30 minutes for large sites
            success: function (response) {
                stopStatusChecking();
                operationInProgress = false;

                if (response.success) {
                    $status.css({ background: '#d4edda', borderColor: '#28a745' })
                        .html('<strong>✓ ' + response.data.message + '</strong>');

                    // Refresh page after 3 seconds
                    setTimeout(function () {
                        window.location.reload();
                    }, 3000);
                } else {
                    $status.css({ background: '#f8d7da', borderColor: '#dc3545' })
                        .html('<strong>✗ Error:</strong> ' + response.data.message);
                    $btn.prop('disabled', false).text(originalText);
                }

                // Auto-refresh logs
                refreshLogs();
            },
            error: function (xhr, status, error) {
                stopStatusChecking();

                // Check if restore actually completed despite timeout
                if (status === 'timeout') {
                    $status.css({ background: '#fff3cd', borderColor: '#ffc107' })
                        .html('<strong>⚠️ Request timed out</strong><br>' +
                            'The restore may still be running in the background. ' +
                            'Please wait 2-3 minutes and refresh the page to check if it completed.<br>' +
                            '<button type="button" class="button button-small" onclick="window.location.reload();" style="margin-top:10px;">Refresh Page Now</button>');

                    // Don't reset operationInProgress immediately
                    setTimeout(function () {
                        operationInProgress = false;
                        $btn.prop('disabled', false).text(originalText);
                    }, 10000); // 10 seconds
                } else {
                    operationInProgress = false;

                    // Try to get detailed error message
                    var errorMsg = 'Request failed.';
                    if (xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMsg = xhr.responseJSON.data.message;
                    } else if (xhr.responseText) {
                        errorMsg = 'Server error. Check logs for details.';
                    }

                    $status.css({ background: '#f8d7da', borderColor: '#dc3545' })
                        .html('<strong>✗ Error:</strong> ' + errorMsg + '<br>Status: ' + status + '<br>Check the logs below for more details.');
                    $btn.prop('disabled', false).text(originalText);
                }

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
