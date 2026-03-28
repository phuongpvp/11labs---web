<?php
/**
 * Verify 2FA code during login
 * POST: { admin_password, code: "123456" }
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/totp.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$adminPassword = $input['admin_password'] ?? '';
$code = $input['code'] ?? '';

// 1. Verify admin password first
if (!$adminPassword || !verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Sai mật khẩu'], 403);
}

if (!$code) {
    jsonResponse(['error' => 'Vui lòng nhập mã 6 số'], 400);
}

try {
    $db = getDB();

    // 2. Check if 2FA is enabled
    $stmt = $db->prepare("SELECT config_value FROM system_config WHERE config_key = '2fa_enabled'");
    $stmt->execute();
    $enabled = $stmt->fetchColumn();

    if ($enabled !== '1') {
        // 2FA not enabled, password alone is enough
        jsonResponse(['status' => 'success', 'message' => '2FA chưa bật, đăng nhập thành công']);
    }

    // 3. Get secret and verify code
    $stmt = $db->prepare("SELECT config_value FROM system_config WHERE config_key = '2fa_secret'");
    $stmt->execute();
    $secret = $stmt->fetchColumn();

    if (!$secret) {
        jsonResponse(['error' => '2FA secret not found. Please reconfigure 2FA.'], 500);
    }

    if (TOTP::verifyCode($secret, $code)) {
        jsonResponse(['status' => 'success', 'message' => 'Xác thực thành công']);
    } else {
        jsonResponse(['error' => 'Mã không đúng hoặc đã hết hạn. Vui lòng thử lại.'], 401);
    }

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
