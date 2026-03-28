<?php
require_once __DIR__ . '/config.php';
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 26 Jul 1997 05:00:00 GMT');
header('Content-Type: application/json');

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

$token = $_GET['token'] ?? '';
if (!$token) {
    jsonResponse(['error' => 'Missing token'], 400);
}

try {
    $db = getDB();

    // Verify token
    $tokenData = verifyToken($token);
    if (!$tokenData) {
        jsonResponse(['error' => 'Unauthorized'], 401);
    }

    $userId = $tokenData['user_id'];

    // Fetch jobs for this user from the last 24 hours
    $stmt = $db->prepare("
        SELECT id, full_text as text, voice_id as voiceId, model_id as modelId, voice_settings as voiceSettings, status, audio_url as audioUrl, srt_url as srtUrl, created_at as timestamp, original_filename, sort_order
        FROM conversion_jobs 
        WHERE user_id = ? AND created_at > (NOW() - INTERVAL 1 DAY)
        ORDER BY created_at DESC 
        LIMIT 50
    ");
    $stmt->execute([$userId]);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format timestamp as milliseconds for JS Compatibility
    foreach ($jobs as &$job) {
        $job['timestamp'] = strtotime($job['timestamp']) * 1000;
        if ($job['voice_settings']) {
            $job['voice_settings'] = json_decode($job['voice_settings'], true);
        }

        // Ensure URLs are Absolute
        if ($job['status'] === 'completed') {
            if (empty($job['audioUrl'])) {
                $job['audioUrl'] = PHP_BACKEND_URL . '/api/results/' . $job['id'] . '.mp3';
            }
            if (empty($job['srtUrl'])) {
                $job['srtUrl'] = PHP_BACKEND_URL . '/api/results/' . $job['id'] . '.srt';
            }
            // Always provide snake_case fallback for frontend compatibility
            $job['audio_url'] = $job['audioUrl'];
            $job['srt_url'] = $job['srtUrl'];
        }

        // Sanitize status — hide internal error details but keep user-friendly message
        if (strpos($job['status'], 'failed') !== false) {
            $rawStatus = $job['status'];
            $errorDetail = '';
            if (strpos($rawStatus, 'failed:') !== false) {
                $errorDetail = trim(substr($rawStatus, strpos($rawStatus, 'failed:') + 7));
            }
            // Show specific message for known errors
            if (stripos($errorDetail, 'Giọng nói') !== false || stripos($errorDetail, 'Voice') !== false) {
                $job['error_message'] = $errorDetail;
            } elseif (stripos($errorDetail, 'Model') !== false) {
                $job['error_message'] = $errorDetail;
            } elseif (stripos($errorDetail, 'chính sách') !== false || stripos($errorDetail, 'Nội dung') !== false || stripos($errorDetail, 'từ chối') !== false || stripos($errorDetail, 'violate') !== false) {
                $job['error_message'] = 'Nội dung hoặc giọng đọc bị chặn bởi chính sách ElevenLabs. Vui lòng thử đổi giọng hoặc chỉnh sửa nội dung.';
            } else {
                $job['error_message'] = 'Đã xảy ra lỗi khi xử lý, vui lòng thử lại.';
            }
            $job['status'] = 'failed';
        } elseif ($job['status'] === 'retrying') {
            $job['status'] = 'processing';
        }
    }

    jsonResponse([
        'status' => 'success',
        'jobs' => $jobs
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
