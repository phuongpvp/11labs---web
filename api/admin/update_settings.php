<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$adminPassword = $input['admin_password'] ?? '';
$settings = $input['settings'] ?? [];

if (!verifyAdminPassword($adminPassword)) {
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit;
}

if (empty($settings)) {
    echo json_encode(['status' => 'error', 'error' => 'No settings provided']);
    exit;
}

try {
    $db = getDB();

    // 1. Ensure table exists (Fix for "Lost after save" issue)
    $db->exec("CREATE TABLE IF NOT EXISTS system_config (
        config_key VARCHAR(50) PRIMARY KEY,
        config_value TEXT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    foreach ($settings as $key => $value) {
        // USE UPSERT (ON DUPLICATE KEY UPDATE) for maximum safety
        $stmt = $db->prepare("INSERT INTO system_config (config_key, config_value) 
                             VALUES (?, ?) 
                             ON DUPLICATE KEY UPDATE config_value = VALUES(config_value)");
        $result = $stmt->execute([$key, $value]);

        // Debug logging
        if (!$result) {
            error_log("SETTINGS SAVE FAILED: key=$key, value=$value");
        }
    }

    echo json_encode(['status' => 'success', 'message' => 'Đã lưu cấu hình thành công!']);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
?>