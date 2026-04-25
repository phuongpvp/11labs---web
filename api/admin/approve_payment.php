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

    // --- AFFILIATE BONUS: When a payment is completed ---
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS affiliate_bonus_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            referrer_id INT NOT NULL,
            referred_id INT NOT NULL,
            bonus_amount INT NOT NULL DEFAULT 0,
            plan_quota INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (referrer_id), INDEX (referred_id)
        )");

        $stmtRef = $db->prepare("SELECT referrer_id FROM users WHERE id = ?");
        $stmtRef->execute([$userId]);
        $referrerId = $stmtRef->fetchColumn();

        if ($referrerId) {
            $stmtChk = $db->prepare("SELECT id FROM affiliate_bonus_logs WHERE referrer_id = ? AND referred_id = ?");
            $stmtChk->execute([$referrerId, $userId]);

            if (!$stmtChk->fetch()) {
                $bonusRate = floatval(getSystemSetting('affiliate_commission_rate', '10')) / 100;
                $bonusAmount = (int) ceil($package['quota'] * $bonusRate); // Using the paid package's quota

                if ($bonusAmount > 0) {
                    $db->prepare("UPDATE users SET quota_total = quota_total + ? WHERE id = ?")->execute([$bonusAmount, $referrerId]);
                    $db->prepare("UPDATE users SET quota_total = quota_total + ? WHERE id = ?")->execute([$bonusAmount, $userId]);
                    $db->prepare("INSERT INTO affiliate_bonus_logs (referrer_id, referred_id, bonus_amount, plan_quota) VALUES (?, ?, ?, ?)")->execute([$referrerId, $userId, $bonusAmount, $package['quota']]);
                }
            }
        }
    } catch (Exception $affEx) {
        error_log("Affiliate Bonus Error: " . $affEx->getMessage());
    }

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