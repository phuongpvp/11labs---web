<?php
require_once '../config.php';

header('Content-Type: application/json');

$input = json_decode(file_get_contents('php://input'), true);
$adminPassword = $input['admin_password'] ?? '';
$keyId = $input['key_id'] ?? 0;

// Verify admin password using password_verify (bcrypt)
if (!password_verify($adminPassword, ADMIN_PASSWORD_HASH)) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'error' => 'Unauthorized']);
    exit;
}

if (!$keyId) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Missing key_id']);
    exit;
}

$conn = getDB();

// Delete the API key
$stmt = $conn->prepare("DELETE FROM api_keys WHERE id = ?");
$stmt->execute([$keyId]);

if ($stmt->rowCount() > 0) {
    echo json_encode([
        'status' => 'success',
        'message' => 'Key deleted successfully'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'error' => 'Failed to delete key or key not found'
    ]);
}
