<?php
require_once __DIR__ . '/config.php';

try {
    $db = getDB();
    $db->exec("ALTER TABLE api_keys ADD COLUMN cooldown_until TIMESTAMP NULL DEFAULT NULL");
    echo "Column 'cooldown_until' added successfully.";
} catch (Exception $e) {
    echo "Error or column already exists: " . $e->getMessage();
}
