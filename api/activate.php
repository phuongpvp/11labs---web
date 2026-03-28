<?php
require_once __DIR__ . '/config.php';

$token = $_GET['token'] ?? '';

if (!$token) {
    die("<h1>Lỗi:</h1><p>Mã kích hoạt không hợp lệ.</p>");
}

try {
    $db = getDB();

    // 1. Find the token
    $stmt = $db->prepare("SELECT * FROM activation_tokens WHERE token = ?");
    $stmt->execute([$token]);
    $tokenData = $stmt->fetch();

    if (!$tokenData) {
        die("<h1>Lỗi:</h1><p>Mã kích hoạt không tồn tại hoặc đã hết hạn.</p>");
    }

    // 2. Check expiry
    if (strtotime($tokenData['expires_at']) < time()) {
        // Cleanup expired token
        $db->prepare("DELETE FROM activation_tokens WHERE id = ?")->execute([$tokenData['id']]);
        die("<h1>Lỗi:</h1><p>Mã kích hoạt đã hết hạn (quá 24 giờ). Vui lòng đăng ký lại.</p>");
    }

    $userId = $tokenData['user_id'];

    // 3. Activate user
    $db->beginTransaction();

    $stmtUpdate = $db->prepare("UPDATE users SET status = 'active' WHERE id = ?");
    $stmtUpdate->execute([$userId]);

    // 4. Delete the token (used)
    $stmtDelete = $db->prepare("DELETE FROM activation_tokens WHERE user_id = ?");
    $stmtDelete->execute([$userId]);

    $db->commit();

    // Redirect to login with success
    header("Location: " . PHP_BACKEND_URL . "/login.html?activated=1");
    exit();

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction())
        $db->rollBack();
    die("<h1>Lỗi hệ thống:</h1><p>" . $e->getMessage() . "</p>");
}
?>