<?php
require_once __DIR__ . '/../../config.php';

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, x-api-key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// 1. Verify API Key
$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
$user = verifyPartnerApiKey($apiKey);

if (!$user) {
    jsonResponse(['error' => 'Invalid or expired API Key'], 401);
}

// 1.5. Rate Limit Check
$rateLimitError = checkRateLimit($user['id'], $user['key_type'] ?? 'partner');
if ($rateLimitError) {
    jsonResponse(['error' => $rateLimitError], 429);
}

// 2. Get Input
$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    jsonResponse(['error' => 'Invalid JSON body'], 400);
}

$text = $input['text'] ?? '';
$voiceId = $input['voice_id'] ?? '21m00Tcm4TlvDq8ikWAM'; // Default Rachel
$modelId = $input['model_id'] ?? 'eleven_multilingual_v2';
$voiceSettings = $input['voice_settings'] ?? null;

if (!$text) {
    jsonResponse(['error' => 'Text is required'], 400);
}

// No character limit - worker handles chunking automatically
$charCount = mb_strlen($text, 'UTF-8');

try {
    $db = getDB();

    // 3. Quota Check (Team-aware, same logic as convert.php)
    $isTeamMember = !empty($user['parent_id']);
    $personalRemaining = max(0, $user['quota_total'] - $user['quota_used']);
    $teamRemaining = 0;

    if ($isTeamMember) {
        $stmtParent = $db->prepare("SELECT id, quota_total, quota_used FROM users WHERE id = ? FOR UPDATE");
        $stmtParent->execute([$user['parent_id']]);
        $parent = $stmtParent->fetch();

        if ($parent) {
            $parentRemaining = max(0, $parent['quota_total'] - $parent['quota_used']);
            $memberTeamLimitRemaining = max(0, ($user['team_quota_limit'] ?? 0) - ($user['team_quota_used'] ?? 0));
            $teamRemaining = min($memberTeamLimitRemaining, $parentRemaining);
        }
    }

    $totalAvailable = $personalRemaining + $teamRemaining;

    if ($totalAvailable < $charCount) {
        jsonResponse(['error' => 'Insufficient quota. Characters needed: ' . $charCount . ', Available: ' . $totalAvailable], 403);
    }

    // 4. Deduct Quota (personal first, then team)
    $toDeduct = $charCount;
    $personalDeduct = min($toDeduct, $personalRemaining);
    $toDeduct -= $personalDeduct;

    if ($personalDeduct > 0) {
        $stmt = $db->prepare("UPDATE users SET quota_used = quota_used + ? WHERE id = ?");
        $stmt->execute([$personalDeduct, $user['id']]);
    }

    if ($toDeduct > 0 && $isTeamMember) {
        $stmt = $db->prepare("UPDATE users SET team_quota_used = team_quota_used + ? WHERE id = ?");
        $stmt->execute([$toDeduct, $user['id']]);
        $stmt = $db->prepare("UPDATE users SET quota_used = quota_used + ? WHERE id = ?");
        $stmt->execute([$toDeduct, $user['parent_id']]);
    }

    // 5. Create Job
    $jobId = strtoupper(bin2hex(random_bytes(4))); // 8 chars ID
    $totalChunks = ceil($charCount / 4500);

    $stmt = $db->prepare("INSERT INTO conversion_jobs (id, user_id, total_chunks, full_text, voice_id, model_id, voice_settings, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
    $settingsJson = $voiceSettings ? json_encode($voiceSettings) : null;
    $stmt->execute([$jobId, $user['id'], $totalChunks, $text, $voiceId, $modelId, $settingsJson]);

    // 6. Log usage
    $textPreview = mb_substr($text, 0, 100, 'UTF-8');
    $stmtLog = $db->prepare("INSERT INTO usage_logs (user_id, job_id, characters_used, worker_uuid, text_preview) VALUES (?, ?, ?, 'EXTERNAL_API', ?)");
    $stmtLog->execute([$user['id'], $jobId, $charCount, $textPreview]);

    // 7. Trigger Dispatcher
    dispatchJob($jobId);

    jsonResponse([
        'status' => 'success',
        'job_id' => $jobId,
        'message' => 'Job created successfully',
        'characters_used' => $charCount,
        'remaining_quota' => ($totalAvailable - $charCount)
    ]);

} catch (Exception $e) {
    error_log("External TTS API Error: " . $e->getMessage());
    jsonResponse(['error' => 'Đã xảy ra lỗi hệ thống, vui lòng thử lại sau.'], 500);
}
