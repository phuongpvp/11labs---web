<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$adminPassword = $input['admin_password'] ?? '';

if (!verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Invalid admin password'], 403);
}

try {
    $db = getDB();

    // 1. Calculate how much we can harvest
    $stmt = $db->query("SELECT SUM(amount) FROM payments WHERE status = 'completed'");
    $totalRevenue = $stmt->fetchColumn() ?: 0;

    $stmt = $db->query("SELECT SUM(amount) FROM admin_harvests");
    $totalHarvested = $stmt->fetchColumn() ?: 0;

    $available = $totalRevenue - $totalHarvested;

    if ($available <= 0) {
        jsonResponse(['error' => 'Không có số dư khả dụng để rút'], 400);
    }

    // 2. Record the harvest
    $notes = $input['notes'] ?? 'Rút doanh thu hệ thống';
    $stmt = $db->prepare("INSERT INTO admin_harvests (amount, notes) VALUES (?, ?)");
    $stmt->execute([$available, $notes]);

    jsonResponse([
        'status' => 'success',
        'message' => 'Đã rút ' . number_format($available) . 'đ về túi thành công!',
        'amount' => $available
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
