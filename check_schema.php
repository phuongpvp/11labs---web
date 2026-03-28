<?php
require_once __DIR__ . '/api/config.php';
try {
    $db = getDB();
    $stmt = $db->query("DESCRIBE worker_logs");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($rows, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
