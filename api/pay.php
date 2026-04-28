<?php
require_once __DIR__ . '/config.php';

$input = json_decode(file_get_contents('php://input'), true);
$method = $_SERVER['REQUEST_METHOD'];

// TẠM KHÓA NẠP TIỀN / GIA HẠN
jsonResponse(['error' => 'Hệ thống đang tạm khóa tính năng Nạp Tiền / Gia Hạn. Xin quý khách vui lòng sử dụng nốt số điểm còn lại!'], 503);

if ($method === 'POST') {
    $token = $input['token'] ?? '';
    $planId = $input['plan_id'] ?? '';
    $action = $input['action'] ?? 'request';

    $user = verifyToken($token);
    if (!$user) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $packages = getSubscriptionPackages();
    if (!isset($packages[$planId])) {
        jsonResponse(['error' => 'Invalid plan'], 400);
    }

    if ($planId === 'trial' || $packages[$planId]['price'] <= 0) {
        jsonResponse(['error' => 'Gói cước này không thể mua!'], 400);
    }

    $package = $packages[$planId];
    $userId = $user['user_id'];

    // Action: Generate QR Info
    if ($action === 'request') {
        // Price mapping from DB
        $amount = $package['price'] ?? 0;
        $userMail = $user['email'] ?? "ID{$userId}";
        $memo = "VOICE {$userMail}";

        // Placeholder Bank Info - User will update this
        $bankId = "MB"; // Example
        $accountNo = "0000000000";

        $qrUrl = "https://img.vietqr.io/image/{$bankId}-{$accountNo}-compact2.png?amount={$amount}&addInfo=" . urlencode($memo);

        // Log pending payment (Only if no pending/sent already exists to avoid duplicates)
        try {
            $db = getDB();

            // Check for existing pending/sent for this user and plan
            $stmt = $db->prepare("SELECT id FROM payments WHERE user_id = ? AND plan_id = ? AND (status = 'pending' OR status = 'sent')");
            $stmt->execute([$userId, $planId]);
            $existing = $stmt->fetch();

            if (!$existing) {
                $stmt = $db->prepare("INSERT INTO payments (user_id, plan_id, amount, status, memo) VALUES (?, ?, ?, 'pending', ?)");
                $stmt->execute([$userId, $planId, $amount, $memo]);
            }
        } catch (Exception $e) {
            // Ignore logging errors for request
        }

        jsonResponse([
            'status' => 'success',
            'qr_url' => $qrUrl,
            'amount' => $amount,
            'memo' => $memo
        ]);
    }

    // Action: Confirm Payment (User informs admin)
    if ($action === 'confirm_payment') {
        $paymentMethod = $input['payment_method'] ?? 'momo';
        try {
            $db = getDB();

            // Lazy schema: add payment_method column if not exists
            try {
                $db->exec("ALTER TABLE payments ADD COLUMN payment_method VARCHAR(20) DEFAULT 'momo' AFTER memo");
            } catch (Exception $e) {
            }

            // Update the latest pending payment for this user and plan to 'sent'
            $stmt = $db->prepare("UPDATE payments SET status = 'sent', payment_method = ? WHERE user_id = ? AND plan_id = ? AND status = 'pending' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$paymentMethod, $userId, $planId]);

            // Notify via Telegram
            require_once __DIR__ . '/telegram.php';
            $stmt = $db->prepare("SELECT email, referrer_id FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $userRow = $stmt->fetch();
            $userMail = $userRow['email'];
            $referrerId = $userRow['referrer_id'];

            // Get amount and memo for the notification from the payment record
            $stmt = $db->prepare("SELECT amount, memo FROM payments WHERE user_id = ? AND plan_id = ? AND status = 'sent' ORDER BY created_at DESC LIMIT 1");
            $stmt->execute([$userId, $planId]);
            $payData = $stmt->fetch();

            if ($payData) {
                $methodLabel = strtoupper($paymentMethod);
                notifyPaymentSent($userMail, $planId, $payData['amount'], $payData['memo'] . " [{$methodLabel}]");

                // --- AFFILIATE COMMISSION (Dynamic Rate) ---
                if ($referrerId) {
                    $rate = getAffiliateCommissionRate();
                    $commissionAmount = $payData['amount'] * $rate;

                    // Check if commission already exists for this payment (simple check preventing duplicate for same day/user/amount)
                    // Better: Link commission to payment ID, but current payments table structure is simple.
                    // We'll trust the 'confirm_payment' flow is triggered once by admin or user per transaction.

                    $stmtComm = $db->prepare("INSERT INTO commissions (referrer_id, payer_id, amount, order_amount, status) VALUES (?, ?, ?, ?, 'pending')");
                    $stmtComm->execute([$referrerId, $userId, $commissionAmount, $payData['amount']]);
                }
            }

            jsonResponse([
                'status' => 'success',
                'message' => 'Thông báo thành công! Admin sẽ kiểm tra và kích hoạt gói cước cho bạn sớm nhất.'
            ]);
        } catch (Exception $e) {
            jsonResponse(['error' => $e->getMessage()], 500);
        }
    }
}
?>