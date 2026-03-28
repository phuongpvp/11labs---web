<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$authHeader = getAuthorizationHeader();
$token = $authHeader ? str_replace('Bearer ', '', $authHeader) : '';

$tokenData = verifyToken($token);
if (!$tokenData) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$userId = $tokenData['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$oldPassword = $input['old_password'] ?? '';
$newPassword = $input['new_password'] ?? '';

if (!$oldPassword || !$newPassword) {
    jsonResponse(['error' => 'Vui lòng điền đầy đủ thông tin'], 400);
}

if (strlen($newPassword) < 6) {
    jsonResponse(['error' => 'Mật khẩu mới phải có ít nhất 6 ký tự'], 400);
}

try {
    $db = getDB();

    // Verify old password
    $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($oldPassword, $user['password_hash'])) {
        jsonResponse(['error' => 'Mật khẩu cũ không chính xác'], 400);
    }

    // Update password
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
    $stmt->execute([$newHash, $userId]);

    jsonResponse(['status' => 'success', 'message' => 'Đổi mật khẩu thành công']);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
?>