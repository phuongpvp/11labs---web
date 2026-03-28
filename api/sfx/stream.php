<?php
// Serve SFX files with proper headers for browser audio playback
// Usage: api/sfx/stream.php?f=sfx_XXXX_123456.mp3

$filename = basename($_GET['f'] ?? '');
if (!$filename || !preg_match('/^sfx_[A-Za-z0-9_]+\.mp3$/', $filename)) {
    http_response_code(400);
    echo "Invalid filename";
    exit;
}

$filepath = __DIR__ . '/../results/sfx/' . $filename;
if (!file_exists($filepath)) {
    http_response_code(404);
    echo "File not found";
    exit;
}

$filesize = filesize($filepath);
$mime = 'audio/mpeg';

// Support byte-range requests (required for audio seeking in browsers)
if (isset($_SERVER['HTTP_RANGE'])) {
    preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $matches);
    $start = intval($matches[1]);
    $end = $matches[2] !== '' ? intval($matches[2]) : $filesize - 1;

    if ($start > $end || $start >= $filesize) {
        http_response_code(416);
        header("Content-Range: bytes */$filesize");
        exit;
    }

    $length = $end - $start + 1;

    http_response_code(206);
    header("Content-Range: bytes $start-$end/$filesize");
    header("Content-Length: $length");
    header("Content-Type: $mime");
    header("Accept-Ranges: bytes");
    header("Cache-Control: public, max-age=3600");

    $fp = fopen($filepath, 'rb');
    fseek($fp, $start);
    echo fread($fp, $length);
    fclose($fp);
} else {
    // Full file request
    header("Content-Type: $mime");
    header("Content-Length: $filesize");
    header("Accept-Ranges: bytes");
    header("Cache-Control: public, max-age=3600");
    readfile($filepath);
}
