<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../mail_helper.php';

// Increase execution time for long-running scripts
set_time_limit(600);

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$adminPassword = $input['admin_password'] ?? '';
$subject = $input['subject'] ?? '';
$template = $input['template'] ?? 'custom'; // 'activation', 'welcome', 'custom'
$customBody = $input['body'] ?? '';
$target = $input['target'] ?? 'active'; // 'all', 'active', 'expired'

if (!verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

if ($template === 'custom' && (empty($subject) || empty($customBody))) {
    jsonResponse(['error' => 'Subject and Body are required for custom emails'], 400);
}

try {
    $db = getDB();

    // 1. Fetch users
    $sql = "SELECT email, plan, quota_total, expires_at FROM users";
    if ($target === 'active') {
        $sql .= " WHERE status = 'active'";
    } elseif ($target === 'expired') {
        $sql .= " WHERE status = 'expired'";
    }

    $stmt = $db->query($sql);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($users)) {
        jsonResponse(['status' => 'success', 'message' => 'Không tìm thấy người dùng phù hợp.', 'count' => 0]);
    }

    $successCount = 0;
    $failCount = 0;
    $errors = [];

    foreach ($users as $user) {
        $mailResult = null;

        if ($template === 'activation') {
            $mailResult = sendActivationEmail($user['email'], $user['plan'], $user['quota_total'], $user['expires_at']);
        } elseif ($template === 'welcome') {
            // Note: Cannot send welcome email with full password here as it's hashed in DB
            // This is for existing users, so activation template is more appropriate
            $mailResult = sendActivationEmail($user['email'], $user['plan'], $user['quota_total'], $user['expires_at']);
        } else {
            $mailResult = sendMail($user['email'], $subject, $customBody);
        }

        if ($mailResult['success']) {
            $successCount++;
        } else {
            $failCount++;
            $errors[] = $user['email'] . ": " . ($mailResult['error'] ?? 'Unknown error');
        }

        // Add a tiny delay to avoid hitting SMTP rate limits too hard
        usleep(100000); // 100ms
    }

    jsonResponse([
        'status' => 'success',
        'message' => "Đã hoàn thành gửi email.",
        'results' => [
            'total' => count($users),
            'success' => $successCount,
            'failed' => $failCount,
            'errors' => array_slice($errors, 0, 10) // Return first 10 errors if any
        ]
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
