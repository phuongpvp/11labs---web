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
        $stmt = $db->prepare("DELETE FROM stt_jobs WHERE user_id = ? AND status IN ('completed', 'failed')");
        $stmt->execute([$userId]);
        jsonResponse(['status' => 'success', 'deleted' => $stmt->rowCount()]);
    }

    $stmt = $db->prepare("SELECT id, original_filename, duration_seconds, result_text, result_srt, language_code, points_used, status, error_message, created_at FROM stt_jobs WHERE user_id = ? ORDER BY created_at DESC LIMIT 50");
    $stmt->execute([$userId]);
    $jobs = $stmt->fetchAll();

    jsonResponse([
        'status' => 'success',
        'jobs' => $jobs
    ]);
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
