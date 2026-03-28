<?php
require_once __DIR__ . '/config.php';
header('Content-Type: application/json');

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

$data = json_decode(file_get_contents('php://input'), true);
$token = $data['token'] ?? '';

if (!$token) {
    jsonResponse(['error' => 'Missing token'], 400);
}

try {
    $db = getDB();

    // Verify token
    $tokenData = verifyToken($token);
    if (!$tokenData) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $userId = $tokenData['user_id'];

    // Delete jobs for this user from the last 24 hours
    $stmt = $db->prepare("DELETE FROM conversion_jobs WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 DAY)");
    $stmt->execute([$userId]);
    $deletedCount = $stmt->rowCount();

    logToFile('admin_actions.log', "USER_ACTION: User $userId deleted all jobs ($deletedCount tasks).");

    jsonResponse([
        'status' => 'success',
        'message' => "Đã xóa $deletedCount tác vụ thành công.",
        'deleted_count' => $deletedCount
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
