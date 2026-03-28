<?php
require_once __DIR__ . '/../config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Get admin password from header
$adminPassword = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';

if (!$adminPassword) {
    jsonResponse(['error' => 'Admin password required'], 400);
}

// Verify admin
if (!verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Invalid admin password'], 403);
}

try {
    $response = [
        'status' => 'success',
        'error_logs' => [],
        'failed_logs' => [],
        'admin_actions_logs' => []
    ];

    // Get error logs (latest file)
    $errorLogs = glob(__DIR__ . '/../logs/error_*.log');
    if ($errorLogs) {
        $latest = end($errorLogs);
        $response['error_log_file'] = basename($latest);

        if (file_exists($latest)) {
            $lines = array_reverse(file($latest, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
            $response['error_logs'] = array_slice($lines, 0, 100);
        }
    }

    // Get failed jobs log
    $failedLog = __DIR__ . '/../logs/failed_jobs.log';
    if (file_exists($failedLog)) {
        $lines = array_reverse(file($failedLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $response['failed_logs'] = array_slice($lines, 0, 50);
    }

    // Get admin actions log
    $adminActionsLog = __DIR__ . '/../logs/admin_actions.log';
    if (file_exists($adminActionsLog)) {
        $lines = array_reverse(file($adminActionsLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $response['admin_actions_logs'] = array_slice($lines, 0, 100);
    }

    // Get IP block analysis log
    $ipBlockLog = __DIR__ . '/../logs/ip_block_analysis.log';
    if (file_exists($ipBlockLog)) {
        $lines = array_reverse(file($ipBlockLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
        $response['ip_block_logs'] = array_slice($lines, 0, 50);
    } else {
        $response['ip_block_logs'] = [];
    }

    jsonResponse($response);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
