<?php
require_once __DIR__ . '/api/config.php';
try {
    $db = getDB();
    $stmt = $db->query("SELECT COUNT(*) FROM worker_logs");
    echo "Count: " . $stmt->fetchColumn();
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
