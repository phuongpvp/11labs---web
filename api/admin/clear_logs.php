<?php
require_once __DIR__ . '/../config.php';

// Clear all worker logs
try {
    $db = getDB();
    $db->exec("TRUNCATE TABLE worker_logs");
    echo "<h1>✅ Đã dọn sạch bảng Log!</h1>";
    echo "<p>Bây giờ anh hãy quay lại Admin và nhấn F5 nhé. Bảng Log sẽ trắng tinh và không còn hiện lỗi cũ nữa.</p>";
} catch (Exception $e) {
    echo "Lỗi: " . $e->getMessage();
}
