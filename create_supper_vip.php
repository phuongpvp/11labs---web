<?php
require_once __DIR__ . '/api/config.php';

try {
    $db = getDB();

    // Check if Supper VIP already exists
    $stmt = $db->prepare("SELECT id FROM packages WHERE id = 'supper_vip'");
    $stmt->execute();

    if (!$stmt->fetch()) {
        $stmt = $db->prepare("INSERT INTO packages (id, name, price, quota_chars, duration_days, is_active, features) 
                              VALUES ('supper_vip', 'Supper VIP', 500000, 10000000, 9999, 1, '[\"srt_download\"]')");
        $stmt->execute();
        echo "✅ Created 'Supper VIP' package successfully.\n";
    } else {
        echo "ℹ️ 'Supper VIP' package already exists.\n";
    }
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>