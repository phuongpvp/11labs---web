<?php
/**
 * Dubbing - Create a new dubbing job (Worker-based)
 * POST: multipart/form-data with file, source_lang, target_lang
 * File is saved to uploads/ and job is created as 'pending' for worker pickup
 */
ini_set('upload_max_filesize', '500M');
ini_set('post_max_size', '512M');
ini_set('max_execution_time', '300');
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Auth
$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
$token = str_replace('Bearer ', '', $auth);
$userData = verifyToken($token);

if (!$userData) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$userId = $userData['user_id'];
$sourceLang = $_POST['source_lang'] ?? 'auto';
$targetLang = $_POST['target_lang'] ?? '';
$name = trim($_POST['name'] ?? 'Dubbing Job');
$sourceUrl = trim($_POST['source_url'] ?? '');

if (!$targetLang) {
    jsonResponse(['error' => 'Vui lòng chọn ngôn ngữ đích'], 400);
}

// Check if file or URL provided
$hasFile = isset($_FILES['file']) && $_FILES['file']['error'] === UPLOAD_ERR_OK;
if (!$hasFile && !$sourceUrl) {
    jsonResponse(['error' => 'Vui lòng upload file hoặc nhập URL'], 400);
}

// File validation
if ($hasFile) {
    $file = $_FILES['file'];
    $maxSize = 500 * 1024 * 1024; // 500MB
    if ($file['size'] > $maxSize) {
        jsonResponse(['error' => 'File quá lớn. Tối đa 500MB'], 400);
    }
    $allowedTypes = ['audio/', 'video/'];
    $isAllowed = false;
    foreach ($allowedTypes as $type) {
        if (strpos($file['type'], $type) === 0) {
            $isAllowed = true;
            break;
        }
    }
    if (!$isAllowed) {
        jsonResponse(['error' => 'Chỉ chấp nhận file audio hoặc video'], 400);
    }
}

// Pre-charge (3200 điểm/phút)
$CREDITS_PER_MINUTE = 3200;
$targetLang = $_POST['target_lang'] ?? '';
$MAX_DURATION_SEC = ($targetLang === 'vi') ? 180 : 600; // Tiếng Việt: 3 phút, khác: 10 phút
$clientDuration = floatval($_POST['duration'] ?? 0);

$maxLabel = ($targetLang === 'vi') ? '3 phút (tiếng Việt)' : '10 phút';
if ($clientDuration > $MAX_DURATION_SEC) {
    jsonResponse(['error' => "File quá dài. Tối đa $maxLabel (" . round($clientDuration / 60, 1) . " phút)"], 400);
}

$estimatedMinutes = ($clientDuration > 0) ? ($clientDuration / 60) : 1;
$pointsNeeded = max($CREDITS_PER_MINUTE, round($estimatedMinutes * $CREDITS_PER_MINUTE));

// Generate job ID
function generateDubJobId()
{
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $id = 'DB';
    for ($i = 0; $i < 6; $i++) {
        $id .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $id;
}

try {
    $db = getDB();

    // Create dubbing_jobs table
    $db->exec("CREATE TABLE IF NOT EXISTS dubbing_jobs (
        id VARCHAR(50) PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(255) DEFAULT '',
        source_lang VARCHAR(10) DEFAULT 'auto',
        target_lang VARCHAR(10) NOT NULL,
        source_url TEXT DEFAULT NULL,
        original_filename VARCHAR(255) DEFAULT NULL,
        source_file VARCHAR(255) DEFAULT NULL,
        dubbing_id VARCHAR(100) DEFAULT NULL,
        result_file VARCHAR(255) DEFAULT NULL,
        points_used INT DEFAULT 0,
        estimated_duration_sec DECIMAL(10,2) DEFAULT 0,
        worker_uuid VARCHAR(64) DEFAULT NULL,
        status ENUM('pending','processing','dubbed','failed') DEFAULT 'pending',
        error_message TEXT DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_user (user_id),
        INDEX idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Ensure new columns exist
    try {
        $db->exec("ALTER TABLE dubbing_jobs ADD COLUMN source_file VARCHAR(255) DEFAULT NULL AFTER original_filename");
    } catch (Exception $e) {
    }
    try {
        $db->exec("ALTER TABLE dubbing_jobs ADD COLUMN worker_uuid VARCHAR(64) DEFAULT NULL AFTER estimated_duration_sec");
    } catch (Exception $e) {
    }

    // Check user quota (pre-charge minimum)
    $db->beginTransaction();

    $stmtUser = $db->prepare("SELECT quota_used, quota_total FROM users WHERE id = ? FOR UPDATE");
    $stmtUser->execute([$userId]);
    $user = $stmtUser->fetch();

    if (!$user) {
        $db->rollBack();
        jsonResponse(['error' => 'User not found'], 404);
    }

    $available = $user['quota_total'] - $user['quota_used'];
    if ($available < $pointsNeeded) {
        $db->rollBack();
        jsonResponse([
            'error' => 'Không đủ quota. Cần tối thiểu ' . number_format($pointsNeeded) . ' ký tự, còn ' . number_format($available),
            'needed' => $pointsNeeded,
            'available' => $available
        ], 402);
    }

    // Deduct estimated points (worker will adjust later based on actual duration)
    $db->prepare("UPDATE users SET quota_used = quota_used + ? WHERE id = ?")->execute([$pointsNeeded, $userId]);

    // Generate unique job ID
    do {
        $jobId = generateDubJobId();
        $check = $db->prepare("SELECT COUNT(*) FROM dubbing_jobs WHERE id = ?");
        $check->execute([$jobId]);
    } while ($check->fetchColumn() > 0);

    $originalFilename = $hasFile ? $file['name'] : basename(parse_url($sourceUrl, PHP_URL_PATH));

    // Save uploaded file for worker to download later
    $sourceFile = null;
    if ($hasFile) {
        $uploadDir = __DIR__ . '/../results/dubbing/uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) ?: 'mp3';
        $sourceFile = $jobId . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], $uploadDir . $sourceFile)) {
            $db->rollBack();
            jsonResponse(['error' => 'Không thể lưu file. Vui lòng thử lại.'], 500);
        }
    }

    // Create job as PENDING — worker will pick it up
    $db->prepare("INSERT INTO dubbing_jobs (id, user_id, name, source_lang, target_lang, source_url, original_filename, source_file, points_used, estimated_duration_sec, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')")
        ->execute([$jobId, $userId, $name, $sourceLang, $targetLang, $sourceUrl ?: null, $originalFilename, $sourceFile, $pointsNeeded, $clientDuration]);

    $db->commit();

    // Log to worker_logs for live server panel
    try {
        $db->prepare("INSERT INTO worker_logs (worker_uuid, worker_name, job_id, message, level) VALUES (?, ?, ?, ?, ?)")
            ->execute(['DUBBING', 'Hàng chờ', $jobId, "Tạo job lồng tiếng: $originalFilename ($sourceLang → $targetLang). Đang chờ worker xử lý...", 'info']);
    } catch (Exception $e) {
    }

    jsonResponse([
        'status' => 'success',
        'job_id' => $jobId,
        'points_used' => $pointsNeeded,
        'message' => 'Job đã được tạo, đang chờ worker xử lý'
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction())
        $db->rollBack();
    jsonResponse(['error' => $e->getMessage()], 500);
}
