<?php
require_once __DIR__ . '/config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Get input
$input = json_decode(file_get_contents('php://input'), true);
$sessionToken = $input['token'] ?? '';
$jobId = $input['job_id'] ?? '';

if (!$sessionToken || !$jobId) {
    jsonResponse(['error' => 'Token and Job ID required'], 400);
}

try {
    $db = getDB();

    // Verify token
    $tokenData = verifyToken($sessionToken);
    if (!$tokenData) {
        jsonResponse(['error' => 'Phiên đăng nhập hết hạn hoặc không hợp lệ'], 401);
    }

    $userId = $tokenData['user_id'];
    // Get user email for logging
    $stmtUser = $db->prepare("SELECT email FROM users WHERE id = ?");
    $stmtUser->execute([$userId]);
    $userEmail = $stmtUser->fetchColumn() ?: "User #$userId";

    // Fetch job details and check ownership
    $stmt = $db->prepare("SELECT * FROM conversion_jobs WHERE id = ? AND user_id = ?");
    $stmt->execute([$jobId, $userId]);
    $job = $stmt->fetch();

    if (!$job) {
        jsonResponse(['error' => 'Không tìm thấy tác vụ hoặc bạn không có quyền'], 404);
    }

    // Check if retryable — customers can only retry failed jobs
    $isFailed = strpos($job['status'], 'failed') !== false;

    if (!$isFailed && $job['status'] !== 'pending') {
        if ($job['status'] === 'completed') {
            jsonResponse(['error' => 'Tác vụ đã hoàn thành, không cần thử lại'], 400);
        }
        if ($job['status'] === 'processing' || $job['status'] === 'retrying') {
            jsonResponse(['error' => 'Tác vụ đang được xử lý, vui lòng chờ thêm'], 400);
        }
        jsonResponse(['error' => 'Không thể thử lại ở trạng thái hiện tại'], 400);
    }
    // Block retry for permanent errors — retrying won't change the result
    if ($isFailed) {
        $jobStatus = $job['status'];
        $permanentErrors = ['violate', 'free_users_not_allowed', 'voice_not_found', 'unsupported_model', 'trả phí', 'từ chối', 'chính sách', 'paid tier', 'Creator tier'];
        foreach ($permanentErrors as $keyword) {
            if (stripos($jobStatus, $keyword) !== false) {
                // Extract user-friendly message after "failed: "
                $errMsg = strpos($jobStatus, 'failed:') !== false 
                    ? trim(substr($jobStatus, strpos($jobStatus, 'failed:') + 7, 200))
                    : 'Lỗi này không thể khắc phục bằng cách thử lại.';
                // Log blocked retry attempt to admin panel
                try {
                    $db->prepare("INSERT INTO worker_logs (worker_uuid, worker_name, job_id, message, level) VALUES (?, ?, ?, ?, ?)")
                        ->execute(['CUSTOMER', '👤 Khách', $jobId, "Khách bấm Thử lại nhưng BỊ CHẶN (lỗi vĩnh viễn). Email: $userEmail", 'warning']);
                } catch (Exception $e) {}
                jsonResponse(['error' => $errMsg], 400);
            }
        }
    }

    // Log customer retry to admin panel
    try {
        $db->prepare("INSERT INTO worker_logs (worker_uuid, worker_name, job_id, message, level) VALUES (?, ?, ?, ?, ?)")
            ->execute(['CUSTOMER', '👤 Khách', $jobId, "Khách bấm Thử lại. Email: $userEmail | Status cũ: " . mb_substr($job['status'], 0, 80), 'info']);
    } catch (Exception $e) {}

    // Reset Job Status to pending
    $stmt = $db->prepare("UPDATE conversion_jobs SET status = 'pending', worker_uuid = NULL, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$jobId]);

    // Dispatch job
    $dispatch = dispatchJob($jobId);

    if (isset($dispatch['error'])) {
        jsonResponse([
            'status' => 'success',
            'job_id' => $jobId,
            'message' => 'Tác vụ đã được đưa vào hàng chờ xử lý lại: ' . $dispatch['error']
        ]);
    }

    jsonResponse([
        'status' => 'success',
        'job_id' => $jobId,
        'message' => 'Đã bắt đầu xử lý lại tác vụ'
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Lỗi Server: ' . $e->getMessage()], 500);
}
?>