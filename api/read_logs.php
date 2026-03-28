<?php
header('Content-Type: text/plain');
$logFile = __DIR__ . '/logs/admin_actions.log';
if (file_exists($logFile)) {
    $lines = file($logFile);
    $lastLines = array_slice($lines, -50);
    echo implode("", $lastLines);
} else {
    echo "Log file not found at $logFile";
}
?>