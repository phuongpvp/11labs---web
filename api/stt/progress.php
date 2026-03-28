<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// ── GET: Customer polling for job status ──
if ($method === 'GET') {
    $jobId = $_GET['job_id'] ?? '';
    if (!$jobId)
        jsonResponse(['error' => 'Missing job_id'], 400);

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT status, source_file, original_filename, duration_seconds, result_text, result_srt, language_code, points_used, error_message FROM stt_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();

        if (!$job)
            jsonResponse(['error' => 'Job not found'], 404);

        jsonResponse(['status' => 'success', 'job' => $job]);
    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

// ── POST: Worker actions ──
if ($method === 'POST') {
    $action = $input['action'] ?? '';
    $workerSecret = $input['secret'] ?? '';

    if (!verifyWorkerSecret($workerSecret)) {
        jsonResponse(['error' => 'Unauthorized worker'], 403);
    }

    try {
        $db = getDB();

        // Ensure table exists
        $db->exec("CREATE TABLE IF NOT EXISTS stt_jobs (
            id VARCHAR(50) PRIMARY KEY,
            user_id INT NOT NULL,
            source_file VARCHAR(255) NOT NULL,
            original_filename VARCHAR(255) DEFAULT NULL,
            duration_seconds DECIMAL(10,2) DEFAULT 0,
            result_text LONGTEXT DEFAULT NULL,
            result_srt LONGTEXT DEFAULT NULL,
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

        switch ($action) {

            case 'get_pending':
                $sttVersion = $input['stt_version'] ?? '';
                if ($sttVersion !== 'v1') {
                    jsonResponse(['status' => 'success', 'jobs' => []]);
                }
                $stmt = $db->query("SELECT id, source_file, original_filename, duration_seconds FROM stt_jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT 3");
                jsonResponse(['status' => 'success', 'jobs' => $stmt->fetchAll()]);
                break;

            case 'reset_stuck':
                $db->exec("UPDATE stt_jobs SET status = 'pending', worker_uuid = NULL, updated_at = NOW() WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
                $count = $db->query("SELECT ROW_COUNT()")->fetchColumn();
                jsonResponse(['status' => 'success', 'reset_count' => intval($count)]);
                break;

            case 'get_keys':
                $stmt = $db->query("SELECT id, key_encrypted as 'key', credits_remaining as credits FROM api_keys WHERE status = 'active' AND (cooldown_until IS NULL OR cooldown_until < NOW()) ORDER BY credits_remaining DESC");
                jsonResponse(['status' => 'success', 'keys' => $stmt->fetchAll()]);
                break;

            case 'start':
                $jobId = $input['job_id'] ?? '';
                $workerUuid = $input['worker_uuid'] ?? '';
                $db->prepare("UPDATE stt_jobs SET status = 'processing', worker_uuid = ?, updated_at = NOW() WHERE id = ? AND status = 'pending'")->execute([$workerUuid, $jobId]);
                $acquired = intval($db->query("SELECT ROW_COUNT()")->fetchColumn()) > 0;
                jsonResponse(['status' => 'success', 'acquired' => $acquired]);
                break;

            case 'complete':
                $jobId = $input['job_id'] ?? '';
                $resultText = $input['result_text'] ?? '';
                $resultSrt = $input['result_srt'] ?? '';
                $languageCode = $input['language_code'] ?? null;
                $apiKeyId = $input['api_key_id'] ?? null;
                // Add result_srt column if missing (for existing DBs)
                try {
                    $db->exec("ALTER TABLE stt_jobs ADD COLUMN result_srt LONGTEXT DEFAULT NULL AFTER result_text");
                } catch (Exception $e) { /* Ignore if exists */
                }
                $db->prepare("UPDATE stt_jobs SET status = 'completed', result_text = ?, result_srt = ?, language_code = ?, api_key_id = ?, updated_at = NOW() WHERE id = ?")->execute([$resultText, $resultSrt, $languageCode, $apiKeyId, $jobId]);
                jsonResponse(['status' => 'success']);
                break;

            case 'fail':
                $jobId = $input['job_id'] ?? '';
                $error = substr($input['error'] ?? 'Unknown error', 0, 500);
                // Refund points
                $db->exec("UPDATE users u JOIN stt_jobs j ON j.user_id = u.id SET u.quota_used = GREATEST(0, u.quota_used - j.points_used) WHERE j.id = '$jobId' AND j.status IN ('pending','processing')");
                $db->prepare("UPDATE stt_jobs SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?")->execute([$error, $jobId]);
                jsonResponse(['status' => 'success']);
                break;

            default:
                jsonResponse(['error' => 'Invalid action'], 400);
        }
    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}
