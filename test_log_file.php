<?php
require __DIR__ . '/api/config.php';
logToFile('test_error_2026-04-28.log', 'TEST_LOG_ENTRY');
echo "Logged to: " . __DIR__ . "/api/logs/test_error_2026-04-28.log";
