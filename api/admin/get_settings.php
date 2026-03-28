<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Get admin password from header
$adminPassword = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';

if (!$adminPassword) {
    echo json_encode(['error' => 'Admin password required']);
    exit;
}

if (!verifyAdminPassword($adminPassword)) {
    echo json_encode(['error' => 'Invalid admin password']);
    exit;
}

try {
    $db = getDB();
    $stmt = $db->query("SELECT config_key, config_value FROM system_config");
    $settings = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $settings[$row['config_key']] = $row['config_value'];
    }

    echo json_encode(['status' => 'success', 'settings' => $settings]);

} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
?>