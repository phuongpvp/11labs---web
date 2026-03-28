<?php
require_once __DIR__ . '/config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

// 2026-02-27: Disable manual registration to prevent spam. Only Google Login allowed for new users.
jsonResponse(['error' => 'Tính năng đăng ký thủ công hiện đã được đóng để bảo trì. Vui lòng sử dụng Đăng nhập bằng Google để tiếp tục.'], 403);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';
$password = $input['password'] ?? '';
$referralCode = $input['referral_code'] ?? ''; // New Input
$plan = 'trial'; // Default plan for new registrations

if (!$email || !$password) {
    jsonResponse(['error' => 'Email and password required'], 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(['error' => 'Định dạng Email không hợp lệ'], 400);
}

// Fetch packages from DB
$packages = getSubscriptionPackages();

// Validate trial package exists
if (!isset($packages[$plan])) {
    jsonResponse(['error' => 'Hệ thống chưa cấu hình gói Trial. Vui lòng liên hệ Admin.'], 500);
}

$package = $packages[$plan];

try {
    $db = getDB();
    if (!$db) {
        throw new Exception("Không thể kết nối Database");
    }

    // Get User IP
    $clientIp = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $clientIp = explode(',', $clientIp)[0]; // If multiple proxy IPs, take the first one

    // 0. Email Abuse/Duplicate Check
    $domain = strtolower(explode('@', $email)[1]);
    $googleDomains = ['gmail.com', 'googlemail.com', 'google.com'];

    if (in_array($domain, $googleDomains)) {
        $localPart = strtolower(explode('@', $email)[0]);
        $localPart = preg_replace('/\+.*$/', '', $localPart); // Remove everything after +
        $normalizedLocal = str_replace('.', '', $localPart); // Remove dots

        // Check for any variation in the database
        $stmtCheck = $db->prepare("
            SELECT id FROM users 
            WHERE SUBSTRING_INDEX(email, '@', -1) IN ('gmail.com', 'googlemail.com', 'google.com')
            AND REPLACE(SUBSTRING_INDEX(SUBSTRING_INDEX(email, '@', 1), '+', 1), '.', '') = ?
        ");
        $stmtCheck->execute([$normalizedLocal]);
        if ($stmtCheck->fetch()) {
            jsonResponse(['error' => 'Email này hoặc biến thể của nó đã tồn tại trong hệ thống. Vui lòng dùng email khác.'], 400);
        }
    } else {
        $stmtCheck = $db->prepare("SELECT id FROM users WHERE email = ?");
        $stmtCheck->execute([$email]);
        if ($stmtCheck->fetch()) {
            jsonResponse(['error' => 'Email này đã được sử dụng.'], 400);
        }
    }

    // Anti-spam: Block specific suspicious patterns if needed
    if (strpos($email, 'hihong547') !== false) {
        jsonResponse(['error' => 'Hệ thống phát hiện dấu hiệu bất thường. Vui lòng liên hệ Admin.'], 403);
    }

    // 0.5 IP Rate Limiting (Prevent mass registration)
    if ($clientIp !== '0.0.0.0' && $clientIp !== '127.0.0.1' && $clientIp !== '::1') {
        $stmtIp = $db->prepare("SELECT COUNT(id) FROM users WHERE register_ip = ? AND created_at > (NOW() - INTERVAL 24 HOUR)");
        $stmtIp->execute([$clientIp]);
        $ipCount = (int) $stmtIp->fetchColumn();

        if ($ipCount >= 3) {
            jsonResponse(['error' => 'Bạn đã tạo quá nhiều tài khoản trong hôm nay. Xin vui lòng thử lại sau 24h.'], 429);
        }
    }

    // 1. Hash password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // 2. Calculate expiry
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$package['days']} days"));

    // For Email Activation Flow, we set status to 'pending'
    $status = 'pending';
    $message = 'Vui lòng kiểm tra hòm thư để kích hoạt. Lưu ý: Nếu không thấy email trong Hộp thư đến, bạn vui lòng kiểm tra cả phần Thư rác (Spam) nhé.';

    // Insert user
    // Generate own referral code
    $ownReferralCode = 'U' . uniqid();

    // Lookup Referrer
    $referrerCode = $input['referral_code'] ?? ''; // Expecting input from frontend
    $referrerId = null;

    if ($referrerCode) {
        $stmtRef = $db->prepare("SELECT id FROM users WHERE referral_code = ?");
        $stmtRef->execute([$referrerCode]);
        $referrerId = $stmtRef->fetchColumn() ?: null;
    }

    $stmt = $db->prepare("INSERT INTO users (email, password_hash, plan, quota_total, quota_used, expires_at, status, referral_code, referrer_id, register_ip) VALUES (?, ?, ?, ?, 0, ?, ?, ?, ?, ?)");
    $success = $stmt->execute([$email, $passwordHash, $plan, $package['quota'], $expiresAt, $status, $ownReferralCode, $referrerId, $clientIp]);

    if (!$success) {
        $errorInfo = $stmt->errorInfo();
        throw new Exception("Lỗi khi thêm user: " . ($errorInfo[2] ?? 'Unknown DB error'));
    }

    $userId = $db->lastInsertId();

    // --- EMAIL ACTIVATION TOKEN ---
    try {
        require_once __DIR__ . '/mail_helper.php';
        $token = bin2hex(random_bytes(32)); // 64 chars
        $expiresToken = date('Y-m-d H:i:s', strtotime('+24 hours'));

        $stmtToken = $db->prepare("INSERT INTO activation_tokens (user_id, token, expires_at) VALUES (?, ?, ?)");
        $stmtToken->execute([$userId, $token, $expiresToken]);

        sendActivationLink($email, $token);
    } catch (Exception $mailEx) {
        error_log("Activation Email error: " . $mailEx->getMessage());
    }

    // Trigger Telegram Notification for Admin
    try {
        require_once __DIR__ . '/telegram.php';
        notifyNewRegistration($email, $plan);
    } catch (Exception $tgEx) {
        error_log("Telegram Notify Error: " . $tgEx->getMessage());
    }

    jsonResponse([
        'status' => 'success',
        'message' => $message,
        'user' => [
            'id' => $userId,
            'email' => $email,
            'plan' => $plan,
            'status' => $status
        ]
    ]);

} catch (PDOException $e) {
    error_log("Register DB Error: " . $e->getMessage());
    jsonResponse(['error' => 'Lỗi hệ thống, vui lòng thử lại sau.'], 500);
} catch (Exception $e) {
    jsonResponse(['error' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
}
?>