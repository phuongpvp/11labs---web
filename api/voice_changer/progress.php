<?php
require_once __DIR__ . '/../config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

$method = $_SERVER['REQUEST_METHOD'];
$input = json_decode(file_get_contents('php://input'), true);

// 1. GET: Polling from Customer Dashboard
if ($method === 'GET') {
    $jobId = $_GET['job_id'] ?? '';
    if (!$jobId)
        jsonResponse(['error' => 'Missing job_id'], 400);

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT status, source_file, result_file, duration, points_used, error_message FROM voice_changer_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();

        if (!$job)
            jsonResponse(['error' => 'Job not found'], 404);

        if ($job['status'] === 'completed') {
            $job['result_url'] = PHP_BACKEND_URL . '/api/results/voice_changer/' . $job['result_file'];
        }

        jsonResponse(['status' => 'success', 'job' => $job]);
    } catch (Exception $e) {
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}

// 2. POST: Actions from Worker (Colab)
if ($method === 'POST') {
    $action = $input['action'] ?? '';
    $workerSecret = $input['secret'] ?? '';

    // Security check for worker
    if (!verifyWorkerSecret($workerSecret)) {
        jsonResponse(['error' => 'Unauthorized worker'], 403);
    }

    try {
        $db = getDB();

        switch ($action) {
            case 'get_pending':
                $stmt = $db->query("SELECT id, source_file, voice_id FROM voice_changer_jobs WHERE status = 'pending' ORDER BY created_at ASC LIMIT 5");
                jsonResponse(['status' => 'success', 'jobs' => $stmt->fetchAll()]);
                break;

            case 'get_keys':
                $stmt = $db->query("SELECT id, key_encrypted as 'key', credits_remaining as credits FROM api_keys WHERE status = 'active' AND (cooldown_until IS NULL OR cooldown_until < NOW()) ORDER BY credits_remaining DESC");
                jsonResponse(['status' => 'success', 'keys' => $stmt->fetchAll()]);
                break;

            case 'start':
                $jobId = $input['job_id'] ?? '';
                $workerUuid = $input['worker_uuid'] ?? '';
                $stmt = $db->prepare("UPDATE voice_changer_jobs SET status = 'processing', worker_uuid = ? WHERE id = ? AND status = 'pending'");
                $stmt->execute([$workerUuid, $jobId]);
                $claimed = $stmt->rowCount() > 0;
                jsonResponse(['status' => 'success', 'claimed' => $claimed]);
                break;

            case 'charge':
                $jobId = $input['job_id'] ?? '';
                $duration = floatval($input['duration'] ?? 0); // Seconds

                // Calculate points: 1.500 points / 60 seconds
                $pointsNeeded = ceil(($duration / 60) * 1500);

                $db->beginTransaction();
                $stmt = $db->prepare("SELECT j.user_id, u.quota_used, u.quota_total FROM voice_changer_jobs j JOIN users u ON j.user_id = u.id WHERE j.id = ? FOR UPDATE");
                $stmt->execute([$jobId]);
                $user = $stmt->fetch();

                if (!$user) {
                    $db->rollBack();
                    jsonResponse(['error' => 'User not found'], 404);
                }

                if (($user['quota_total'] - $user['quota_used']) < $pointsNeeded) {
                    $db->rollBack();
                    jsonResponse(['error' => 'Insufficient points', 'needed' => $pointsNeeded, 'available' => ($user['quota_total'] - $user['quota_used'])]);
                }

                // Deduct points
                $db->prepare("UPDATE users SET quota_used = quota_used + ? WHERE id = ?")->execute([$pointsNeeded, $user['user_id']]);
                $db->prepare("UPDATE voice_changer_jobs SET duration = ?, points_used = ? WHERE id = ?")->execute([$duration, $pointsNeeded, $jobId]);
                $db->commit();

                jsonResponse(['status' => 'success', 'points_deducted' => $pointsNeeded]);
                break;

            case 'complete':
                $jobId = $input['job_id'] ?? '';
                $resultFile = $input['result_file'] ?? '';
                $apiKeyIds = $input['api_key_ids'] ?? '';

                $stmt = $db->prepare("UPDATE voice_changer_jobs SET status = 'completed', result_file = ?, api_key_ids = ?, completed_at = NOW() WHERE id = ?");
                $stmt->execute([$resultFile, $apiKeyIds, $jobId]);
                jsonResponse(['status' => 'success']);
                break;

            case 'fail':
                $jobId = $input['job_id'] ?? '';
                $error = $input['error'] ?? 'Unknown error';
                $stmt = $db->prepare("UPDATE voice_changer_jobs SET status = 'failed', error_message = ? WHERE id = ?");
                $stmt->execute([$error, $jobId]);
                jsonResponse(['status' => 'success']);
                break;

            default:
                jsonResponse(['error' => 'Invalid action'], 400);
        }
    } catch (Exception $e) {
        if ($db && $db->inTransaction())
            $db->rollBack();
        jsonResponse(['error' => $e->getMessage()], 500);
    }
}
