<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$adminPassword = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
if (!verifyAdminPassword($adminPassword)) {
    die(json_encode(['error' => 'Unauthorized']));
}

try {
    $db = getDB();
    // 1. Add retry_count column if not exists
    try {
        $db->exec("ALTER TABLE conversion_jobs ADD COLUMN retry_count INT DEFAULT 0 AFTER api_key_ids");
        $results['table_update'] = "Added retry_count column.";
    } catch (Exception $e) {
        $results['table_update'] = "retry_count column already exists or error: " . $e->getMessage();
    }

    // 2. Add last_error column if not exists (to store failure reason)
    try {
        $db->exec("ALTER TABLE conversion_jobs ADD COLUMN last_error TEXT AFTER retry_count");
        $results['last_error_update'] = "Added last_error column.";
    } catch (Exception $e) {
        $results['last_error_update'] = "last_error column already exists or error: " . $e->getMessage();
    }

    echo json_encode(['status' => 'success', 'results' => $results], JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>