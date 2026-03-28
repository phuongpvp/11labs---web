<?php
require_once __DIR__ . '/config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

// Get User
$authHeader = getAuthorizationHeader();
$token = $authHeader ? str_replace('Bearer ', '', $authHeader) : '';
$user = verifyToken($token);

if (!$user) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$userId = $user['user_id'];
$db = getDB();

try {
    // Ensure affiliate_bonus_logs table exists
    $db->exec("CREATE TABLE IF NOT EXISTS affiliate_bonus_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        referrer_id INT NOT NULL,
        referred_id INT NOT NULL,
        bonus_amount INT NOT NULL DEFAULT 0,
        plan_quota INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX (referrer_id),
        INDEX (referred_id)
    )");

    // 1. Get Referral Code
    $stmt = $db->prepare("SELECT referral_code FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $refCode = $stmt->fetchColumn();

    // Auto-generate if missing
    if (!$refCode) {
        $refCode = 'U' . $userId . rand(100, 999);
        $db->prepare("UPDATE users SET referral_code = ? WHERE id = ?")->execute([$refCode, $userId]);
    }

    // 2. Get referred users (who signed up with this user's code)
    $stmt = $db->prepare("
        SELECT u.email, u.plan, u.custom_plan_name, u.status, u.created_at, u.quota_total,
               COALESCE(b.bonus_amount, 0) as bonus_given
        FROM users u
        LEFT JOIN affiliate_bonus_logs b ON b.referred_id = u.id AND b.referrer_id = ?
        WHERE u.referrer_id = ?
        ORDER BY u.created_at DESC
    ");
    $stmt->execute([$userId, $userId]);
    $referrals = $stmt->fetchAll();

    // 3. Stats
    $totalReferrals = count($referrals);
    $paidCount = 0;
    $totalBonus = 0;
    foreach ($referrals as $r) {
        if ($r['status'] === 'active')
            $paidCount++;
        $totalBonus += (int) $r['bonus_given'];
    }

    // 4. Revenue tracking - payments from referred users
    $stmt = $db->prepare("
        SELECT u.email, p.plan_id, p.amount, p.status as pay_status, p.created_at
        FROM payments p
        JOIN users u ON p.user_id = u.id
        WHERE u.referrer_id = ? AND p.status = 'completed'
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([$userId]);
    $revenuePayments = $stmt->fetchAll();

    $totalRevenue = 0;
    foreach ($revenuePayments as $rp) {
        $totalRevenue += (int) $rp['amount'];
    }

    // 5. Bonus rate from settings
    $bonusRate = floatval(getSystemSetting('affiliate_commission_rate', '10'));

    jsonResponse([
        'status' => 'success',
        'data' => [
            'referral_code' => $refCode,
            'referral_link' => PHP_BACKEND_URL . "/login?r=" . $refCode,
            'referral_count' => $totalReferrals,
            'paid_count' => $paidCount,
            'total_bonus' => $totalBonus,
            'total_revenue' => $totalRevenue,
            'revenue_payments' => $revenuePayments,
            'bonus_rate' => $bonusRate,
            'referrals' => $referrals
        ]
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
?>