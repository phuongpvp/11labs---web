<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$token = $input['token'] ?? '';
$password = $input['password'] ?? '';

if (!$token || !$password) {
    jsonResponse(['error' => 'Vui lòng nhập đầy đủ thông tin'], 400);
}

if (strlen($password) < 6) {
    jsonResponse(['error' => 'Mật khẩu phải có ít nhất 6 ký tự'], 400);
}

try {
    $db = getDB();

    // Find user by valid token
    $stmt = $db->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW()");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['error' => 'Liên kết đã hết hạn hoặc không hợp lệ. Vui lòng yêu cầu lại.'], 400);
    }

    // Update password
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE users SET password_hash = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?");
    $stmt->execute([$hash, $user['id']]);

    jsonResponse(['status' => 'success', 'message' => 'Cập nhật mật khẩu thành công']);

} catch (Exception $e) {
    jsonResponse(['error' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
}
?>