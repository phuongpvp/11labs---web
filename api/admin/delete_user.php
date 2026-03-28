<?php
require_once __DIR__ . '/../config.php';

// Handle OPTIONS for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$adminPassword = $input['admin_password'] ?? '';
$userId = $input['user_id'] ?? 0;

// Verify admin
if (!verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

if (!$userId) {
    jsonResponse(['error' => 'Missing user_id'], 400);
}

try {
    $db = getDB();

    // Delete the user
    $stmt = $db->prepare("DELETE FROM users WHERE id = ?");
    $stmt->execute([$userId]);

    if ($stmt->rowCount() > 0) {
        jsonResponse([
            'status' => 'success',
            'message' => 'User deleted successfully'
        ]);
    } else {
        jsonResponse(['error' => 'Failed to delete user or user not found'], 500);
    }
} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
