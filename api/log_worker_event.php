<?php
require_once __DIR__ . '/config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$secret = $input['secret'] ?? '';
$workerUuid = $input['worker_uuid'] ?? '';
$workerName = $input['worker_name'] ?? 'Unknown Worker';
$jobId = $input['job_id'] ?? null;
$message = $input['message'] ?? '';
$level = $input['level'] ?? 'info';

// Simple secret verification
if (!verifyWorkerSecret($secret)) {
    jsonResponse(['error' => 'Invalid secret'], 403);
}

if (!$message) {
    jsonResponse(['error' => 'Message required'], 400);
}

try {
    $db = getDB();

    // 1. Create table if not exists
    $db->exec("CREATE TABLE IF NOT EXISTS worker_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        worker_uuid VARCHAR(64),
        worker_name VARCHAR(255),
        job_id VARCHAR(64) NULL,
        message TEXT,
        level VARCHAR(20) DEFAULT 'info',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // 2. Insert log
    $stmt = $db->prepare("INSERT INTO worker_logs (worker_uuid, worker_name, job_id, message, level) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$workerUuid, $workerName, $jobId, $message, $level]);

    // 3. Prune old logs (Keep last 500)
    $db->exec("DELETE FROM worker_logs WHERE id <= (SELECT id FROM (SELECT id FROM worker_logs ORDER BY id DESC LIMIT 1 OFFSET 500) as t)");

    jsonResponse(['status' => 'success']);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
