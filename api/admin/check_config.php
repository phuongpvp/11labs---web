<?php
require_once __DIR__ . '/../config.php';
header('Content-Type: application/json');

try {
    $db = getDB();

    // Check if table exists
    $stmt = $db->query("SHOW TABLES LIKE 'system_config'");
    $tableExists = $stmt->rowCount() > 0;

    if (!$tableExists) {
        // Create it if missing
        $db->exec("CREATE TABLE IF NOT EXISTS system_config (
            config_key VARCHAR(50) PRIMARY KEY,
            config_value TEXT
        )");
        echo json_encode(['status' => 'created_table', 'data' => []]);
        exit;
    }

    $stmt = $db->query("SELECT * FROM system_config");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'error' => $e->getMessage()]);
}
?>