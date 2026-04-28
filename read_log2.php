<?php
$file = __DIR__ . '/api/logs/error_' . date('Y-m-d') . '.log';
if (file_exists($file)) {
    echo nl2br(file_get_contents($file));
} else {
    echo "No log found for today.";
}
