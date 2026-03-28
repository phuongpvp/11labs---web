<?php
/**
 * Setup 2FA - Generate QR code or disable 2FA
 * POST: { admin_password, action: "enable" | "disable" | "status" }
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

// Auth via header
$adminPassword = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
if (!$adminPassword || !verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Unauthorized'], 403);
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? 'status';

try {
    $db = getDB();

    // Ensure system_config table has 2fa fields
    try {
        $db->exec("INSERT IGNORE INTO system_config (config_key, config_value) VALUES ('2fa_enabled', '0')");
        $db->exec("INSERT IGNORE INTO system_config (config_key, config_value) VALUES ('2fa_secret', '')");
    } catch (Exception $e) { /* ignore */
    }

    switch ($action) {
        case 'status':
            $stmt = $db->prepare("SELECT config_value FROM system_config WHERE config_key = '2fa_enabled'");
            $stmt->execute();
            $enabled = $stmt->fetchColumn();
            jsonResponse(['status' => 'success', 'enabled' => $enabled === '1']);
            break;

        case 'enable':
            // Generate new secret
            $secret = TOTP::generateSecret(16);
            $qrUrl = TOTP::getQRCodeUrl('Admin', $secret, '11labs Admin');

            // Save secret (not enabled yet - user must verify first)
            $db->prepare("UPDATE system_config SET config_value = ? WHERE config_key = '2fa_secret'")->execute([$secret]);

            jsonResponse([
                'status' => 'success',
                'secret' => $secret,
                'qr_url' => $qrUrl,
                'message' => 'Quét mã QR bằng Google Authenticator, sau đó nhập mã 6 số để xác nhận'
            ]);
            break;

        case 'confirm':
            // Verify the code to confirm 2FA setup
            $code = $input['code'] ?? '';
            if (!$code) {
                jsonResponse(['error' => 'Vui lòng nhập mã 6 số'], 400);
            }

            $stmt = $db->prepare("SELECT config_value FROM system_config WHERE config_key = '2fa_secret'");
            $stmt->execute();
            $secret = $stmt->fetchColumn();

            if (!$secret) {
                jsonResponse(['error' => 'Chưa tạo mã QR. Vui lòng bật 2FA trước.'], 400);
            }

            if (TOTP::verifyCode($secret, $code)) {
                $db->prepare("UPDATE system_config SET config_value = '1' WHERE config_key = '2fa_enabled'")->execute();
                jsonResponse(['status' => 'success', 'message' => '✅ 2FA đã được bật thành công!']);
            } else {
                jsonResponse(['error' => 'Mã không đúng. Vui lòng thử lại.'], 400);
            }
            break;

        case 'disable':
            $code = $input['code'] ?? '';

            // Check if 2FA is currently enabled
            $stmt = $db->prepare("SELECT config_value FROM system_config WHERE config_key = '2fa_enabled'");
            $stmt->execute();
            $enabled = $stmt->fetchColumn();

            if ($enabled === '1') {
                // Must verify code to disable
                $stmt = $db->prepare("SELECT config_value FROM system_config WHERE config_key = '2fa_secret'");
                $stmt->execute();
                $secret = $stmt->fetchColumn();

                if (!$code || !TOTP::verifyCode($secret, $code)) {
                    jsonResponse(['error' => 'Cần nhập mã 6 số để tắt 2FA'], 400);
                }
            }

            $db->prepare("UPDATE system_config SET config_value = '0' WHERE config_key = '2fa_enabled'")->execute();
            $db->prepare("UPDATE system_config SET config_value = '' WHERE config_key = '2fa_secret'")->execute();
            jsonResponse(['status' => 'success', 'message' => '2FA đã tắt']);
            break;

        case 'show':
            // Show existing QR/secret for sharing (without resetting)
            $stmt = $db->prepare("SELECT config_value FROM system_config WHERE config_key = '2fa_secret'");
            $stmt->execute();
            $secret = $stmt->fetchColumn();

            if (!$secret) {
                jsonResponse(['error' => '2FA chưa được bật'], 400);
            }

            $qrUrl = TOTP::getQRCodeUrl('Admin', $secret, '11labs Admin');
            jsonResponse([
                'status' => 'success',
                'secret' => $secret,
                'qr_url' => $qrUrl
            ]);
            break;

        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }
} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
