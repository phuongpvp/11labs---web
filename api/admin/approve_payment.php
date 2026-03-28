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

    // 1. Get Payment Info
    $stmt = $db->prepare("SELECT * FROM payments WHERE id = ? AND (status = 'sent' OR status = 'pending')");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();

    if (!$payment) {
        jsonResponse(['error' => 'Payment not found or already processed'], 404);
    }

    $userId = $payment['user_id'];
    $planId = $payment['plan_id'];

    // 2. Get Package Info
    $packages = getSubscriptionPackages();
    if (!isset($packages[$planId])) {
        jsonResponse(['error' => 'Invalid plan in payment record'], 400);
    }
    $package = $packages[$planId];

    // 2.5 Get current User stats for stacking
    $stmtUser = $db->prepare("SELECT quota_total, expires_at, status FROM users WHERE id = ?");
    $stmtUser->execute([$userId]);
    $currentUser = $stmtUser->fetch();

    if (!$currentUser) {
        jsonResponse(['error' => 'User not found'], 404);
    }

    // --- LOGIC CỘNG DỒN (STACKING) ---

    // 1. Dung lượng: Cộng thêm vào tổng hiện tại
    // Chú ý: Không reset quota_used về 0 để bảo toàn phần đã dùng, 
    // tổng dung lượng mới = tổng cũ + gói mới.
    $newQuotaTotal = $currentUser['quota_total'] + $package['quota'];

    // 2. Hạn dùng: Gia hạn nếu còn hạn, hoặc tính từ nay nếu đã hết
    $currentExpiresAt = strtotime($currentUser['expires_at']);
    $now = time();

    if ($currentExpiresAt > $now && $currentUser['status'] === 'active') {
        // Còn hạn: Cộng thêm số ngày vào ngày hết hạn cũ
        $newExpiresAt = date('Y-m-d H:i:s', strtotime("+{$package['days']} days", $currentExpiresAt));
    } else {
        // Hết hạn hoặc status khác: Tính từ thời điểm duyệt
        $newExpiresAt = date('Y-m-d H:i:s', strtotime("+{$package['days']} days", $now));
    }

    $db->beginTransaction();

    // 3. Update User
    // Chỉnh sửa: Sử dụng $newQuotaTotal và $newExpiresAt, KHÔNG reset quota_used
    $stmt = $db->prepare("UPDATE users SET plan = ?, quota_total = ?, expires_at = ?, status = 'active' WHERE id = ?");
    $stmt->execute([$planId, $newQuotaTotal, $newExpiresAt, $userId]);

    // 4. Update Payment
    $stmt = $db->prepare("UPDATE payments SET status = 'completed' WHERE id = ?");
    $stmt->execute([$paymentId]);

    $db->commit();

    // Set celebration flag for customer popup
    try {
        $db->exec("ALTER TABLE users ADD COLUMN payment_celebration TINYINT DEFAULT 0");
    } catch (Exception $e) { /* Ignore if exists */ }
    $db->prepare("UPDATE users SET payment_celebration = 1 WHERE id = ?")->execute([$userId]);

    // Notify via Telegram
    require_once __DIR__ . '/../telegram.php';
    require_once __DIR__ . '/../mail_helper.php';

    $stmt = $db->prepare("SELECT email FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $userMail = $stmt->fetchColumn();

    notifyPaymentApproved($userMail, $planId);
    sendActivationEmail($userMail, $planId, $newQuotaTotal, $newExpiresAt);

    jsonResponse([
        'status' => 'success',
        'message' => 'Duyệt thanh toán thành công! Gói cước đã được kích hoạt.'
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction())
        $db->rollBack();
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
?>