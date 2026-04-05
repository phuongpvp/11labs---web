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
    $stmt = $db->prepare("DELETE FROM voice_changer_jobs WHERE user_id = ?");
    $stmt->execute([$userId]);

    jsonResponse([
        'status' => 'success',
        'message' => 'Cleared ' . $stmt->rowCount() . ' jobs'
    ]);
} catch (Exception $e) {
    jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
}
