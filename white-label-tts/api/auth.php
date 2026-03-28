<?php
// =====================================================
// Đăng ký / Đăng nhập khách cuối
// =====================================================
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

$input = getInput();
$action = $input['action'] ?? ($_GET['action'] ?? '');

switch ($action) {
    case 'register':
        handleRegister($input);
        break;
    case 'login':
        handleLogin($input);
        break;
    case 'profile':
        handleProfile();
        break;
    case 'change_password':
        handleChangePassword($input);
        break;
    default:
        jsonResponse(['error' => 'Invalid action. Use: register, login, profile, change_password'], 400);
}

function handleRegister($input)
{
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $name = trim($input['display_name'] ?? '');
    $refCode = trim($input['ref'] ?? '');

    if (!$email || !$password) {
        jsonResponse(['error' => 'Email và mật khẩu là bắt buộc'], 400);
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['error' => 'Email không hợp lệ'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['error' => 'Mật khẩu tối thiểu 6 ký tự'], 400);
    }

    $db = getDB();

    // Check duplicate
    $stmt = $db->prepare("SELECT id FROM customers WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Email đã được đăng ký'], 409);
    }

    // Find referrer by referral code
    $referrerId = null;
    if ($refCode) {
        $stmt = $db->prepare("SELECT id FROM customers WHERE referral_code = ?");
        $stmt->execute([$refCode]);
        $referrer = $stmt->fetch();
        if ($referrer) {
            $referrerId = $referrer['id'];
        }
    }

    // Create account with referral code
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $token = bin2hex(random_bytes(32));
    $newRefCode = 'R' . strtoupper(bin2hex(random_bytes(4)));

    $stmt = $db->prepare("INSERT INTO customers (email, password_hash, display_name, auth_token, referral_code, referred_by) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$email, $hash, $name ?: explode('@', $email)[0], $token, $newRefCode, $referrerId]);

    jsonResponse([
        'status' => 'success',
        'message' => 'Đăng ký thành công',
        'token' => $token,
        'user' => [
            'id' => $db->lastInsertId(),
            'email' => $email,
            'display_name' => $name ?: explode('@', $email)[0],
            'quota_allocated' => 0,
            'quota_used' => 0
        ]
    ]);
}

function handleLogin($input)
{
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';

    if (!$email || !$password) {
        jsonResponse(['error' => 'Email và mật khẩu là bắt buộc'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM customers WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonResponse(['error' => 'Email hoặc mật khẩu không đúng'], 401);
    }

    if (!$user['is_active']) {
        jsonResponse(['error' => 'Tài khoản đã bị khóa'], 403);
    }

    // Refresh token
    $token = bin2hex(random_bytes(32));
    $stmt = $db->prepare("UPDATE customers SET auth_token = ? WHERE id = ?");
    $stmt->execute([$token, $user['id']]);

    jsonResponse([
        'status' => 'success',
        'token' => $token,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'display_name' => $user['display_name'],
            'quota_allocated' => (int) $user['quota_allocated'],
            'quota_used' => (int) $user['quota_used'],
            'remaining' => max(0, (int) $user['quota_allocated'] - (int) $user['quota_used'])
        ]
    ]);
}

function handleProfile()
{
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);

    $user = verifyCustomerToken($token);
    if (!$user) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    jsonResponse([
        'status' => 'success',
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'display_name' => $user['display_name'],
            'quota_allocated' => (int) $user['quota_allocated'],
            'quota_used' => (int) $user['quota_used'],
            'remaining' => max(0, (int) $user['quota_allocated'] - (int) $user['quota_used'])
        ]
    ]);
}

function handleChangePassword($input)
{
    $token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    $token = str_replace('Bearer ', '', $token);

    $user = verifyCustomerToken($token);
    if (!$user) {
        jsonResponse(['error' => 'Phiên đăng nhập hết hạn'], 401);
    }

    $oldPassword = $input['old_password'] ?? '';
    $newPassword = $input['new_password'] ?? '';

    if (!$oldPassword || !$newPassword) {
        jsonResponse(['error' => 'Vui lòng nhập đầy đủ mật khẩu cũ và mật khẩu mới'], 400);
    }

    if (strlen($newPassword) < 6) {
        jsonResponse(['error' => 'Mật khẩu mới phải có ít nhất 6 ký tự'], 400);
    }

    if (!password_verify($oldPassword, $user['password_hash'])) {
        jsonResponse(['error' => 'Mật khẩu cũ không chính xác'], 400);
    }

    $db = getDB();
    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $stmt = $db->prepare("UPDATE customers SET password_hash = ? WHERE id = ?");
    $stmt->execute([$newHash, $user['id']]);

    jsonResponse(['status' => 'success', 'message' => 'Đổi mật khẩu thành công']);
}
