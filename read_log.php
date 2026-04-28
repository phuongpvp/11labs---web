<?php
$file = __DIR__ . '/api/logs/error_' . date('Y-m-d') . '.log';
if (file_exists($file)) {
    $lines = file($file);
    foreach ($lines as $line) {
        if (strpos($line, 'Test voice failed') !== false) {
            echo htmlspecialchars($line) . "<br>";
        }
    }
} else {
    echo "Log file does not exist: $file";
}
