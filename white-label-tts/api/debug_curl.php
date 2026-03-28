<?php
// Debug: Test ALL connection methods
header('Content-Type: application/json');
require_once __DIR__ . '/config.php';

$url = 'https://11labs.id.vn/api/external/v1/user.php?api_key=' . urlencode(PARTNER_API_KEY);
$results = [];

// Test 1: file_get_contents (HTTPS)
$ctx = stream_context_create(['ssl' => ['verify_peer' => false, 'verify_peer_name' => false], 'http' => ['timeout' => 10]]);
$r1 = @file_get_contents($url, false, $ctx);
$results['file_get_contents_https'] = $r1 ? json_decode($r1, true) : 'FAILED: ' . error_get_last()['message'];

// Test 2: file_get_contents (HTTP)
$url2 = str_replace('https://', 'http://', $url);
$r2 = @file_get_contents($url2, false, $ctx);
$results['file_get_contents_http'] = $r2 ? json_decode($r2, true) : 'FAILED: ' . error_get_last()['message'];

// Test 3: Check allow_url_fopen
$results['allow_url_fopen'] = ini_get('allow_url_fopen');

// Test 4: Check if curl exists
$results['curl_exists'] = function_exists('curl_init');

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
