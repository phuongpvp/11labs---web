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
$email = $input['email'] ?? '';
$password = $input['password'] ?? '';
$plan = $input['plan'] ?? 'basic';
$quotaTotal = $input['quota_total'] ?? 10000;
$days = $input['days'] ?? 30;

if (!$adminPassword || !$email || !$password) {
    jsonResponse(['error' => 'Admin password, email, and password required'], 400);
}

// Verify admin
if (!verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Invalid admin password'], 403);
}

try {
    $db = getDB();

    // Check if user exists
    $stmt = $db->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'User already exists'], 409);
    }

    // Hash password
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    // Calculate expiry
    $expiresAt = date('Y-m-d H:i:s', strtotime("+{$days} days"));

    // Insert user
    $stmt = $db->prepare("INSERT INTO users (email, password_hash, plan, quota_total, quota_used, expires_at, status) VALUES (?, ?, ?, ?, 0, ?, 'active')");
    $stmt->execute([$email, $passwordHash, $plan, $quotaTotal, $expiresAt]);

    $userId = $db->lastInsertId();

    require_once __DIR__ . '/../mail_helper.php';
    sendWelcomeEmail($email, $password, $plan, $quotaTotal, $expiresAt);

    jsonResponse([
        'status' => 'success',
        'message' => 'User created successfully',
        'user' => [
            'id' => $userId,
            'email' => $email,
            'plan' => $plan,
            'quota_total' => $quotaTotal,
            'expires_at' => $expiresAt
        ]
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
?>