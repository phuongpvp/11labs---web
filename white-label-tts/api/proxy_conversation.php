<?php
// =====================================================
// Proxy Conversation — Quản lý quota hội thoại cho đại lý
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
    $charCount = (int) ($input['characters'] ?? 0);
    $jobId = $input['job_id'] ?? '';
    $textPreview = $input['text_preview'] ?? '';

    if (!$jobId || !$charCount) {
        jsonResponse(['error' => 'job_id and characters required'], 400);
    }

    $db = getDB();

    // Deduct quota
    $db->prepare("UPDATE customers SET quota_used = quota_used + ? WHERE id = ?")
        ->execute([$charCount, $customer['id']]);

    // Save history
    $db->prepare("INSERT INTO tts_history (customer_id, job_id, text_preview, characters_used, voice_id, model_id, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')")
        ->execute([$customer['id'], $jobId, $textPreview, $charCount, 'conversation', $input['model_id'] ?? '']);

    $remaining = (int) $customer['quota_allocated'] - (int) $customer['quota_used'] - $charCount;

    jsonResponse([
        'status' => 'success',
        'characters_deducted' => $charCount,
        'remaining' => max(0, $remaining)
    ]);

} elseif ($action === 'update_status') {
    $jobId = $input['job_id'] ?? '';
    $status = $input['status'] ?? '';
    $resultFile = $input['result_file'] ?? '';

    if ($jobId && $status) {
        $db = getDB();
        $db->prepare("UPDATE tts_history SET status = ?, result_file = ? WHERE job_id = ? AND customer_id = ?")
            ->execute([$status, $resultFile, $jobId, $customer['id']]);
    }

    jsonResponse(['status' => 'success']);

} else {
    jsonResponse(['error' => 'Invalid action. Use: check_quota, deduct_quota, update_status'], 400);
}
