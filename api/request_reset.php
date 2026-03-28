<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/mail_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$email = $input['email'] ?? '';

if (!$email) {
    jsonResponse(['error' => 'Vui lòng nhập Email'], 400);
}

try {
    $db = getDB();

    // Check user exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Don't leak user existence? Actually for our case better be clear or use generic message.
        // Let's use generic message.
        jsonResponse(['status' => 'success', 'message' => 'Nếu email tồn tại trong hệ thống, bạn sẽ nhận được hướng dẫn đặt lại mật khẩu trong giây lát.']);
    }

    // Generate token
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));

    // Save to DB
    $stmt = $db->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?");
    $stmt->execute([$token, $expires, $user['id']]);

    // Send Mail
    $resetLink = PHP_BACKEND_URL . "/reset.html?token=" . $token;
    $subject = "[11LABS] Đặt lại mật khẩu của bạn";
    $body = "
        <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; border: 1px solid #e0e0e0; border-radius: 10px;'>
            <h2 style='color: #8b5cf6; text-align: center;'>11LABS - Reset Password</h2>
            <p>Xin chào,</p>
            <p>Chúng tôi nhận được yêu cầu đặt lại mật khẩu cho tài khoản 11LABS gắn với email <b>$email</b>.</p>
            <p>Vui lòng nhấn vào nút bên dưới để đặt lại mật khẩu của bạn (Liên kết này có hiệu lực trong 60 phút):</p>
            <div style='text-align: center; margin: 30px 0;'>
                <a href='$resetLink' style='background: #8b5cf6; color: white; padding: 12px 25px; text-decoration: none; border-radius: 8px; font-weight: bold;'>ĐẶT LẠI MẬT KHẨU</a>
            </div>
            <p>Nếu bạn không gửi yêu cầu này, vui lòng bỏ qua email này hoặc liên kết bộ phận hỗ trợ nếu bạn thấy có dấu hiệu lạ.</p>
            <hr style='border: 0; border-top: 1px solid #eee; margin: 20px 0;'>
            <p style='font-size: 12px; color: #888; text-align: center;'>© 2026 11LABS.ID.VN - All Rights Reserved.</p>
        </div>
    ";

    $mailResult = sendMail($email, $subject, $body);

    if ($mailResult['success']) {
        jsonResponse(['status' => 'success', 'message' => 'Nếu email tồn tại trong hệ thống, bạn sẽ nhận được hướng dẫn đặt lại mật khẩu trong giây lát.']);
    } else {
        // Fallback to Telegram if Log Mail fails? 
        jsonResponse(['error' => 'Lỗi khi gửi email. Vui lòng liên hệ Admin.'], 500);
    }

} catch (Exception $e) {
    jsonResponse(['error' => 'Lỗi hệ thống: ' . $e->getMessage()], 500);
}
?>