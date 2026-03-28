<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../config.php';

$adminPassword = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
if (!verifyAdminPassword($adminPassword)) {
    die(json_encode(['error' => 'Unauthorized']));
}

echo json_encode([
    'php_version' => PHP_VERSION,
    'post_max_size' => ini_get('post_max_size'),
    'upload_max_filesize' => ini_get('upload_max_filesize'),
    'max_execution_time' => ini_get('max_execution_time'),
    'memory_limit' => ini_get('memory_limit'),
    'server_software' => $_SERVER['SERVER_SOFTWARE'],
    'server_time' => date('Y-m-d H:i:s'),
    'log_errors' => ini_get('log_errors'),
    'error_log' => ini_get('error_log')
], JSON_PRETTY_PRINT);
?>