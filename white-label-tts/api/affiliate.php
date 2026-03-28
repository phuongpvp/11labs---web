<?php
// =====================================================
// Affiliate API — White-Label TTS
// =====================================================
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

// Auth
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);
$user = verifyCustomerToken($token);

if (!$user) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$db = getDB();
$userId = $user['id'];

try {
    // 1. Get/Generate referral code
    $refCode = $user['referral_code'] ?? '';
    if (!$refCode) {
        $refCode = 'R' . strtoupper(bin2hex(random_bytes(4)));
        $db->prepare("UPDATE customers SET referral_code = ? WHERE id = ?")->execute([$refCode, $userId]);
    }

    // 2. Get referred customers
    $stmt = $db->prepare("
        SELECT c.email, c.plan_name, c.is_active, c.created_at, c.quota_allocated,
               COALESCE(b.bonus_amount, 0) as bonus_given
        FROM customers c
        LEFT JOIN affiliate_bonus_logs b ON b.referred_id = c.id AND b.referrer_id = ?
        WHERE c.referred_by = ?
        ORDER BY c.created_at DESC
    ");
    $stmt->execute([$userId, $userId]);
    $referrals = $stmt->fetchAll();

    // 3. Stats
    $totalReferrals = count($referrals);
    $activeCount = 0;
    $totalBonus = 0;
    foreach ($referrals as $r) {
        if ($r['is_active']) $activeCount++;
        $totalBonus += (int)$r['bonus_given'];
    }

    // 4. Revenue from plan activations of referred users
    $stmt = $db->prepare("
        SELECT c.email, pa.plan_name, pa.quota_granted, pa.activated_at
        FROM plan_activations pa
        JOIN customers c ON pa.customer_id = c.id
        WHERE c.referred_by = ?
        ORDER BY pa.activated_at DESC
    ");
    $stmt->execute([$userId]);
    $revenueActivations = $stmt->fetchAll();

    // 5. Get commission rate from settings
    $settingsFile = __DIR__ . '/../data/settings.json';
    $settings = file_exists($settingsFile) ? (json_decode(file_get_contents($settingsFile), true) ?? []) : [];
    $commissionRate = floatval($settings['affiliate_commission_rate'] ?? 10);

    // Build referral link base
    $siteUrl = rtrim($settings['site_url'] ?? '', '/');
    if (!$siteUrl) {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $siteUrl = $protocol . '://' . $_SERVER['HTTP_HOST'] . dirname(dirname($_SERVER['SCRIPT_NAME']));
    }

    jsonResponse([
        'status' => 'success',
        'data' => [
            'referral_code' => $refCode,
            'referral_link' => $siteUrl . '/app.html?ref=' . $refCode,
            'referral_count' => $totalReferrals,
            'active_count' => $activeCount,
            'total_bonus' => $totalBonus,
            'commission_rate' => $commissionRate,
            'referrals' => $referrals,
            'revenue_activations' => $revenueActivations
        ]
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
?>
