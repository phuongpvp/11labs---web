<?php
/**
 * Reload inactive/cooldown keys back to active status
 * - Sets all 'inactive' keys back to 'active'
 * - Clears cooldown_until for all keys in cooldown
 */
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);

// Auth: admin password via body or header
$pwd = $input['admin_password'] ?? '';
if (!$pwd) {
    $pwd = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
}
if (!$pwd || !verifyAdminPassword($pwd)) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

try {
    $db = getDB();

    // 1. Reactivate inactive keys (that still have credits)
    $stmt = $db->prepare("UPDATE api_keys SET status = 'active' WHERE status != 'active' AND CAST(REPLACE(credits_remaining, '.', '') AS SIGNED) > 0");
    $stmt->execute();
    $reactivated = $stmt->rowCount();

    // 2. Clear all cooldowns
    $stmt2 = $db->prepare("UPDATE api_keys SET cooldown_until = NULL WHERE cooldown_until IS NOT NULL");
    $stmt2->execute();
    $cooldownCleared = $stmt2->rowCount();

    logToFile('admin_actions.log', "RELOAD KEYS: Admin reactivated $reactivated inactive keys, cleared $cooldownCleared cooldowns.");

    jsonResponse([
        'status' => 'success',
        'reactivated' => $reactivated,
        'cooldown_cleared' => $cooldownCleared
    ]);
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
