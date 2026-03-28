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
$minCredits = (int) ($input['min_credits'] ?? 0);

if (!$adminPassword) {
    jsonResponse(['error' => 'Admin password required'], 400);
}

// Verify admin
if (!verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Invalid admin password'], 403);
}

try {
    $db = getDB();

    // Delete keys with credits <= threshold
    $stmt = $db->prepare("DELETE FROM api_keys WHERE credits_remaining <= ?");
    $stmt->execute([$minCredits]);

    $deletedCount = $stmt->rowCount();

    jsonResponse([
        'status' => 'success',
        'message' => "Đã xóa {$deletedCount} key có ít hơn hoặc bằng {$minCredits} credits.",
        'deleted_count' => $deletedCount
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
?>
