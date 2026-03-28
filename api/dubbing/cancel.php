<?php
/**
 * Dubbing - Cancel a pending/processing job and refund points
 */
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
$jobId = $input['job_id'] ?? $_POST['job_id'] ?? '';

if (!$jobId) {
    jsonResponse(['error' => 'Missing job_id'], 400);
}

try {
    $db = getDB();
    $db->beginTransaction();

    $stmt = $db->prepare("SELECT id, user_id, points_used, status FROM dubbing_jobs WHERE id = ? AND user_id = ? FOR UPDATE");
    $stmt->execute([$jobId, $userId]);
    $job = $stmt->fetch();

    if (!$job) {
        $db->rollBack();
        jsonResponse(['error' => 'Job not found'], 404);
    }

    if (!in_array($job['status'], ['pending', 'processing'])) {
        $db->rollBack();
        jsonResponse(['error' => 'Job đã hoàn thành, không thể hủy'], 400);
    }

    // Only refund if job has NOT started processing yet
    $refund = 0;
    if ($job['status'] === 'pending') {
        $refund = intval($job['points_used']);
        if ($refund > 0) {
            $db->prepare("UPDATE users SET quota_used = GREATEST(0, quota_used - ?) WHERE id = ?")->execute([$refund, $userId]);
        }
    }

    // Mark as failed
    $cancelMsg = $job['status'] === 'pending' ? 'Đã hủy bởi người dùng (hoàn điểm)' : 'Đã hủy bởi người dùng (không hoàn điểm - job đang xử lý)';
    $db->prepare("UPDATE dubbing_jobs SET status = 'failed', error_message = ?, updated_at = NOW() WHERE id = ?")->execute([$cancelMsg, $jobId]);
    $db->commit();

    // Log to admin
    try {
        $stmtEmail = $db->prepare("SELECT email FROM users WHERE id = ?");
        $stmtEmail->execute([$userId]);
        $email = $stmtEmail->fetchColumn() ?: "User #$userId";
        $db->prepare("INSERT INTO worker_logs (worker_uuid, worker_name, job_id, message, level) VALUES (?, ?, ?, ?, ?)")
            ->execute(['CUSTOMER', '👤 Khách', $jobId, "Khách hủy Dubbing job. Email: $email | Trạng thái: {$job['status']} | Hoàn: $refund điểm", 'warning']);
    } catch (Exception $e) {}

    jsonResponse(['status' => 'success', 'refunded' => $refund]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction())
        $db->rollBack();
    jsonResponse(['error' => $e->getMessage()], 500);
}
