<?php
require_once __DIR__ . '/config.php';

echo "<h1>Database Fix Tool</h1>";

try {
    $db = getDB();

    echo "Attempting to modify usage_logs table...<br>";

    // Force the modification
    $db->exec("ALTER TABLE usage_logs MODIFY COLUMN api_key_id INT NULL");

    echo "✅ SUCCESS: api_key_id is now NULLABLE.<br>";

    // Verify
    $stmt = $db->query("DESCRIBE usage_logs");
    $cols = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "<pre>";
    print_r($cols);
    echo "</pre>";

    echo "<hr><a href='../admin.html'>Back to Admin</a>";

} catch (Exception $e) {
    echo "<b style='color:red'>FAILED: " . $e->getMessage() . "</b>";
}
?>