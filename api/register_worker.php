<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/telegram.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$secret = $input['secret'] ?? '';
$url = $input['url'] ?? '';
$workerUuid = $input['worker_uuid'] ?? '';
$workerName = $input['worker_name'] ?? '';
// IP: ưu tiên IP worker tự báo cáo, fallback về IP request
$workerIp = $input['worker_ip'] ?? ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '');

// Simple secret verification
if (!verifyWorkerSecret($secret)) {
    jsonResponse(['error' => 'Invalid secret'], 403);
}

if (!$url || !$workerUuid) {
    jsonResponse(['error' => 'URL and Worker UUID required'], 400);
}

try {
    $db = getDB();

    // Register or Update worker (Upsert)
    // V13.1 Update: Cleanup old UUIDs using the same URL and init last_assigned
    $db->beginTransaction();

    // 1. Remove any other active workers with this same URL to avoid duplicates in the pool
    $stmtCleanup = $db->prepare("DELETE FROM workers WHERE url = ? AND worker_uuid != ?");
    $stmtCleanup->execute([$url, $workerUuid]);

    // 2. Insert/Update worker
    // Using a very old date for last_assigned on new workers to make them priority
    $stmt = $db->prepare("
        INSERT INTO workers (worker_uuid, worker_name, url, ip_address, status, last_seen, last_assigned, connected_at, failed_jobs)
        VALUES (?, ?, ?, ?, 'active', CURRENT_TIMESTAMP, '1970-01-01 00:00:00', CURRENT_TIMESTAMP, 0)
        ON DUPLICATE KEY UPDATE
            worker_name = VALUES(worker_name),
            url = VALUES(url),
            ip_address = VALUES(ip_address),
            status = 'active',
            last_seen = CURRENT_TIMESTAMP,
            failed_jobs = 0
    ");
    $stmt->execute([$workerUuid, $workerName, $url, $workerIp]);

    $db->commit();

    // Check for other offline workers and notify
    checkOfflineWorkers();

    jsonResponse([
        'status' => 'success',
        'message' => 'Worker registered/updated',
        'worker_uuid' => $workerUuid
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}