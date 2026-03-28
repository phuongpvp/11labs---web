<?php
require_once __DIR__ . '/../config.php';

// Check Admin Auth
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$adminPass = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';

if (!verifyAdminPassword($adminPass)) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

// Handle POST actions (delete/cleanup workers)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $db = getDB();

    if ($action === 'delete_worker') {
        $uuid = $input['worker_uuid'] ?? '';
        if (!$uuid) jsonResponse(['error' => 'UUID required'], 400);

        $db->prepare("DELETE FROM workers WHERE worker_uuid = ?")->execute([$uuid]);

        // Release ngrok token
        try {
            $db->prepare("UPDATE ngrok_keys SET worker_uuid = NULL, worker_ip = NULL, assigned_at = NULL WHERE worker_uuid = ?")->execute([$uuid]);
        } catch (Exception $e) { /* ignore */ }

        jsonResponse(['success' => true, 'message' => 'Đã xóa worker']);
    }

    if ($action === 'cleanup_workers') {
        $stmt = $db->prepare("DELETE FROM workers WHERE last_seen < (NOW() - INTERVAL 300 SECOND)");
        $stmt->execute();
        $deleted = $stmt->rowCount();
        jsonResponse(['success' => true, 'message' => "Đã dọn $deleted worker offline"]);
    }

    jsonResponse(['error' => 'Invalid action'], 400);
}

try {
    $db = getDB();

    // 1. Get Workers
    $workersStmt = $db->query("
        SELECT w.id, w.worker_uuid, w.worker_name, w.url, w.ip_address, w.status, w.last_seen, w.connected_at, w.failed_jobs,
               COUNT(CASE WHEN j.status = 'processing' AND j.created_at > (NOW() - INTERVAL 1 HOUR) THEN 1 END) as active_jobs,
               COUNT(CASE WHEN j.status = 'failed' AND j.created_at > (NOW() - INTERVAL 1 HOUR) THEN 1 END) as recent_failed,
               (SELECT COUNT(*) FROM conversion_jobs cj WHERE cj.worker_uuid = w.worker_uuid AND cj.status = 'completed' AND cj.updated_at >= w.connected_at) as jobs_completed,
               (SELECT COALESCE(SUM(LENGTH(cj2.full_text)), 0) FROM conversion_jobs cj2 WHERE cj2.worker_uuid = w.worker_uuid AND cj2.status = 'completed' AND cj2.updated_at >= w.connected_at) as chars_used
        FROM workers w
        LEFT JOIN conversion_jobs j ON j.worker_uuid = w.worker_uuid
        GROUP BY w.id
        ORDER BY CAST(REGEXP_REPLACE(w.worker_name, '[^0-9]', '') AS UNSIGNED), w.worker_name ASC
    ");
    $workers = $workersStmt->fetchAll();

    // 2. Get API Keys
    $keysStmt = $db->query("SELECT id, key_encrypted, credits_remaining, status, last_checked, created_at, reset_at, assigned_worker_uuid FROM api_keys ORDER BY credits_remaining DESC");
    $keysRaw = $keysStmt->fetchAll();

    $keys = [];
    $totalCredits = 0;
    foreach ($keysRaw as $k) {
        $realKey = decryptKey($k['key_encrypted']);
        $maskedKey = substr($realKey, 0, 4) . '...' . substr($realKey, -4);

        $keys[] = [
            'id' => $k['id'],
            'masked_key' => $maskedKey,
            'credits_remaining' => (int) $k['credits_remaining'],
            'status' => $k['status'],
            'assigned_worker_uuid' => $k['assigned_worker_uuid'],
            'last_checked' => $k['last_checked'],
            'created_at' => $k['created_at'],
            'reset_at' => $k['reset_at']
        ];

        if ($k['status'] === 'active') {
            $totalCredits += (int) $k['credits_remaining'];
        }
    }

    // 3. System Config (for alert threshold reference)
    $configStmt = $db->query("SELECT config_key, config_value FROM system_config WHERE config_key IN ('telegram_enabled', 'last_credit_alert_sent')");
    $config = [];
    while ($row = $configStmt->fetch()) {
        $config[$row['config_key']] = $row['config_value'];
    }

    jsonResponse([
        'status' => 'success',
        'data' => [
            'workers' => $workers,
            'api_keys' => $keys,
            'summary' => [
                'total_active_credits' => $totalCredits,
                'worker_count' => count($workers),
                'online_workers' => count(array_filter($workers, fn($w) => $w['status'] === 'active' && strtotime($w['last_seen']) > (time() - 180))),
                'config' => $config
            ]
        ]
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
