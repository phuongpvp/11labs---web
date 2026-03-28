<?php
require_once __DIR__ . '/../config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$adminPassword = $input['admin_password'] ?? '';
$paymentId = $input['payment_id'] ?? 0;

if (!verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Unauthorized'], 403);
}

if (!$paymentId) {
    jsonResponse(['error' => 'Missing payment_id'], 400);
}

try {
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM payments WHERE id = ?");
    $stmt->execute([$paymentId]);

    if ($stmt->rowCount() > 0) {
        jsonResponse([
            'status' => 'success',
            'message' => 'Đã xóa giao dịch thành công.'
        ]);
    } else {
        jsonResponse(['error' => 'Không tìm thấy giao dịch hoặc đã bị xóa'], 404);
    }
} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
?>