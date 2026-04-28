<?php
require __DIR__ . '/api/config.php';
$w = getActiveWorker();
if ($w) {
    echo "Found active worker: " . $w['url'];
} else {
    echo "NO ACTIVE WORKER FOUND!";
}
