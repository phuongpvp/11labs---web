<?php
require_once '../config.php';
header('Content-Type: application/json');

// 1. Verify user token
$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
$token = str_replace('Bearer ', '', $auth);
$userData = verifyToken($token);

if (!$userData) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$user_id = $userData['user_id'];

try {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM music_jobs WHERE user_id = ?");
    $stmt->execute([$user_id]);

    jsonResponse(['status' => 'success', 'message' => 'Lịch sử đã được xóa.']);
} catch (PDOException $e) {
    jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
}