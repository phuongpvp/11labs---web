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
$quotaTotal = $input['quota_total'] ?? null;
$expiresAt = $input['expires_at'] ?? null;
$status = $input['status'] ?? null;
$customPlanName = $input['custom_plan_name'] ?? null;

// Verify admin
if (!verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

if (!$userId) {
    jsonResponse(['error' => 'Missing user_id'], 400);
}

try {
    $db = getDB();

    // Build update query dynamically
    $updates = [];
    $params = [];

    if ($quotaTotal !== null) {
        $updates[] = "quota_total = ?";
        $params[] = $quotaTotal;
    }
    if ($expiresAt !== null) {
        $updates[] = "expires_at = ?";
        $params[] = $expiresAt;
    }
    if ($status !== null) {
        $updates[] = "status = ?";
        $params[] = $status;
    }
    if (isset($input['plan'])) {
        $updates[] = "plan = ?";
        $params[] = $input['plan'];
    }
    if ($customPlanName !== null) {
        $updates[] = "custom_plan_name = ?";
        // If empty string, set it back to NULL in the database
        $params[] = $customPlanName === '' ? null : $customPlanName;
    }
    if (isset($input['partner_api_key'])) {
        $updates[] = "partner_api_key = ?";
        $params[] = $input['partner_api_key'] === '' ? null : $input['partner_api_key'];
    }
    if (isset($input['key_type'])) {
        $updates[] = "key_type = ?";
        $params[] = in_array($input['key_type'], ['partner', 'developer']) ? $input['key_type'] : 'partner';
    }
    if (array_key_exists('max_parallel', $input)) {
        // Lazy schema: add column if not exists
        try { $db->exec("ALTER TABLE users ADD COLUMN max_parallel INT DEFAULT NULL"); } catch (Exception $e) {}
        $updates[] = "max_parallel = ?";
        $val = $input['max_parallel'];
        $params[] = ($val !== null && $val !== '' && $val !== 0) ? (int)$val : null;
    }

    if (empty($updates)) {
        jsonResponse(['error' => 'No changes provided'], 400);
    }

    $params[] = $userId;

    // Get old info if we're changing status to active
    $shouldSendEmail = false;
    if ($status === 'active') {
        $stmtCheck = $db->prepare("SELECT email, status, plan, quota_total, expires_at FROM users WHERE id = ?");
        $stmtCheck->execute([$userId]);
        $oldUser = $stmtCheck->fetch();
        if ($oldUser && $oldUser['status'] !== 'active') {
            $shouldSendEmail = true;
            $userMail = $oldUser['email'];
            $planName = $input['plan'] ?? $oldUser['plan'];
            $quota = $quotaTotal ?? $oldUser['quota_total'];
            $expiry = $expiresAt ?? $oldUser['expires_at'];
        }
    }

    $sql = "UPDATE users SET " . implode(", ", $updates) . " WHERE id = ?";
    $stmt = $db->prepare($sql);

    if ($stmt->execute($params)) {
        if ($shouldSendEmail) {
            require_once __DIR__ . '/../mail_helper.php';
            sendActivationEmail($userMail, $planName, $quota, $expiry);
        }

        // --- AFFILIATE BONUS: When activating a referred user ---
        if ($status === 'active' && $oldUser && $oldUser['status'] !== 'active') {
            try {
                // Ensure table exists
                $db->exec("CREATE TABLE IF NOT EXISTS affiliate_bonus_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    referrer_id INT NOT NULL,
                    referred_id INT NOT NULL,
                    bonus_amount INT NOT NULL DEFAULT 0,
                    plan_quota INT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX (referrer_id), INDEX (referred_id)
                )");

                // Check if user has a referrer
                $stmtRef = $db->prepare("SELECT referrer_id FROM users WHERE id = ?");
                $stmtRef->execute([$userId]);
                $referrerId = $stmtRef->fetchColumn();

                if ($referrerId) {
                    // Check if bonus was already given for this pair
                    $stmtChk = $db->prepare("SELECT id FROM affiliate_bonus_logs WHERE referrer_id = ? AND referred_id = ?");
                    $stmtChk->execute([$referrerId, $userId]);

                    if (!$stmtChk->fetch()) {
                        // Calculate bonus: X% of the plan's quota
                        $userQuota = $quotaTotal ?? $oldUser['quota_total'];
                        $bonusRate = floatval(getSystemSetting('affiliate_commission_rate', '10')) / 100;
                        $bonusAmount = (int) ceil($userQuota * $bonusRate);

                        if ($bonusAmount > 0) {
                            // Add bonus to REFERRER
                            $db->prepare("UPDATE users SET quota_total = quota_total + ? WHERE id = ?")
                                ->execute([$bonusAmount, $referrerId]);

                            // Add bonus to REFERRED USER
                            $db->prepare("UPDATE users SET quota_total = quota_total + ? WHERE id = ?")
                                ->execute([$bonusAmount, $userId]);

                            // Log the bonus
                            $db->prepare("INSERT INTO affiliate_bonus_logs (referrer_id, referred_id, bonus_amount, plan_quota) VALUES (?, ?, ?, ?)")
                                ->execute([$referrerId, $userId, $bonusAmount, $userQuota]);
                        }
                    }
                }
            } catch (Exception $affEx) {
                error_log("Affiliate Bonus Error: " . $affEx->getMessage());
                // Don't fail the main operation
            }
        }

        jsonResponse([
            'status' => 'success',
            'message' => 'User updated successfully'
        ]);
    } else {
        jsonResponse(['error' => 'Failed to update user'], 500);
    }
} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
