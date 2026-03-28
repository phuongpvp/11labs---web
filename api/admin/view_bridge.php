<?php
require_once __DIR__ . '/../config.php';
$adminPassword = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
if (!verifyAdminPassword($adminPassword)) {
    die("Access denied");
}
$logFile = __DIR__ . '/../logs/admin_actions.log';
if (file_exists($logFile)) {
    echo nl2br(file_get_contents($logFile));
} else {
    echo "Log file not found";
}
