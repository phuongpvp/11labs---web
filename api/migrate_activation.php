<?php
require_once __DIR__ . '/config.php';

try {
    $db = getDB();
    $sql = "CREATE TABLE IF NOT EXISTS activation_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        token VARCHAR(255) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at TIMESTAMP NOT NULL,
        INDEX (token),
        INDEX (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

    $db->exec($sql);
    echo "SUCCESS: Table 'activation_tokens' is ready.";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
?>