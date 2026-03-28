<?php
require_once __DIR__ . '/../config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$adminPassword = $input['admin_password'] ?? '';
$keyEncrypted = $input['key_encrypted'] ?? '';

if (!$adminPassword || !$keyEncrypted) {
    jsonResponse(['error' => 'Admin password and key required'], 400);
}

// Verify admin
if (!verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Invalid admin password'], 403);
}

try {
    $db = getDB();

    // Decrypt and check credits
    $decryptedKey = decryptKey($keyEncrypted);
    $token = $decryptedKey;

    // Handle Firebase login if needed
    $fbTokens = null;
    if (strpos($decryptedKey, ':') !== false && strpos($decryptedKey, '@') !== false) {
        list($email, $password) = explode(':', $decryptedKey, 2);

        $fbTokens = loginWithFirebase($email, $password, null, $errorMsg, true, true);

        if (!$fbTokens) {
            jsonResponse(['error' => 'Failed to authenticate with Firebase: ' . ($errorMsg ?? 'Invalid credentials or account not registered')], 400);
        }
        $token = $fbTokens['idToken'];
    }

    // Check credits
    $elData = getElevenLabsCredits($token);
    if ($elData === null) {
        jsonResponse(['error' => 'Failed to fetch credits. Invalid key?'], 400);
    }
    $credits = $elData['credits'];
    $resetAt = $elData['reset_at'];

    // Insert into database
    $stmt = $db->prepare("INSERT INTO api_keys (key_encrypted, credits_remaining, fb_token, fb_token_expires, fb_refresh_token, status, last_checked, reset_at) VALUES (?, ?, ?, ?, ?, 'active', NOW(), ?)");
    $stmt->execute([
        $keyEncrypted,
        $credits,
        $fbTokens['idToken'] ?? null,
        $fbTokens['expires'] ?? null,
        $fbTokens['refreshToken'] ?? null,
        $resetAt
    ]);

    $keyId = $db->lastInsertId();

    jsonResponse([
        'status' => 'success',
        'message' => 'API key added successfully',
        'key_id' => $keyId,
        'credits_remaining' => $credits
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
?>