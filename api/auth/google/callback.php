<?php
require_once __DIR__ . '/../../config.php';

// Google OAuth Configuration
$clientId = GOOGLE_CLIENT_ID;
$clientSecret = GOOGLE_CLIENT_SECRET;
$redirectUri = PHP_BACKEND_URL . '/api/auth/google/callback.php';

// Log request for debugging
error_log("Google Callback - Method: " . $_SERVER['REQUEST_METHOD']);

if ($clientSecret === 'ANH_DIEN_CLIENT_SECRET_VAO_DAY') {
    die("Lỗi: Anh chưa điền 'Client Secret' vào file api/config.php ạ! Quế ơi, anh vào Google Cloud lấy cái mã đó dán vô nhé.");
}

try {
    // 1. Get Authentication Data
    $code = $_GET['code'] ?? null;
    $credential = $_POST['credential'] ?? null;
    $referralCode = $_POST['referral_code'] ?? $_GET['r'] ?? '';
    $error = $_GET['error'] ?? null;

    if ($error) {
        die("Lỗi xác thực Google: " . htmlspecialchars($error));
    }

    $email = null;
    $name = 'Google User';

    if ($credential) {
        // GIS Identity Token Flow (JWT) - This is what the Google Login Button uses
        error_log("Google Callback - Using Identity Token Flow");
        $parts = explode('.', $credential);
        if (count($parts) === 3) {
            $payload = json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
            $email = $payload['email'] ?? null;
            $name = $payload['name'] ?? 'Google User';
        }
    } elseif ($code) {
        // Authorization Code Flow - Traditional OAuth
        error_log("Google Callback - Using Authorization Code Flow");

        $tokenUrl = 'https://oauth2.googleapis.com/token';
        $postData = [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => $redirectUri,
            'grant_type' => 'authorization_code'
        ];

        $ch = curl_init($tokenUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        $response = curl_exec($ch);
        $data = json_decode($response, true);
        curl_close($ch);

        if (isset($data['access_token'])) {
            $accessToken = $data['access_token'];

            $userInfoUrl = 'https://www.googleapis.com/oauth2/v3/userinfo';
            $ch = curl_init($userInfoUrl);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            $userInfoResponse = curl_exec($ch);
            $userInfo = json_decode($userInfoResponse, true);
            curl_close($ch);

            $email = $userInfo['email'] ?? null;
            $name = $userInfo['name'] ?? 'Google User';
        }
    }

    if (!$email) {
        die("Lỗi: Không tìm thấy thông tin xác thực từ Google. Vui lòng thử lại.");
    }

    $db = getDB();

    // 2. Check if user exists
    $stmt = $db->prepare("SELECT * FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Auto-Register new user
        $password = bin2hex(random_bytes(8));
        $passwordHash = password_hash($password, PASSWORD_DEFAULT);

        // Fetch trial package from config to get current settings
        $packages = getSubscriptionPackages();
        $trialPlan = 'trial';
        $package = $packages[$trialPlan] ?? ['quota' => 5000, 'days' => 3];

        $quota = $package['quota'];
        $expiryDate = date('Y-m-d H:i:s', strtotime("+{$package['days']} days"));

        // Lookup referrer by referral code
        $referrerId = null;
        if ($referralCode) {
            $stmtRef = $db->prepare("SELECT id FROM users WHERE referral_code = ?");
            $stmtRef->execute([$referralCode]);
            $referrerId = $stmtRef->fetchColumn() ?: null;
        }

        // Generate own referral code
        $ownRefCode = 'U' . uniqid();

        $stmt = $db->prepare("INSERT INTO users (email, password_hash, plan, quota_total, quota_used, expires_at, status, referral_code, referrer_id) VALUES (?, ?, ?, ?, 0, ?, 'active', ?, ?)");
        $stmt->execute([$email, $passwordHash, $trialPlan, $quota, $expiryDate, $ownRefCode, $referrerId]);

        $userId = $db->lastInsertId();
    } else {
        $userId = $user['id'];
    }

    // 3. Generate our JWT Token
    $token = generateToken($userId, $email);

    ?>
    <!DOCTYPE html>
    <html>

    <head>
        <title>Authenticating...</title>
    </head>

    <body>
        <p>Đang chuyển hướng về ứng dụng...</p>
        <script>
            // Store token in BOTH keys to be safe (GIS uses userToken, standard uses auth_token)
            localStorage.setItem('userToken', '<?php echo $token; ?>');
            localStorage.setItem('auth_token', '<?php echo $token; ?>');
            localStorage.setItem('user_email', '<?php echo strtolower($email); ?>');

            // Link referrer if referral code exists in localStorage
            (async function () {
                const refCode = localStorage.getItem('referral_code');
                if (refCode) {
                    try {
                        await fetch('/api/link_referrer.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Authorization': 'Bearer ' + '<?php echo $token; ?>' },
                            body: JSON.stringify({ referral_code: refCode })
                        });
                        localStorage.removeItem('referral_code');
                    } catch (e) { console.error(e); }
                }
                window.location.href = '../../../customer';
            })();
        </script>
    </body>

    </html>
    <?php

} catch (Exception $e) {
    die("Lỗi hệ thống: " . $e->getMessage());
}
