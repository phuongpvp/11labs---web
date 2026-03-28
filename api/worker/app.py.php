<?php
// Serve the latest app.py file for Colab workers (encoded + secret protected)
require_once __DIR__ . '/../config.php';

// Check secret
$provided = $_GET['s'] ?? '';
if (!verifyWorkerSecret($provided)) {
    http_response_code(403);
    echo "Access Denied";
    exit;
}

$appFile = __DIR__ . '/app.py';

if (!file_exists($appFile)) {
    http_response_code(404);
    echo "app.py not found on server";
    exit;
}

// Read and filter out Colab-only lines (lines starting with !)
$lines = file($appFile);
$filtered = [];
foreach ($lines as $line) {
    if (preg_match('/^\s*!/', $line))
        continue; // Skip !pip, !apt, etc.
    $filtered[] = $line;
}
$code = implode('', $filtered);

// Compress + base64 encode
$compressed = gzcompress($code, 9);
$encoded = base64_encode($compressed);

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Return a self-decoding Python snippet
echo "import base64,zlib;exec(zlib.decompress(base64.b64decode('" . $encoded . "')))";
