<?php

/**
 * Standalone Test Script for WP Migrate Lite Logging
 * 
 * Run this from command line: php test-logging.php
 * Or access via browser if placed in WordPress root
 */

// Try to load WordPress if available
$wp_load = dirname(__FILE__) . '/../../../wp-load.php';
if (file_exists($wp_load)) {
    require_once $wp_load;
    echo "✓ WordPress loaded\n";
} else {
    echo "⚠ WordPress not loaded (standalone mode)\n";
    define('WP_CONTENT_DIR', dirname(__FILE__) . '/../../..');
}

// Load plugin files
require_once __DIR__ . '/includes/class-paths.php';
require_once __DIR__ . '/includes/class-log.php';

echo "\n=== WP Migrate Lite Logging Test ===\n\n";

// Test 1: Get log directory
echo "Test 1: Getting log directory...\n";
try {
    $log_dir = WPMB_Log::get_log_dir();
    echo "  Log directory: {$log_dir}\n";

    if (is_dir($log_dir)) {
        echo "  ✓ Directory exists\n";
    } else {
        echo "  ✗ Directory does NOT exist\n";
    }

    if (is_writable($log_dir)) {
        echo "  ✓ Directory is writable\n";
    } else {
        echo "  ✗ Directory is NOT writable\n";
        echo "  Attempting to create...\n";
        @mkdir($log_dir, 0755, true);
        if (is_writable($log_dir)) {
            echo "  ✓ Successfully created and now writable\n";
        }
    }
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n";
}

echo "\n";

// Test 2: Write test log entry
echo "Test 2: Writing test log entry...\n";
WPMB_Log::write('TEST LOG ENTRY', [
    'test_type' => 'standalone_test',
    'timestamp' => time(),
    'date' => gmdate('Y-m-d H:i:s'),
    'message' => 'This is a test to verify logging system is working correctly',
]);
echo "  ✓ Log write command executed\n";

echo "\n";

// Test 3: Check if log file was created
echo "Test 3: Checking log file...\n";
$expected_file = $log_dir . '/wpmb-' . gmdate('Y-m-d') . '.log';
echo "  Expected file: {$expected_file}\n";

if (file_exists($expected_file)) {
    echo "  ✓ Log file exists\n";
    $size = filesize($expected_file);
    echo "  File size: {$size} bytes\n";

    $content = file_get_contents($expected_file);
    echo "\n  Last 500 characters of log file:\n";
    echo "  " . str_repeat('-', 70) . "\n";
    echo "  " . substr($content, -500) . "\n";
    echo "  " . str_repeat('-', 70) . "\n";
} else {
    echo "  ✗ Log file NOT found\n";
    echo "  Checking PHP error log...\n";

    // Check if there are any files in the directory
    $files = @glob($log_dir . '/*');
    if ($files) {
        echo "  Files in log directory:\n";
        foreach ($files as $file) {
            echo "    - " . basename($file) . " (" . filesize($file) . " bytes)\n";
        }
    } else {
        echo "  No files in log directory\n";
    }
}

echo "\n";

// Test 4: Read recent entries
echo "Test 4: Reading recent log entries...\n";
$entries = WPMB_Log::get_recent_entries(5);
if (!empty($entries)) {
    echo "  ✓ Found " . count($entries) . " entries:\n";
    foreach ($entries as $entry) {
        echo "    • " . $entry . "\n";
    }
} else {
    echo "  ✗ No entries retrieved\n";
}

echo "\n";

// Test 5: Check fallback location
echo "Test 5: Checking fallback log location...\n";
$fallback = sys_get_temp_dir() . '/wpmb-' . gmdate('Y-m-d') . '.log';
echo "  Fallback location: {$fallback}\n";
if (file_exists($fallback)) {
    echo "  ⚠ Fallback log exists (main logging may have failed)\n";
    $size = filesize($fallback);
    echo "  File size: {$size} bytes\n";
} else {
    echo "  ✓ No fallback log (good - means main logging worked)\n";
}

echo "\n=== Test Complete ===\n";
echo "\nIf you see errors above, the logging system needs attention.\n";
echo "Otherwise, check your WordPress admin page for logs.\n";
