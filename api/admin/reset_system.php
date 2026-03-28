<?php
require_once __DIR__ . '/../config.php';

// Enable error reporting for debug
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

try {
    $db = getDB();

    echo "<h1>Hệ thống Dọn dẹp & Reset Toàn diện</h1>";

    // 1. Giải phóng toàn bộ Key khỏi máy chủ
    $db->prepare("UPDATE api_keys SET assigned_worker_uuid = NULL")->execute();
    echo "✅ Đã giải phóng toàn bộ API Key (Clear Assignment).<br>";

    // 2. Xóa Cooldown và reset máy chủ
    $db->prepare("UPDATE workers SET cooldown_until = NULL, failed_jobs = 0, capacity_alert_last_sent = NULL")->execute();
    echo "✅ Đã xóa trạng thái Cooldown và báo động của tất cả máy chủ.<br>";

    // 3. Chuẩn hóa điểm (Xóa dấu chấm lọt lưới)
    $db->prepare("UPDATE api_keys SET credits_remaining = REPLACE(credits_remaining, '.', '') WHERE credits_remaining LIKE '%.%'")->execute();
    echo "✅ Đã chuẩn hóa lại định dạng điểm trong Database (Xóa dấu chấm).<br>";

    echo "<br><b style='color:green'>==> HỆ THỐNG ĐÃ SẠCH SẼ!</b><br>";
    echo "Bây giờ anh hãy thử gửi Job lại, hệ thống sẽ phân bổ mới hoàn toàn từ đầu.<br>";
    echo "<hr><a href='../../admin.html'>Quay lại trang Admin</a>";

} catch (Exception $e) {
    echo "<b style='color:red'>LỖI: " . $e->getMessage() . "</b>";
}
