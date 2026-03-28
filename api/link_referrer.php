<?php
require_once __DIR__ . '/config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$authHeader = getAuthorizationHeader();
$token = $authHeader ? str_replace('Bearer ', '', $authHeader) : '';
$user = verifyToken($token);

if (!$user) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);
$referralCode = $input['referral_code'] ?? '';

if (!$referralCode) {
    jsonResponse(['error' => 'No referral code'], 400);
}

try {
    $db = getDB();
    $userId = $user['user_id'];

    // Check if user already has a referrer
    $stmt = $db->prepare("SELECT referrer_id FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $currentReferrer = $stmt->fetchColumn();

    if ($currentReferrer) {
        // Already has referrer, skip
        jsonResponse(['status' => 'ok', 'message' => 'Already linked']);
    }

    // Lookup referrer by code
    $stmt = $db->prepare("SELECT id FROM users WHERE referral_code = ?");
    $stmt->execute([$referralCode]);
    $referrerId = $stmt->fetchColumn();

    if (!$referrerId || $referrerId == $userId) {
        // Invalid code or self-referral
        jsonResponse(['status' => 'ok', 'message' => 'Invalid referral']);
    }

    // Link referrer
    $stmt = $db->prepare("UPDATE users SET referrer_id = ? WHERE id = ? AND referrer_id IS NULL");
    $stmt->execute([$referrerId, $userId]);

    // Also ensure this user has a referral code
    $stmt = $db->prepare("SELECT referral_code FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    if (!$stmt->fetchColumn()) {
        $code = 'U' . $userId . rand(100, 999);
        $db->prepare("UPDATE users SET referral_code = ? WHERE id = ?")->execute([$code, $userId]);
    }

    jsonResponse(['status' => 'success', 'message' => 'Referrer linked']);

} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
?>