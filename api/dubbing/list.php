<?php
/**
 * Dubbing - List job history for user
 */
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
$token = str_replace('Bearer ', '', $auth);
$userData = verifyToken($token);

if (!$userData) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$userId = $userData['user_id'];

try {
    $db = getDB();

    // Handle DELETE: clear all history
    if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
        $stmt = $db->prepare("DELETE FROM dubbing_jobs WHERE user_id = ? AND status IN ('dubbed', 'failed')");
        $stmt->execute([$userId]);
        jsonResponse(['status' => 'success', 'deleted' => $stmt->rowCount()]);
    }

    // Ensure progress columns exist
    try {
        $db->exec("ALTER TABLE dubbing_jobs ADD COLUMN progress INT DEFAULT 0");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE dubbing_jobs ADD COLUMN progress_message VARCHAR(255) DEFAULT ''");
    } catch (Exception $e) {
    }

    $stmt = $db->prepare("SELECT id, name, source_lang, target_lang, original_filename, result_file, points_used, estimated_duration_sec, status, error_message, progress, progress_message, created_at FROM dubbing_jobs WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$userId]);
    $jobs = $stmt->fetchAll();

    // Add result URLs
    foreach ($jobs as &$job) {
        if ($job['status'] === 'dubbed' && $job['result_file']) {
            $job['result_url'] = PHP_BACKEND_URL . '/api/results/dubbing/' . $job['result_file'];
        }
    }

    jsonResponse([
        'status' => 'success',
        'jobs' => $jobs
    ]);
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
