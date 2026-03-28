<?php
require_once __DIR__ . '/../../config.php';

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, x-api-key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

// 1. Verify API Key
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
$user = verifyPartnerApiKey($apiKey);

if (!$user) {
    jsonResponse(['error' => 'Invalid or expired API Key'], 401);
}

// POST: retry a job
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $input['action'] ?? '';
    $jobId = $input['job_id'] ?? ($_GET['job_id'] ?? '');

    if ($action === 'retry' && $jobId) {
        try {
            $db = getDB();
            // Verify job belongs to user
            $stmt = $db->prepare("SELECT id, status FROM conversion_jobs WHERE id = ? AND user_id = ?");
            $stmt->execute([$jobId, $user['id']]);
            $job = $stmt->fetch();

            if (!$job) {
                jsonResponse(['error' => 'Job not found'], 404);
            }
            if ($job['status'] === 'completed') {
                jsonResponse(['error' => 'Job already completed'], 400);
            }

            // Reset job to retrying for dispatcher to pick up
            $db->prepare("UPDATE conversion_jobs SET status = 'retrying', worker_uuid = NULL, updated_at = NOW() WHERE id = ?")
                ->execute([$jobId]);

            logToFile('admin_actions.log', "PARTNER RETRY: Job $jobId reset to retrying by partner user {$user['id']}");

            // Try immediate dispatch
            try {
                dispatchJob($jobId);
            } catch (Exception $e) { /* cron will pick it up */
            }

            jsonResponse(['status' => 'success', 'message' => 'Job re-queued']);
        } catch (Exception $e) {
            jsonResponse(['error' => 'Internal Server Error'], 500);
        }
    }

    jsonResponse(['error' => 'Invalid action'], 400);
}

// 2. Get Job ID (GET request)
$jobId = $_GET['job_id'] ?? '';
if (!$jobId) {
    jsonResponse(['error' => 'Job ID is required'], 400);
}

try {
    $db = getDB();

    // 3. Fetch Job (Ensure it belongs to the user)
    $stmt = $db->prepare("SELECT status, created_at, processed_chunks, total_chunks FROM conversion_jobs WHERE id = ? AND user_id = ?");
    $stmt->execute([$jobId, $user['id']]);
    $job = $stmt->fetch();

    if (!$job) {
        jsonResponse(['error' => 'Job not found'], 404);
    }

    $response = [
        'status' => $job['status'], // pending, processing, completed, failed
        'job_id' => $jobId,
        'created_at' => $job['created_at'],
        'processed_chunks' => (int)($job['processed_chunks'] ?? 0),
        'total_chunks' => (int)($job['total_chunks'] ?? 0)
    ];

    if ($job['status'] === 'completed') {
        // Construct full URL to the result file (standard naming: {job_id}.mp3)
        $response['download_url'] = PHP_BACKEND_URL . '/api/results/' . $jobId . '.mp3';
        $response['srt_url'] = PHP_BACKEND_URL . '/api/results/' . $jobId . '.srt';
    }

    if (strpos($job['status'], 'failed') !== false) {
        $errorDetail = '';
        if (strpos($job['status'], 'failed:') !== false) {
            $errorDetail = trim(substr($job['status'], strpos($job['status'], 'failed:') + 7));
        }
        // Show specific message for known errors
        if (stripos($errorDetail, 'Giọng nói') !== false || stripos($errorDetail, 'Voice') !== false) {
            $response['error_message'] = $errorDetail;
        } elseif (stripos($errorDetail, 'Model') !== false) {
            $response['error_message'] = $errorDetail;
        } elseif (stripos($errorDetail, 'chính sách') !== false || stripos($errorDetail, 'Nội dung') !== false || stripos($errorDetail, 'từ chối') !== false || stripos($errorDetail, 'violate') !== false) {
            $response['error_message'] = 'Nội dung hoặc giọng đọc bị chặn bởi chính sách ElevenLabs. Vui lòng thử đổi giọng hoặc chỉnh sửa nội dung.';
        } elseif (stripos($errorDetail, 'trả phí') !== false || stripos($errorDetail, 'paid tier') !== false || stripos($errorDetail, 'Creator tier') !== false) {
            $response['error_message'] = $errorDetail;
        } else {
            $response['error_message'] = 'Đã xảy ra lỗi khi xử lý, vui lòng thử lại.';
        }
        $response['status'] = 'failed';
    }

    jsonResponse($response);

} catch (Exception $e) {
    jsonResponse(['error' => 'Internal Server Error'], 500);
}
