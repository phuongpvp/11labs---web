<?php
require_once __DIR__ . '/../config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$adminPassword = $input['admin_password'] ?? '';
$planId = $input['plan_id'] ?? '';
$name = $input['name'] ?? '';
$price = isset($input['price']) ? (int) $input['price'] : 0;
$priceUsd = isset($input['price_usd']) ? (float) $input['price_usd'] : null;
$quota = isset($input['quota']) ? (int) $input['quota'] : 0;
$days = isset($input['days']) ? (int) $input['days'] : 30;

if (!$adminPassword || !$planId || !$name) {
    jsonResponse(['error' => 'Admin password, Plan ID, and Name are required'], 400);
}

// Verify admin
if (!verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Invalid admin password'], 403);
}

try {
    $db = getDB();

    // Check if exists
    $stmt = $db->prepare("SELECT id FROM packages WHERE id = ?");
    $stmt->execute([$planId]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Package ID already exists'], 409);
    }

    // Insert new package
    $stmt = $db->prepare("INSERT INTO packages (id, name, price, price_usd, quota_chars, duration_days, is_active, features) 
                          VALUES (?, ?, ?, ?, ?, ?, 1, '[]')");
    $stmt->execute([$planId, $name, $price, $priceUsd, $quota, $days]);

    jsonResponse([
        'status' => 'success',
        'message' => 'Package created successfully',
        'data' => [
            'id' => $planId,
            'name' => $name,
            'price' => $price,
            'price_usd' => $priceUsd,
            'quota' => $quota,
            'days' => $days
        ]
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
?>