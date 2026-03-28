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
$keysText = $input['keys_text'] ?? ''; // Nhiều keys, mỗi key 1 dòng

if (!$adminPassword || !$keysText) {
    jsonResponse(['error' => 'Admin password and keys required'], 400);
}

// Verify admin
if (!verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Invalid admin password'], 403);
}

try {
    $db = getDB();

    // Split keys by newline
    $keys = array_filter(array_map('trim', explode("\n", $keysText)));

    $results = [
        'total' => count($keys),
        'success' => 0,
        'failed' => 0,
        'details' => []
    ];

    foreach ($keys as $keyEncrypted) {
        try {
            // Decrypt and check credits
            $decryptedKey = decryptKey($keyEncrypted);
            $token = $decryptedKey;

            // Handle Firebase login if needed
            $fbTokens = null;
            if (strpos($decryptedKey, ':') !== false && strpos($decryptedKey, '@') !== false) {
                list($email, $password) = explode(':', $decryptedKey, 2);
                $fbError = "";
                // Use worker routing to bypass Firebase quota (distribute across Colab IPs)
                $fbTokens = loginWithFirebase($email, $password, null, $fbError, true, true);
                if (!$fbTokens) {
                    $results['failed']++;
                    $results['details'][] = [
                        'key' => substr($keyEncrypted, 0, 30) . '...',
                        'full_key' => $keyEncrypted,
                        'status' => 'failed',
                        'error' => 'Firebase login failed: ' . $fbError
                    ];
                    continue;
                }
                $token = $fbTokens['idToken'];
            }

            // Check credits
            $elData = getElevenLabsCredits($token);
            if ($elData === null) {
                $results['failed']++;
                $results['details'][] = [
                    'key' => substr($keyEncrypted, 0, 30) . '...',
                    'full_key' => $keyEncrypted,
                    'status' => 'failed',
                    'error' => 'Invalid key or failed to fetch credits'
                ];
                continue;
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

            $results['success']++;
            $results['details'][] = [
                'key' => substr($keyEncrypted, 0, 30) . '...',
                'status' => 'success',
                'credits' => $credits
            ];

        } catch (Exception $e) {
            $results['failed']++;
            $results['details'][] = [
                'key' => substr($keyEncrypted, 0, 30) . '...',
                'full_key' => $keyEncrypted,
                'status' => 'failed',
                'error' => $e->getMessage()
            ];
        }
    }

    jsonResponse([
        'status' => 'success',
        'message' => "Imported {$results['success']}/{$results['total']} keys",
        'results' => $results
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
?>