<?php
/**
 * Dubbing Progress - Handle both customer polling (GET) and worker actions (POST)
 */
ini_set('upload_max_filesize', '500M');
ini_set('post_max_size', '512M');
ini_set('max_execution_time', '300');
ini_set('memory_limit', '512M');
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

// =============================================
// 1. GET: Customer polling for job status
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $jobId = $_GET['job_id'] ?? '';
    if (!$jobId) {
        jsonResponse(['error' => 'Missing job_id'], 400);
    }

    try {
        $db = getDB();
        // Ensure progress columns exist
        try {
            $db->exec("ALTER TABLE dubbing_jobs ADD COLUMN progress INT DEFAULT 0");
        } catch (Exception $e) {
        }
        try {
            $db->exec("ALTER TABLE dubbing_jobs ADD COLUMN progress_message VARCHAR(255) DEFAULT ''");
        } catch (Exception $e) {
        }

        $stmt = $db->prepare("SELECT id, name, source_lang, target_lang, original_filename, dubbing_id, result_file, points_used, estimated_duration_sec, status, error_message, progress, progress_message, created_at FROM dubbing_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();

        if (!$job) {
            jsonResponse(['error' => 'Job not found'], 404);
        }

        // Build result URL if completed
        if ($job['status'] === 'dubbed' && $job['result_file']) {
            $job['result_url'] = PHP_BACKEND_URL . '/api/results/dubbing/' . $job['result_file'];
        }

        jsonResponse(['status' => 'success', 'job' => $job]);
    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

// =============================================
// 2. POST: Worker actions (from Colab worker)
// =============================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    // For file uploads, use $_POST
    if (!$input) {
        $input = $_POST;
    }

    $action = $input['action'] ?? '';
    $workerSecret = $input['secret'] ?? '';

    // Security check (cancel uses JWT auth, other actions use worker secret)
    if ($action !== 'cancel' && !verifyWorkerSecret($workerSecret)) {
        jsonResponse(['error' => 'Unauthorized worker'], 403);
    }

    try {
        $db = getDB();

        switch ($action) {
            case 'get_pending':
                $workerUuid = $input['worker_uuid'] ?? '';
                // Auto-create columns if needed
                try {
                    $db->exec("ALTER TABLE dubbing_jobs ADD COLUMN skip_count INT DEFAULT 0");
                } catch (Exception $e) {
                }
                try {
                    $db->exec("ALTER TABLE dubbing_jobs ADD COLUMN skipped_workers TEXT DEFAULT ''");
                } catch (Exception $e) {
                }

                // === AUTO-TIMEOUT: Reset stuck 'processing' jobs (>15 min without update) ===
                try {
                    $stuckJobs = $db->query("SELECT id, user_id, worker_uuid FROM dubbing_jobs WHERE status = 'processing' AND updated_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)")->fetchAll();
                    foreach ($stuckJobs as $stuck) {
                        $db->prepare("UPDATE dubbing_jobs SET status = 'pending', worker_uuid = NULL, progress = 0, progress_message = 'Worker mất kết nối, đang chuyển máy chủ khác...', skip_count = COALESCE(skip_count, 0) + 1, skipped_workers = CONCAT(COALESCE(skipped_workers, ''), ',', ?), updated_at = NOW() WHERE id = ? AND status = 'processing'")->execute([$stuck['worker_uuid'] ?? '', $stuck['id']]);
                        // Log
                        try {
                            $db->prepare("INSERT INTO worker_logs (worker_uuid, worker_name, job_id, message, level) VALUES (?, ?, ?, ?, ?)")
                                ->execute(['SYSTEM', 'Auto-Timeout', $stuck['id'], "Job treo >15 phút, tự reset về pending", 'warning']);
                        } catch (Exception $e) {}
                    }
                } catch (Exception $e) {}

                // Exclude jobs this worker already skipped
                if ($workerUuid) {
                    $stmt = $db->prepare("SELECT id, source_lang, target_lang, source_file, source_url, original_filename FROM dubbing_jobs WHERE status = 'pending' AND (skipped_workers IS NULL OR skipped_workers = '' OR skipped_workers NOT LIKE ?) ORDER BY created_at ASC LIMIT 5");
                    $stmt->execute(["%$workerUuid%"]);
                } else {
                    $stmt = $db->query("SELECT id, source_lang, target_lang, source_file, source_url, original_filename FROM dubbing_jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT 5");
                }
                jsonResponse(['status' => 'success', 'jobs' => $stmt->fetchAll()]);
                break;

            case 'start':
                $jobId = $input['job_id'] ?? '';
                $workerUuid = $input['worker_uuid'] ?? '';
                // Atomic claim
                $stmt = $db->prepare("UPDATE dubbing_jobs SET status = 'processing', worker_uuid = ?, updated_at = NOW() WHERE id = ? AND status = 'pending'");
                $stmt->execute([$workerUuid, $jobId]);
                $claimed = $stmt->rowCount() > 0;
                jsonResponse(['status' => 'success', 'claimed' => $claimed]);
                break;

            case 'get_keys':
                $workerUuid = $input['worker_uuid'] ?? '';
                $keys = [];

                // First try: get keys assigned to this worker
                if ($workerUuid) {
                    $stmt = $db->prepare("SELECT id, key_encrypted as 'key', fb_token, fb_token_expires, fb_refresh_token, credits_remaining as credits FROM api_keys WHERE status = 'active' AND assigned_worker_uuid = ? AND (cooldown_until IS NULL OR cooldown_until < NOW()) ORDER BY credits_remaining DESC");
                    $stmt->execute([$workerUuid]);
                    $keys = $stmt->fetchAll();
                }

                // Fallback: if no keys assigned to this worker, get all unassigned active keys
                if (empty($keys)) {
                    $stmt = $db->query("SELECT id, key_encrypted as 'key', fb_token, fb_token_expires, fb_refresh_token, credits_remaining as credits FROM api_keys WHERE status = 'active' AND (assigned_worker_uuid IS NULL OR assigned_worker_uuid = '') AND (cooldown_until IS NULL OR cooldown_until < NOW()) ORDER BY credits_remaining DESC");
                    $keys = $stmt->fetchAll();
                }

                jsonResponse(['status' => 'success', 'keys' => $keys]);
                break;

            case 'charge':
                // Worker calls this after measuring actual audio duration
                $jobId = $input['job_id'] ?? '';
                $duration = floatval($input['duration'] ?? 0);
                $CREDITS_PER_MINUTE = 3200;
                // Tính theo tỷ lệ thực tế, tối thiểu 1 phút
                $pointsNeeded = max($CREDITS_PER_MINUTE, round(($duration / 60) * $CREDITS_PER_MINUTE));

                $db->beginTransaction();
                $stmt = $db->prepare("SELECT j.user_id, j.points_used, u.quota_used, u.quota_total FROM dubbing_jobs j JOIN users u ON j.user_id = u.id WHERE j.id = ? FOR UPDATE");
                $stmt->execute([$jobId]);
                $data = $stmt->fetch();

                if (!$data) {
                    $db->rollBack();
                    jsonResponse(['error' => 'Job not found'], 404);
                }

                $alreadyCharged = intval($data['points_used']);
                $diff = $pointsNeeded - $alreadyCharged;

                if ($diff > 0) {
                    // Need to charge more
                    $available = $data['quota_total'] - $data['quota_used'];
                    if ($available < $diff) {
                        $db->rollBack();
                        jsonResponse(['error' => 'Insufficient points', 'needed' => $pointsNeeded, 'available' => $available + $alreadyCharged]);
                    }
                    $db->prepare("UPDATE users SET quota_used = quota_used + ? WHERE id = ?")->execute([$diff, $data['user_id']]);
                } elseif ($diff < 0) {
                    // Overcharged - refund
                    $db->prepare("UPDATE users SET quota_used = GREATEST(0, quota_used - ?) WHERE id = ?")->execute([abs($diff), $data['user_id']]);
                }

                $db->prepare("UPDATE dubbing_jobs SET points_used = ?, estimated_duration_sec = ? WHERE id = ?")->execute([$pointsNeeded, $duration, $jobId]);
                $db->commit();

                jsonResponse(['status' => 'success', 'points_charged' => $pointsNeeded]);
                break;

            case 'complete':
                $jobId = $input['job_id'] ?? '';
                $resultFile = $input['result_file'] ?? '';

                $stmt = $db->prepare("UPDATE dubbing_jobs SET status = 'dubbed', result_file = ?, updated_at = NOW() WHERE id = ?");
                $stmt->execute([$resultFile, $jobId]);

                // Log completion
                try {
                    $job = $db->prepare("SELECT original_filename, source_lang, target_lang FROM dubbing_jobs WHERE id = ?")->fetch();
                    $db->prepare("INSERT INTO worker_logs (worker_uuid, worker_name, job_id, message, level) VALUES (?, ?, ?, ?, ?)")
                        ->execute(['DUBBING', 'Worker', $jobId, "Hoàn thành lồng tiếng: " . ($job['original_filename'] ?? $jobId), 'success']);
                } catch (Exception $e) {
                }

                jsonResponse(['status' => 'success']);
                break;

            case 'fail':
                $jobId = $input['job_id'] ?? '';
                $error = $input['error'] ?? 'Unknown error';

                // Refund points atomically
                $db->beginTransaction();
                $stmt = $db->prepare("SELECT user_id, points_used, status FROM dubbing_jobs WHERE id = ? FOR UPDATE");
                $stmt->execute([$jobId]);
                $job = $stmt->fetch();

                if ($job && in_array($job['status'], ['pending', 'processing'])) {
                    $refund = intval($job['points_used']);
                    if ($refund > 0) {
                        $db->prepare("UPDATE users SET quota_used = GREATEST(0, quota_used - ?) WHERE id = ?")->execute([$refund, $job['user_id']]);
                    }
                }
                $db->prepare("UPDATE dubbing_jobs SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?")->execute([$error, $jobId]);
                $db->commit();

                jsonResponse(['status' => 'success']);
                break;

            case 'release':
                // Worker releases job back to pending (e.g. not enough credits)
                $jobId = $input['job_id'] ?? '';
                $reason = $input['reason'] ?? 'Released by worker';
                $workerUuid = $input['worker_uuid'] ?? '';

                // Track skip count to avoid infinite loop
                try {
                    $db->exec("ALTER TABLE dubbing_jobs ADD COLUMN skip_count INT DEFAULT 0");
                } catch (Exception $e) {
                }
                try {
                    $db->exec("ALTER TABLE dubbing_jobs ADD COLUMN skipped_workers TEXT DEFAULT ''");
                } catch (Exception $e) {
                }

                $stmt = $db->prepare("SELECT skip_count, skipped_workers FROM dubbing_jobs WHERE id = ?");
                $stmt->execute([$jobId]);
                $job = $stmt->fetch();

                $skipCount = intval($job['skip_count'] ?? 0) + 1;
                $skippedWorkers = trim(($job['skipped_workers'] ?? '') . ',' . $workerUuid, ',');

                if ($skipCount >= 5) {
                    // Too many skips → fail the job
                    $db->prepare("UPDATE dubbing_jobs SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?")->execute([$reason, $jobId]);
                    // Refund
                    $stmtJ = $db->prepare("SELECT user_id, points_used FROM dubbing_jobs WHERE id = ?");
                    $stmtJ->execute([$jobId]);
                    $jData = $stmtJ->fetch();
                    if ($jData && intval($jData['points_used']) > 0) {
                        $db->prepare("UPDATE users SET quota_used = GREATEST(0, quota_used - ?) WHERE id = ?")->execute([intval($jData['points_used']), $jData['user_id']]);
                    }
                } else {
                    // Release back to pending
                    $db->prepare("UPDATE dubbing_jobs SET status = 'pending', worker_uuid = NULL, skip_count = ?, skipped_workers = ?, progress = 0, progress_message = ?, updated_at = NOW() WHERE id = ?")
                        ->execute([$skipCount, $skippedWorkers, "Đang xử lý, vui lòng chờ...", $jobId]);
                }

                jsonResponse(['status' => 'success', 'skip_count' => $skipCount]);
                break;

            case 'update_progress':
                $jobId = $input['job_id'] ?? '';
                $progress = intval($input['progress'] ?? 0);
                $progressMsg = $input['progress_message'] ?? '';

                try {
                    $db->exec("ALTER TABLE dubbing_jobs ADD COLUMN progress INT DEFAULT 0");
                } catch (Exception $e) {
                }
                try {
                    $db->exec("ALTER TABLE dubbing_jobs ADD COLUMN progress_message VARCHAR(255) DEFAULT ''");
                } catch (Exception $e) {
                }

                $db->prepare("UPDATE dubbing_jobs SET progress = ?, progress_message = ?, updated_at = NOW() WHERE id = ?")->execute([$progress, $progressMsg, $jobId]);
                jsonResponse(['status' => 'success']);
                break;

            case 'log':
                // Worker sends progress log
                $jobId = $input['job_id'] ?? '';
                $message = $input['message'] ?? '';
                try {
                    $db->prepare("INSERT INTO worker_logs (worker_uuid, worker_name, job_id, message, level) VALUES (?, ?, ?, ?, ?)")
                        ->execute(['DUBBING', 'Worker', $jobId, $message, 'info']);
                } catch (Exception $e) {
                }
                jsonResponse(['status' => 'success']);
                break;
            case 'cancel':
                $jobId = $input['job_id'] ?? '';
                $userId = null;

                // Auth: check token or worker secret
                if (!empty($input['secret']) && verifyWorkerSecret($input['secret'])) {
                    // Worker/admin cancel
                } else {
                    // User cancel - verify ownership
                    $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
                    if (preg_match('/Bearer\s+(.+)/', $authHeader, $m)) {
                        $decoded = verifyToken($m[1]);
                        if ($decoded)
                            $userId = $decoded['sub'] ?? $decoded['id'] ?? null;
                    }
                    if (!$userId)
                        jsonResponse(['error' => 'Unauthorized'], 401);
                }

                $db->beginTransaction();
                if ($userId) {
                    $stmt = $db->prepare("SELECT id, user_id, points_used, status FROM dubbing_jobs WHERE id = ? AND user_id = ? FOR UPDATE");
                    $stmt->execute([$jobId, $userId]);
                } else {
                    $stmt = $db->prepare("SELECT id, user_id, points_used, status FROM dubbing_jobs WHERE id = ? FOR UPDATE");
                    $stmt->execute([$jobId]);
                }
                $job = $stmt->fetch();

                if (!$job) {
                    $db->rollBack();
                    jsonResponse(['error' => 'Job not found'], 404);
                }
                if (!in_array($job['status'], ['pending', 'processing'])) {
                    $db->rollBack();
                    jsonResponse(['error' => 'Job cannot be cancelled'], 400);
                }

                // Refund points
                $refund = intval($job['points_used']);
                if ($refund > 0) {
                    $db->prepare("UPDATE users SET quota_used = GREATEST(0, quota_used - ?) WHERE id = ?")->execute([$refund, $job['user_id']]);
                }

                // Mark as failed
                $db->prepare("UPDATE dubbing_jobs SET status = 'failed', error_message = 'Đã hủy bởi người dùng', updated_at = NOW() WHERE id = ?")->execute([$jobId]);
                $db->commit();

                jsonResponse(['status' => 'success', 'refunded' => $refund]);
                break;

            default:
                jsonResponse(['error' => 'Invalid action'], 400);
        }
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction())
            $db->rollBack();
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}
