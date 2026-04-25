<?php
// =====================================================
// Proxy Status — Xem trạng thái job từ server chính
// =====================================================
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

// Verify customer
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);
$customer = verifyCustomerToken($token);

if (!$customer) {
    jsonResponse(['error' => 'Vui lòng đăng nhập'], 401);
}

$jobId = $_GET['job_id'] ?? '';
if (!$jobId) {
    jsonResponse(['error' => 'job_id required'], 400);
}

// Verify this job belongs to this customer
$db = getDB();
$stmt = $db->prepare("SELECT id, status, characters_used FROM tts_history WHERE job_id = ? AND customer_id = ?");
$stmt->execute([$jobId, $customer['id']]);
$job = $stmt->fetch();
if (!$job) {
    jsonResponse(['error' => 'Job not found'], 404);
}

// Get status from admin server
$result = callExternalAPI("status.php?job_id=" . urlencode($jobId));

if ($result['code'] === 200) {
    $data = $result['body'];

    // Update local history if completed
    if (($data['status'] ?? '') === 'completed') {
        $downloadUrl = $data['download_url'] ?? '';
        $db->prepare("UPDATE tts_history SET status = 'completed', result_file = ? WHERE job_id = ?")
            ->execute([$downloadUrl, $jobId]);
    } elseif (strpos($data['status'] ?? '', 'failed') !== false) {
        if ($job['status'] !== 'failed') {
            $chars = (int)($job['characters_used'] ?? 0);
            if ($chars > 0) {
                $db->prepare("UPDATE customers SET quota_used = MAX(0, quota_used - ?) WHERE id = ?")
                   ->execute([$chars, $customer['id']]);
            }
        }
        $db->prepare("UPDATE tts_history SET status = 'failed' WHERE job_id = ?")->execute([$jobId]);
    }

    jsonResponse($data);
} else {
    jsonResponse(['error' => 'Không thể kiểm tra trạng thái'], $result['code'] ?: 500);
}
