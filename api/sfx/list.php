<?php
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
        $stmt = $db->prepare("DELETE FROM sfx_jobs WHERE user_id = ? AND status IN ('completed', 'failed')");
        $stmt->execute([$userId]);
        jsonResponse(['status' => 'success', 'deleted' => $stmt->rowCount()]);
    }

    $stmt = $db->prepare("SELECT id, prompt, duration, is_loop, prompt_influence, result_file, points_used, status, error_message, created_at FROM sfx_jobs WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$userId]);
    $jobs = $stmt->fetchAll();

    // Add result URLs
    foreach ($jobs as &$job) {
        if ($job['status'] === 'completed' && $job['result_file']) {
            $job['result_url'] = PHP_BACKEND_URL . '/api/results/sfx/' . $job['result_file'];
        }
    }

    jsonResponse([
        'status' => 'success',
        'jobs' => $jobs
    ]);
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
