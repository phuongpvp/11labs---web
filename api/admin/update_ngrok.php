<?php
require_once __DIR__ . '/../config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$adminPassword = $input['admin_password'] ?? '';
$ngrokUrl = $input['ngrok_url'] ?? '';

if (!$adminPassword) {
    jsonResponse(['error' => 'Admin password required'], 400);
}

// Verify admin
if (!verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Invalid admin password'], 403);
}

if (!$ngrokUrl) {
    jsonResponse(['error' => 'Ngrok URL required'], 400);
}

// Validate URL format (basic check)
if (!filter_var($ngrokUrl, FILTER_VALIDATE_URL)) {
    jsonResponse(['error' => 'Invalid URL format'], 400);
}

// Remove trailing slash if present
$ngrokUrl = rtrim($ngrokUrl, '/');

try {
    if (updateNgrokUrl($ngrokUrl)) {
        jsonResponse(['status' => 'success', 'ngrok_url' => $ngrokUrl]);
    } else {
        jsonResponse(['error' => 'Failed to update URL in database'], 500);
    }
} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
?>