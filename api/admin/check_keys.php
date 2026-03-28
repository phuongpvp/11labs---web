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
$ids = $input['ids'] ?? [];

if (!$adminPassword || empty($ids)) {
    jsonResponse(['error' => 'Admin password and key IDs required'], 400);
}

// Verify admin
if (!verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Invalid admin password'], 403);
}

if (!is_array($ids)) {
    $ids = [$ids];
}

try {
    $db = getDB();

    $results = [
        'total' => count($ids),
        'success' => 0,
        'failed' => 0,
        'details' => []
    ];

    foreach ($ids as $id) {
        try {
            // Get key data from DB
            $stmt = $db->prepare("SELECT * FROM api_keys WHERE id = ?");
            $stmt->execute([$id]);
            $keyRow = $stmt->fetch();

            if (!$keyRow) {
                $results['failed']++;
                $results['details'][] = ['id' => $id, 'status' => 'failed', 'error' => 'Key not found'];
                continue;
            }

            // Resolve effective key (Token or API Key)
            // Note: We need to override getEffectiveElevenLabsKey behavior to force useWorker if login is needed
            // However, getEffectiveElevenLabsKey in config.php doesn't take useWorker as param yet.
            // Let's modify the local logic here for higher reliability.

            $decryptedKey = decryptKey($keyRow['key_encrypted']);
            $finalKeyOrToken = null;

            // 1. Check for valid cached token
            if (!empty($keyRow['fb_token']) && !empty($keyRow['fb_token_expires'])) {
                $expiry = strtotime($keyRow['fb_token_expires']);
                if ($expiry > (time() + 60)) {
                    $finalKeyOrToken = $keyRow['fb_token'];
                }
            }

            // 2. Try refreshing
            if (!$finalKeyOrToken && !empty($keyRow['fb_refresh_token'])) {
                $finalKeyOrToken = refreshFirebaseToken($keyRow['fb_refresh_token'], $id);
            }

            // 3. Login via Worker if needed
            if (!$finalKeyOrToken) {
                if (strpos($decryptedKey, ':') !== false && strpos($decryptedKey, '@') !== false) {
                    list($email, $pass) = explode(':', $decryptedKey, 2);
                    $fbError = "";
                    $finalKeyOrToken = loginWithFirebase($email, $pass, $id, $fbError, false, true); // useWorker = true
                } else {
                    $finalKeyOrToken = $decryptedKey;
                }
            }

            if (!$finalKeyOrToken) {
                throw new Exception("Failed to resolve key or login");
            }

            // Fetch credits
            $elData = getElevenLabsCredits($finalKeyOrToken);
            if ($elData === null) {
                throw new Exception("Invalid key or ElevenLabs API error");
            }
            $credits = $elData['credits'];
            $resetAt = $elData['reset_at'];

            // Update DB
            $stmt = $db->prepare("UPDATE api_keys SET credits_remaining = ?, last_checked = NOW(), status = 'active', reset_at = ? WHERE id = ?");
            $stmt->execute([$credits, $resetAt, $id]);

            $results['success']++;
            $results['details'][] = [
                'id' => $id,
                'status' => 'success',
                'credits' => $credits,
                'reset_at' => $resetAt
            ];

        } catch (Exception $e) {
            $results['failed']++;
            $results['details'][] = [
                'id' => $id,
                'status' => 'failed',
                'error' => $e->getMessage()
            ];

            // If explicit "invalid key" from ElevenLabs, we might want to mark it as inactive
            if (strpos($e->getMessage(), 'Invalid key') !== false) {
                $db->prepare("UPDATE api_keys SET status = 'inactive' WHERE id = ?")->execute([$id]);
            }
        }
    }

    jsonResponse([
        'status' => 'success',
        'message' => "Checked {$results['success']}/{$results['total']} keys",
        'results' => $results
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
