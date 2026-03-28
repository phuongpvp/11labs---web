<?php
require_once __DIR__ . '/../../config.php';

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, x-api-key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

// 1. Verify API Key
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
$user = verifyPartnerApiKey($apiKey);

if (!$user) {
    jsonResponse(['error' => 'Invalid or expired API Key'], 401);
}

// 2. Calculate Quota (Team-aware)
$personalRemaining = max(0, (int) $user['quota_total'] - (int) $user['quota_used']);
$teamRemaining = 0;
$isTeamMember = !empty($user['parent_id']);

if ($isTeamMember) {
    try {
        $db = getDB();
        $stmtParent = $db->prepare("SELECT quota_total, quota_used FROM users WHERE id = ?");
        $stmtParent->execute([$user['parent_id']]);
        $parent = $stmtParent->fetch();

        if ($parent) {
            $parentRemaining = max(0, (int) $parent['quota_total'] - (int) $parent['quota_used']);
            $memberTeamLimitRemaining = max(0, (int) ($user['team_quota_limit'] ?? 0) - (int) ($user['team_quota_used'] ?? 0));
            $teamRemaining = min($memberTeamLimitRemaining, $parentRemaining);
        }
    } catch (Exception $e) { /* fallback to personal only */ }
}

$totalQuota = (int) $user['quota_total'] + ($isTeamMember ? (int) ($user['team_quota_limit'] ?? 0) : 0);
$totalUsed = (int) $user['quota_used'] + ($isTeamMember ? (int) ($user['team_quota_used'] ?? 0) : 0);
$totalRemaining = $personalRemaining + $teamRemaining;

jsonResponse([
    'status' => 'success',
    'id' => $user['id'],
    'email' => $user['email'],
    'quota_total' => $totalQuota,
    'quota_used' => $totalUsed,
    'remaining_quota' => max(0, $totalRemaining),
    'expires_at' => $user['expires_at']
]);
