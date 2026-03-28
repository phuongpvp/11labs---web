<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// 1. Verify user token
$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
$token = str_replace('Bearer ', '', $auth);
$userData = verifyToken($token);

if (!$userData) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$userId = $userData['user_id'];

// 2. Check for uploaded file
if (!isset($_FILES['file'])) {
    jsonResponse(['error' => 'No file uploaded'], 400);
}

$file = $_FILES['file'];
$fileName = $file['name'];
$fileTmp = $file['tmp_name'];
$fileSize = $file['size'];
$fileError = $file['error'];

if ($fileError !== 0) {
    jsonResponse(['error' => 'File upload error code: ' . $fileError], 500);
}

// 3. Validate file type
$allowed = ['mp3', 'wav', 'm4a', 'flac', 'mp4', 'mov', 'avi', 'mkv', 'ogg', 'webm', 'aac'];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if (!in_array($ext, $allowed)) {
    jsonResponse(['error' => 'Định dạng không hỗ trợ. Cho phép: ' . implode(', ', $allowed)], 400);
}

// 4. Validate file size (max 500MB for long audio)
if ($fileSize > 500 * 1024 * 1024) {
    jsonResponse(['error' => 'File quá lớn. Tối đa 500MB'], 400);
}

// 5. Get audio duration from client (sent as form field)
$durationSeconds = floatval($_POST['duration'] ?? 0);
if ($durationSeconds <= 0) {
    // Fallback: estimate from file size (rough: ~1MB per minute for MP3)
    $durationSeconds = max(10, ($fileSize / (1024 * 1024)) * 60);
}

// 6. Calculate credits: 180 credits per minute
$STT_CREDITS_PER_MINUTE = 180;
$durationMinutes = $durationSeconds / 60;
$pointsNeeded = (int) ceil($durationMinutes * $STT_CREDITS_PER_MINUTE);

// 7. Save file
$uploadDir = __DIR__ . '/../results/stt/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$newFileName = uniqid('stt_', true) . '.' . $ext;
$destination = $uploadDir . $newFileName;

if (!move_uploaded_file($fileTmp, $destination)) {
    jsonResponse(['error' => 'Failed to save uploaded file'], 500);
}

// Generate short STT Job ID: ST + 6 random alphanumeric chars
function generateSttJobId()
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $id = 'ST';
    for ($i = 0; $i < 6; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

try {
    $db = getDB();

    // Ensure stt_jobs table exists
    $db->exec("CREATE TABLE IF NOT EXISTS stt_jobs (
        id VARCHAR(50) PRIMARY KEY,
        user_id INT NOT NULL,
        source_file VARCHAR(255) NOT NULL,
        original_filename VARCHAR(255) DEFAULT NULL,
        duration_seconds DECIMAL(10,2) DEFAULT 0,
        result_text LONGTEXT DEFAULT NULL,
        language_code VARCHAR(10) DEFAULT NULL,
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
        // Clean up uploaded file
        if (file_exists($destination))
            unlink($destination);
        jsonResponse([
            'error' => 'Không đủ ký tự. Cần ' . number_format($pointsNeeded) . ', còn ' . number_format($available),
            'needed' => $pointsNeeded,
            'available' => $available
        ], 402);
    }

    // Deduct points
    $db->prepare("UPDATE users SET quota_used = quota_used + ? WHERE id = ?")->execute([$pointsNeeded, $userId]);

    // Create job
    do {
        $jobId = generateSttJobId();
        $checkStmt = $db->prepare("SELECT COUNT(*) FROM stt_jobs WHERE id = ?");
        $checkStmt->execute([$jobId]);
    } while ($checkStmt->fetchColumn() > 0);

    $db->prepare("INSERT INTO stt_jobs (id, user_id, source_file, original_filename, duration_seconds, points_used, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')")
        ->execute([$jobId, $userId, $newFileName, $fileName, $durationSeconds, $pointsNeeded]);

    $db->commit();

    jsonResponse([
        'status' => 'success',
        'job_id' => $jobId,
        'points_used' => $pointsNeeded,
        'duration' => $durationSeconds,
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction())
        $db->rollBack();
    jsonResponse(['error' => $e->getMessage()], 500);
}
