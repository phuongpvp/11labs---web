<?php
// =====================================================
// Proxy tải audio kèm CORS header cho white-label partners
// URL: /api/results/download.php?file=JOBID.mp3
// =====================================================

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$file = $_GET['file'] ?? '';

// Security: only allow alphanumeric + underscore + dot, must end with .mp3 or .srt
if (!$file || !preg_match('/^[A-Za-z0-9_\-]+\.(mp3|srt)$/', $file)) {
    http_response_code(400);
    echo 'Invalid file';
    exit;
}

$filePath = __DIR__ . '/' . $file;

if (!file_exists($filePath)) {
    http_response_code(404);
    echo 'File not found';
    exit;
}

$ext = pathinfo($file, PATHINFO_EXTENSION);
if ($ext === 'srt') {
    header('Content-Type: text/plain; charset=utf-8');
} else {
    header('Content-Type: audio/mpeg');
}
header('Content-Length: ' . filesize($filePath));
header('Content-Disposition: attachment; filename="' . $file . '"');

readfile($filePath);
exit;
