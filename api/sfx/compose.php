<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
$token = str_replace('Bearer ', '', $auth);
$userData = verifyToken($token);

if (!$userData) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$userId = $userData['user_id'];
$input = json_decode(file_get_contents('php://input'), true);
$prompt = trim($input['prompt'] ?? '');
$duration = floatval($input['duration'] ?? 0); // seconds, 0 = auto
$loop = (bool) ($input['loop'] ?? false);
$promptInfluence = floatval($input['prompt_influence'] ?? 0.3);

if (!$prompt) {
    jsonResponse(['error' => 'Prompt is required'], 400);
}

// Duration validation: 0 = auto (default 5s for billing), 0.5-30s manual
if ($duration > 0) {
    if ($duration < 0.5)
        $duration = 0.5;
    if ($duration > 30)
        $duration = 30;
    $billDuration = $duration;
} else {
    // Auto mode: ElevenLabs decides, bill as 5s (200 credits on their side)
    $billDuration = 5;
}

// 1 second = 50 credits (ký tự)
$SFX_CREDITS_PER_SECOND = 50;
$pointsNeeded = (int) ceil($billDuration * $SFX_CREDITS_PER_SECOND);

// Generate short SFX Job ID: SX + 6 random alphanumeric chars
function generateSfxJobId()
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $id = 'SX';
    for ($i = 0; $i < 6; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

try {
    $db = getDB();

    // Ensure sfx_jobs table exists
    $db->exec("CREATE TABLE IF NOT EXISTS sfx_jobs (
        id VARCHAR(50) PRIMARY KEY,
        user_id INT NOT NULL,
        prompt TEXT NOT NULL,
        duration DECIMAL(5,1) DEFAULT 0,
        is_loop TINYINT(1) DEFAULT 0,
        prompt_influence DECIMAL(3,2) DEFAULT 0.30,
        result_file VARCHAR(255) DEFAULT NULL,
        points_used INT DEFAULT 0,
        api_key_id INT DEFAULT NULL,
        status ENUM('pending','processing','completed','failed') DEFAULT 'pending',
        worker_uuid VARCHAR(64) DEFAULT NULL,
        error_message TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->beginTransaction();

    // Check user quota
    $stmt = $db->prepare("SELECT quota_used, quota_total FROM users WHERE id = ? FOR UPDATE");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        $db->rollBack();
        jsonResponse(['error' => 'User not found'], 404);
    }

    $available = $user['quota_total'] - $user['quota_used'];
    if ($available < $pointsNeeded) {
        $db->rollBack();
        jsonResponse([
            'error' => 'Không đủ ký tự. Cần ' . number_format($pointsNeeded) . ', còn ' . number_format($available),
            'needed' => $pointsNeeded,
            'available' => $available
        ], 402);
    }

    // Deduct points
    $db->prepare("UPDATE users SET quota_used = quota_used + ? WHERE id = ?")->execute([$pointsNeeded, $userId]);

    // Create job with unique ID
    do {
        $jobId = generateSfxJobId();
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM sfx_jobs WHERE id = ?");
        $checkStmt->execute([$jobId]);
    } while ($checkStmt->fetchColumn() > 0);

    $db->prepare("INSERT INTO sfx_jobs (id, user_id, prompt, duration, is_loop, prompt_influence, points_used, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')")
        ->execute([$jobId, $userId, $prompt, $duration, $loop ? 1 : 0, $promptInfluence, $pointsNeeded]);

    $db->commit();

    jsonResponse([
        'status' => 'success',
        'job_id' => $jobId,
        'points_used' => $pointsNeeded,
        'duration' => $duration,
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction())
        $db->rollBack();
    jsonResponse(['error' => $e->getMessage()], 500);
}
