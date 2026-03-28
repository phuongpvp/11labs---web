<?php
/**
 * Admin: Cancel or Retry a job
 * POST { action: "cancel" | "retry", job_id: "XXX" }
 */
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

$adminPassword = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
if (!$adminPassword || !verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$input = json_decode(file_get_contents('php://input'), true);
$jobId = $input['job_id'] ?? '';
$action = $input['action'] ?? 'retry';

if (!$jobId) {
    jsonResponse(['error' => 'Missing job_id'], 400);
}

try {
    $db = getDB();

    // Search across all job tables
    $jobTables = [
        'conversion_jobs' => ['text_field' => 'full_text', 'points_field' => null],
        'music_jobs'      => ['text_field' => null, 'points_field' => 'points_used'],
        'sfx_jobs'        => ['text_field' => null, 'points_field' => 'points_used'],
        'stt_jobs'        => ['text_field' => null, 'points_field' => 'points_used'],
        'dubbing_jobs'    => ['text_field' => null, 'points_field' => 'points_used'],
        'isolation_jobs'  => ['text_field' => null, 'points_field' => 'points_used'],
    ];

    $job = null;
    $jobTable = null;
    $refundAmount = 0;

    foreach ($jobTables as $table => $config) {
        try {
            $stmt = $db->prepare("SELECT * FROM $table WHERE id = ?");
            $stmt->execute([$jobId]);
            $found = $stmt->fetch();
            if ($found) {
                $job = $found;
                $jobTable = $table;
                if ($config['points_field']) {
                    $refundAmount = (int)($found[$config['points_field']] ?? 0);
                } else {
                    $refundAmount = mb_strlen($found[$config['text_field']] ?? '', 'UTF-8');
                }
                break;
            }
        } catch (Exception $e) { continue; }
    }

    if (!$job) {
        jsonResponse(['error' => 'Job not found'], 404);
    }

    // === CANCEL: Hủy job + hoàn trả điểm ===
    if ($action === 'cancel') {
        $db->beginTransaction();
        try {
            // Update job status
            $db->prepare("UPDATE $jobTable SET status = 'failed: Hủy bởi admin', worker_uuid = NULL WHERE id = ?")
                ->execute([$jobId]);

            // Refund credits
            if ($refundAmount > 0) {
                $stmtU = $db->prepare("SELECT quota_used, team_quota_used, parent_id FROM users WHERE id = ? FOR UPDATE");
                $stmtU->execute([$job['user_id']]);
                $userData = $stmtU->fetch();

                if ($userData) {
                    $personalRefund = min($refundAmount, $userData['quota_used']);
                    $teamRefund = $refundAmount - $personalRefund;

                    if ($personalRefund > 0) {
                        $db->prepare("UPDATE users SET quota_used = quota_used - ? WHERE id = ?")
                            ->execute([$personalRefund, $job['user_id']]);
                    }
                    if ($teamRefund > 0 && !empty($userData['parent_id'])) {
                        $db->prepare("UPDATE users SET team_quota_used = team_quota_used - ? WHERE id = ?")
                            ->execute([$teamRefund, $job['user_id']]);
                        $db->prepare("UPDATE users SET quota_used = quota_used - ? WHERE id = ?")
                            ->execute([$teamRefund, $userData['parent_id']]);
                    }
                }
            }
            $db->commit();

            // Log
            try {
                $stmtL = $db->prepare("INSERT INTO worker_logs (worker_uuid, worker_name, job_id, message, level) VALUES (?, ?, ?, ?, ?)");
                $stmtL->execute(['SYSTEM', 'System Engine', $jobId, "Admin đã hủy Job $jobId ($jobTable). Hoàn trả " . number_format($refundAmount) . " điểm cho User {$job['user_id']}.", 'warning']);
            } catch (Exception $e) {}

            logToFile('admin_actions.log', "ADMIN CANCEL: Job $jobId ($jobTable) cancelled. Refunded $refundAmount points to User {$job['user_id']}.");
            jsonResponse(['status' => 'success', 'message' => "Đã hủy Job $jobId và hoàn trả " . number_format($refundAmount) . " điểm"]);

        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // === RETRY: Reset + redispatch ===
    if ($action === 'retry') {
        $db->prepare("UPDATE $jobTable SET status = 'pending', worker_uuid = NULL, updated_at = NOW() WHERE id = ?")
            ->execute([$jobId]);

        logToFile('admin_actions.log', "ADMIN RETRY: Job $jobId ($jobTable) reset to pending.");

        if ($jobTable === 'conversion_jobs') {
            $result = dispatchJob($jobId);
            if (isset($result['status']) && $result['status'] === 'success') {
                jsonResponse(['status' => 'success', 'message' => "Job đã được gửi đến máy chủ {$result['worker']}"]);
            } else {
                jsonResponse(['status' => 'success', 'message' => "Job đã reset, đang chờ máy chủ rảnh"]);
            }
        } else {
            jsonResponse(['status' => 'success', 'message' => "Job $jobId ($jobTable) đã reset về pending. Worker sẽ tự nhận."]);
        }
    }

    jsonResponse(['error' => 'Invalid action. Use "cancel" or "retry"'], 400);

} catch (Exception $e) {
    jsonResponse(['error' => $e->getMessage()], 500);
}
