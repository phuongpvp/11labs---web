<?php
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$token = $input['token'] ?? '';

if (!$token) {
    jsonResponse(['error' => 'Token required'], 400);
}

$tokenData = verifyToken($token);
if (!$tokenData) {
    jsonResponse(['error' => 'Invalid token'], 401);
}

$userId = $tokenData['user_id'];

try {
    $db = getDB();

    // Get fresh user data
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['error' => 'User not found'], 404);
    }

    // Check expiry
    if (strtotime($user['expires_at']) < time()) {
        $stmt = $db->prepare("UPDATE users SET status = 'expired' WHERE id = ?");
        $stmt->execute([$user['id']]);
        $user['status'] = 'expired';
    }

    $personalRemaining = max(0, $user['quota_total'] - $user['quota_used']);
    $teamRemaining = 0;
    $expiresAt = $user['expires_at'];

    if (!empty($user['parent_id'])) {
        $stmtP = $db->prepare("SELECT plan, quota_total, quota_used, expires_at FROM users WHERE id = ?");
        $stmtP->execute([$user['parent_id']]);
        $parent = $stmtP->fetch();
        if ($parent) {
            $parentRem = max(0, $parent['quota_total'] - $parent['quota_used']);
            $memberLimRem = max(0, $user['team_quota_limit'] - $user['team_quota_used']);
            $teamRemaining = min($parentRem, $memberLimRem);
            $expiresAt = $parent['expires_at']; // Correctly sync expiry to leader

            // INHERIT PLAN FROM PARENT
            $user['plan'] = $parent['plan'];
        }
    }
    $totalRemaining = $personalRemaining + $teamRemaining;

    // Get User Features
    $packages = getSubscriptionPackages();
    $features = $packages[$user['plan']]['features'] ?? [];

    $tutorialLink = getSystemSetting('tutorial_link', '');

    // Check for payment celebration flag (one-time popup)
    $celebration = false;
    try {
        $celebrationFlag = (int)($user['payment_celebration'] ?? 0);
        if ($celebrationFlag === 1) {
            $celebration = true;
            // Clear flag immediately (one-time show)
            $db->prepare("UPDATE users SET payment_celebration = 0 WHERE id = ?")->execute([$userId]);
        }
    } catch (Exception $e) { /* column may not exist yet */ }

    jsonResponse([
        'status' => 'success',
        'tutorial_link' => $tutorialLink,
        'payment_celebration' => $celebration,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'plan' => $user['plan'],
            'custom_plan_name' => $user['custom_plan_name'] ?? null,
            'features' => $features,
            'quota_total' => $user['quota_total'] + ((isset($user['parent_id']) && (int) $user['parent_id'] > 0) ? $user['team_quota_limit'] : 0),
            'quota_used' => $user['quota_used'] + ((isset($user['parent_id']) && (int) $user['parent_id'] > 0) ? $user['team_quota_used'] : 0),
            'quota_remaining' => $totalRemaining,
            'expires_at' => $expiresAt,
            'status' => $user['status'],
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
?>