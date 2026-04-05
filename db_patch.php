<?php
require_once __DIR__ . '/api/config.php';

try {
    $db = getDB();
    
    $sql = "CREATE TABLE IF NOT EXISTS voice_changer_jobs (
        id VARCHAR(50) PRIMARY KEY,
        user_id INT NOT NULL,
        voice_id VARCHAR(50) NOT NULL,
        source_file VARCHAR(255) NOT NULL,
        result_file VARCHAR(255) DEFAULT '',
        api_key_ids VARCHAR(255) DEFAULT '',
        duration FLOAT DEFAULT 0,
        points_used INT DEFAULT 0,
        status ENUM('pending', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'pending',
        worker_uuid VARCHAR(100) DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        completed_at TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    
    $db->exec($sql);
    echo "Bảng voice_changer_jobs tạo thành công!";
} catch (Exception $e) {
    echo "Lỗi tạo bảng: " . $e->getMessage();
}
