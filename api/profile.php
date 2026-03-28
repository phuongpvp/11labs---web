<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$authHeader = getAuthorizationHeader();
$token = $authHeader ? str_replace('Bearer ', '', $authHeader) : '';

// Fallback to GET token if Header is missing (Common server issue)
if (!$token && isset($_GET['token'])) {
    $token = $_GET['token'];
}

$tokenData = verifyToken($token);
if (!$tokenData) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$userId = $tokenData['user_id'];

// POST: Generate/Regenerate API Key
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'generate_api_key') {
        try {
            $db = getDB();
            // Check if user already has a partner key — don't overwrite
            $stmt = $db->prepare("SELECT partner_api_key, COALESCE(key_type, '') as key_type FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $existing = $stmt->fetch();
            if ($existing && $existing['partner_api_key'] && $existing['key_type'] === 'partner') {
                jsonResponse(['error' => 'Bạn đang có API key loại đại lý. Không thể tạo key mới từ đây.'], 403);
            }

            // Generate key in same format: PL_ + 20 random uppercase chars
            $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
            $randomPart = '';
            for ($i = 0; $i < 20; $i++) {
                $randomPart .= $chars[random_int(0, strlen($chars) - 1)];
            }
            $newKey = 'PL_' . $randomPart;
            $stmt = $db->prepare("UPDATE users SET partner_api_key = ?, key_type = 'developer' WHERE id = ?");
            $stmt->execute([$newKey, $userId]);
            jsonResponse(['status' => 'success', 'api_key' => $newKey]);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Không thể tạo API key'], 500);
        }
    } elseif ($action === 'revoke_api_key') {
        try {
            $db = getDB();
            $stmt = $db->prepare("UPDATE users SET partner_api_key = NULL, key_type = NULL WHERE id = ?");
            $stmt->execute([$userId]);
            jsonResponse(['status' => 'success']);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Không thể hủy API key'], 500);
        }
    }

    jsonResponse(['error' => 'Invalid action'], 400);
}

try {
    $db = getDB();

    // Get User Details
    // Get User Details (Inherit Plan from Parent if exists)
    $stmt = $db->prepare("
        SELECT u.email, u.quota_used, u.quota_total, u.expires_at, u.status, u.custom_plan_name,
               u.partner_api_key, COALESCE(u.key_type, '') as key_type,
               COALESCE(p_parent.plan, u.plan) as effective_plan, 
               pkg.name as plan_name
        FROM users u
        LEFT JOIN users p_parent ON u.parent_id = p_parent.id
        LEFT JOIN packages pkg ON COALESCE(p_parent.plan, u.plan) = pkg.id
        WHERE u.id = ?
    ");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['error' => 'User not found'], 404);
    }

    // Get Recent Jobs (Last 24h Source of Truth)
    $stmt = $db->prepare("
        SELECT created_at, CHAR_LENGTH(full_text) as characters_used, 
               SUBSTRING(full_text, 1, 100) as text_preview, status
        FROM conversion_jobs 
        WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 DAY)
        ORDER BY created_at DESC 
        LIMIT 50
    ");
    $stmt->execute([$userId]);
    $logs = $stmt->fetchAll();

    jsonResponse([
        'status' => 'success',
        'user' => [
            'email' => $user['email'],
            'plan_name' => $user['custom_plan_name'] ?: ($user['plan_name'] ?? ucfirst($user['effective_plan'])),
            'quota_used' => $user['quota_used'],
            'quota_total' => $user['quota_total'],
            'expires_at' => $user['expires_at'],
            'status' => $user['status'],
            'api_key' => $user['partner_api_key'] ?: null,
            'key_type' => $user['key_type'] ?: null
        ],
        'logs' => $logs
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
?>