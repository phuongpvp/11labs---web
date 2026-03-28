<?php
require_once __DIR__ . '/config.php';

$token = $_GET['token'] ?? '';

if (!$token) {
    jsonResponse(['error' => 'Missing token'], 400);
}

try {
    $db = getDB();

    // Debug: Check if token exists at all without expiry check
    $stmt = $db->prepare("SELECT id, reset_token, reset_expires, NOW() as db_now FROM users WHERE reset_token = ?");
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        if ($user['reset_expires'] > $user['db_now']) {
            jsonResponse(['status' => 'success']);
        } else {
            jsonResponse(['error' => 'Token has expired. DB Expire: ' . $user['reset_expires'] . ', DB Now: ' . $user['db_now']], 400);
        }
    } else {
        jsonResponse(['error' => 'Token not found in database. Token checked: ' . $token], 400);
    }
} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
?>