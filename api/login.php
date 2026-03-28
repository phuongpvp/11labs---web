<?php
require_once __DIR__ . '/config.php';

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

// Only accept POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';
$password = $input['password'] ?? '';

if (!$email || !$password) {
    jsonResponse(['error' => 'Email and password required'], 400);
}

try {
    $db = getDB();

    // Find user
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['error' => 'Invalid credentials'], 401);
    }

    if ($user['status'] === 'inactive') {
        jsonResponse(['error' => 'Tài khoản chưa được kích hoạt. Vui lòng liên hệ Admin.'], 403);
    }

    if ($user['status'] !== 'active' && $user['status'] !== 'expired') { // Allow expired to login to see status? No, let's keep it strict or handle expired below
        // Actually existing code handled 'active' in query. Let's stick to strict check.
    }

    // Verify password
    if (!password_verify($password, $user['password_hash'])) {
        jsonResponse(['error' => 'Invalid credentials'], 401);
    }

    // Check expiry
    if (strtotime($user['expires_at']) < time()) {
        // Update status to expired
        $stmt = $db->prepare("UPDATE users SET status = 'expired' WHERE id = ?");
        $stmt->execute([$user['id']]);
        $user['status'] = 'expired'; // Cập nhật local để lát trả về đúng
    }

    // Generate token
    $token = generateToken($user['id'], $user['email']);

    $personalRemaining = max(0, $user['quota_total'] - $user['quota_used']);
    $teamRemaining = 0;
    $expiresAt = $user['expires_at'];

    if (!empty($user['parent_id'])) {
        $stmtP = $db->prepare("SELECT quota_total, quota_used, expires_at FROM users WHERE id = ?");
        $stmtP->execute([$user['parent_id']]);
        $parent = $stmtP->fetch();
        if ($parent) {
            $parentRem = max(0, $parent['quota_total'] - $parent['quota_used']);
            $memberLimRem = max(0, $user['team_quota_limit'] - $user['team_quota_used']);
            $teamRemaining = min($parentRem, $memberLimRem);
            $expiresAt = $parent['expires_at']; // Correctly sync expiry to leader
        }
    }
    $totalRemaining = $personalRemaining + $teamRemaining;

    // Get User Features
    $packages = getSubscriptionPackages();
    $features = $packages[$user['plan']]['features'] ?? [];

    // Return user data
    jsonResponse([
        'status' => 'success',
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'plan' => $user['plan'],
            'features' => $features,
            'quota_total' => $user['quota_total'] + ((isset($user['parent_id']) && (int) $user['parent_id'] > 0) ? $user['team_quota_limit'] : 0),
            'quota_used' => $user['quota_used'] + ((isset($user['parent_id']) && (int) $user['parent_id'] > 0) ? $user['team_quota_used'] : 0),
            'quota_remaining' => $totalRemaining,
            'expires_at' => $expiresAt,
            'parent_id' => $user['parent_id'],
            'team_quota_limit' => $user['team_quota_limit'],
            'team_quota_used' => $user['team_quota_used'],
            'personal_remaining' => $personalRemaining,
            'team_remaining' => $teamRemaining
        ]
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error'], 500);
}
