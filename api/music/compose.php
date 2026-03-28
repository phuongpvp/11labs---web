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
$duration = intval($input['duration'] ?? 30); // seconds

if (!$prompt) {
    jsonResponse(['error' => 'Prompt is required'], 400);
}

if ($duration < 5)
    $duration = 5;
if ($duration > 300)
    $duration = 300;

// 1 phút = 1300 ký tự charge
$MUSIC_CREDITS_PER_MINUTE = 1300;
$pointsNeeded = (int) ceil(($duration / 60) * $MUSIC_CREDITS_PER_MINUTE);

// Generate short Music Job ID: MU + 6 random alphanumeric chars
function generateMusicJobId()
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $id = 'MU';
    for ($i = 0; $i < 6; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

try {
    $db = getDB();

    // Ensure music_jobs table exists
    $db->exec("CREATE TABLE IF NOT EXISTS music_jobs (
        id VARCHAR(50) PRIMARY KEY,
        user_id INT NOT NULL,
        prompt TEXT NOT NULL,
        duration INT DEFAULT 30,
        result_file VARCHAR(255) DEFAULT NULL,
        points_used INT DEFAULT 0,
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

    // Create job
    // Generate unique MU-prefix ID, retry if collision
    do {
        $jobId = generateMusicJobId();
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM music_jobs WHERE id = ?");
        $checkStmt->execute([$jobId]);
    } while ($checkStmt->fetchColumn() > 0);
    $db->prepare("INSERT INTO music_jobs (id, user_id, prompt, duration, points_used, status) VALUES (?, ?, ?, ?, ?, 'pending')")
        ->execute([$jobId, $userId, $prompt, $duration, $pointsNeeded]);

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
