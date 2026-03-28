<?php
// =====================================================
// Update quota sau khi TTS thành công (gọi từ frontend)
// =====================================================
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Verify customer
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);
$customer = verifyCustomerToken($token);

if (!$customer) {
    jsonResponse(['error' => 'Vui lòng đăng nhập'], 401);
}

$input = getInput();
$action = $input['action'] ?? '';

if ($action === 'check_quota') {
    // Check if customer has enough quota
    $charCount = (int) ($input['characters'] ?? 0);
    $remaining = (int) $customer['quota_allocated'] - (int) $customer['quota_used'];

    if ($remaining < $charCount) {
        jsonResponse(['error' => "Không đủ quota. Cần: {$charCount}, Còn lại: {$remaining}"], 403);
    }

    jsonResponse([
        'status' => 'success',
        'remaining' => $remaining,
        'characters' => $charCount
    ]);

} elseif ($action === 'deduct_quota') {
    // Deduct quota and save history after successful TTS
    $charCount = (int) ($input['characters'] ?? 0);
    $jobId = $input['job_id'] ?? '';
    $textPreview = $input['text_preview'] ?? '';
    $voiceId = $input['voice_id'] ?? '';
    $modelId = $input['model_id'] ?? '';

    if (!$jobId || !$charCount) {
        jsonResponse(['error' => 'job_id and characters required'], 400);
    }

    $db = getDB();

    // Deduct quota
    $db->prepare("UPDATE customers SET quota_used = quota_used + ? WHERE id = ?")
        ->execute([$charCount, $customer['id']]);

    // Save history
    $db->prepare("INSERT INTO tts_history (customer_id, job_id, text_preview, characters_used, voice_id, model_id, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')")
        ->execute([$customer['id'], $jobId, $textPreview, $charCount, $voiceId, $modelId]);

    $remaining = (int) $customer['quota_allocated'] - (int) $customer['quota_used'] - $charCount;

    jsonResponse([
        'status' => 'success',
        'characters_deducted' => $charCount,
        'remaining' => max(0, $remaining)
    ]);

} elseif ($action === 'update_status') {
    // Update job status in history
    $jobId = $input['job_id'] ?? '';
    $status = $input['status'] ?? '';
    $resultFile = $input['result_file'] ?? '';
    $errorMessage = $input['error_message'] ?? '';

    if ($jobId && $status) {
        $db = getDB();
        // Ensure error_message column exists
        try { $db->exec("ALTER TABLE tts_history ADD COLUMN error_message TEXT DEFAULT ''"); } catch (Exception $e) {}
        $db->prepare("UPDATE tts_history SET status = ?, result_file = ?, error_message = ? WHERE job_id = ? AND customer_id = ?")
            ->execute([$status, $resultFile, $errorMessage, $jobId, $customer['id']]);
    }

    jsonResponse(['status' => 'success']);

} elseif ($action === 'retry_job') {
    // Retry a failed/stuck job — re-dispatch on main server
    $jobId = $input['job_id'] ?? '';
    if (!$jobId) {
        jsonResponse(['error' => 'job_id required'], 400);
    }

    $db = getDB();
    // Verify job belongs to this customer
    $stmt = $db->prepare("SELECT id, status FROM tts_history WHERE job_id = ? AND customer_id = ?");
    $stmt->execute([$jobId, $customer['id']]);
    $job = $stmt->fetch();
    if (!$job) {
        jsonResponse(['error' => 'Job not found'], 404);
    }

    // Call main server to retry (POST to retry.php)
    $result = callExternalAPI("retry.php", 'POST', ['action' => 'retry', 'job_id' => $jobId]);

    // Check if main server blocked the retry (permanent error)
    if (isset($result['error'])) {
        jsonResponse(['error' => $result['error']], 400);
    }

    // Reset local status to pending
    $db->prepare("UPDATE tts_history SET status = 'pending' WHERE job_id = ? AND customer_id = ?")
        ->execute([$jobId, $customer['id']]);

    jsonResponse(['status' => 'success', 'message' => 'Đã gửi lệnh chạy lại']);

} elseif ($action === 'cancel_job') {
    // Cancel a stuck job — mark as cancelled and refund quota
    $jobId = $input['job_id'] ?? '';
    if (!$jobId) {
        jsonResponse(['error' => 'job_id required'], 400);
    }

    $db = getDB();
    // Get job info
    $stmt = $db->prepare("SELECT id, status, characters_used FROM tts_history WHERE job_id = ? AND customer_id = ?");
    $stmt->execute([$jobId, $customer['id']]);
    $job = $stmt->fetch();
    if (!$job) {
        jsonResponse(['error' => 'Job not found'], 404);
    }

    // Only cancel if not already completed or cancelled
    if ($job['status'] === 'completed') {
        jsonResponse(['error' => 'Job đã hoàn thành, không thể hủy'], 400);
    }
    if ($job['status'] === 'cancelled') {
        jsonResponse(['error' => 'Job đã được hủy trước đó'], 400);
    }

    // Only refund if job has NOT started processing yet
    $chars = (int) ($job['characters_used'] ?? 0);
    $refunded = 0;
    if ($job['status'] === 'pending' && $chars > 0) {
        $db->prepare("UPDATE customers SET quota_used = MAX(0, quota_used - ?) WHERE id = ?")
            ->execute([$chars, $customer['id']]);
        $refunded = $chars;
    }

    // Mark as cancelled
    $db->prepare("UPDATE tts_history SET status = 'cancelled' WHERE job_id = ? AND customer_id = ?")
        ->execute([$jobId, $customer['id']]);

    $msg = $refunded > 0 ? "Đã hủy job và hoàn trả {$refunded} ký tự" : "Đã hủy job (không hoàn ký tự vì job đang xử lý)";
    jsonResponse(['status' => 'success', 'message' => $msg]);

} else {
    jsonResponse(['error' => 'Invalid action. Use: check_quota, deduct_quota, update_status, retry_job, cancel_job'], 400);
}
