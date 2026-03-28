<?php
// =====================================================
// Proxy Voices — Lấy danh sách voice từ server chính
// =====================================================
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

// Verify customer (optional - can allow public access)
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);
$customer = verifyCustomerToken($token);

if (!$customer) {
    jsonResponse(['error' => 'Vui lòng đăng nhập'], 401);
}

// Optional filters
$search = $_GET['q'] ?? '';
$language = $_GET['language'] ?? '';

$endpoint = 'voices.php';
$params = [];
if ($search)
    $params[] = "q=" . urlencode($search);
if ($language)
    $params[] = "language=" . urlencode($language);
if ($params)
    $endpoint .= '?' . implode('&', $params);

$result = callExternalAPI($endpoint);

if ($result['code'] === 200) {
    jsonResponse($result['body']);
} else {
    jsonResponse(['error' => 'Không thể tải danh sách voice'], $result['code'] ?: 500);
}
