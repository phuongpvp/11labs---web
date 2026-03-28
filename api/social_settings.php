<?php
require_once __DIR__ . '/config.php';

// Allow public access (CORS handled in jsonResponse)
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    $settings = getSocialSettings();
    jsonResponse(['status' => 'success', 'settings' => $settings]);
} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
?>