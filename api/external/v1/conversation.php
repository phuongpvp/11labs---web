<?php
/**
 * EXTERNAL API — Conversation (Hội thoại đa giọng nói)
 * Follows the same pattern as tts.php for partner/external usage.
 */
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

$lines = $input['lines'] ?? [];           // [{speaker: "A", text: "..."}, ...]
$speakersMap = $input['speakers'] ?? [];   // {A: {voice_id, voice_name}, B: {...}}
$modelId = $input['model_id'] ?? 'eleven_flash_v2_5';
$voiceSettings = $input['voice_settings'] ?? null;
$pauseDuration = floatval($input['pause_duration'] ?? 0.5);

if (empty($lines) || empty($speakersMap)) {
    jsonResponse(['error' => 'Lines and speakers are required'], 400);
}

// Validate each line has a speaker with voice_id assigned
foreach ($lines as $line) {
    $sp = $line['speaker'] ?? '';
    if (!$sp || !isset($speakersMap[$sp]) || empty($speakersMap[$sp]['voice_id'])) {
        jsonResponse(['error' => "Speaker '$sp' does not have a voice assigned"], 400);
    }
}

// 3. Calculate total characters
$charsNeeded = 0;
foreach ($lines as $line) {
    $charsNeeded += mb_strlen($line['text'] ?? '', 'UTF-8');
}

if ($charsNeeded === 0) {
    jsonResponse(['error' => 'Conversation content is empty'], 400);
}

// Model cost multiplier
$modelLower = strtolower($modelId);
$multiplier = 1;
if (strpos($modelLower, 'v3') !== false) {
    $multiplier = 2;
}
$totalCost = ceil($charsNeeded * $multiplier);

// 4. Quota Check (Team-aware)
$isTeamMember = !empty($user['parent_id']);
$personalRemaining = max(0, $user['quota_total'] - $user['quota_used']);
$teamRemaining = 0;

try {
    $db = getDB();

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

    if ($totalAvailable < $totalCost) {
        jsonResponse(['error' => 'Insufficient quota. Cost: ' . $totalCost . ', Available: ' . $totalAvailable], 403);
    }

    // 5. Deduct Quota (personal first, then team)
    $toDeduct = $totalCost;
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

    // 6. Create Job
    $jobId = 'CV-' . substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 7);

    // Pack conversation data as JSON into full_text
    $conversationData = json_encode([
        'lines' => $lines,
        'speakers' => $speakersMap,
        'pause_duration' => $pauseDuration
    ], JSON_UNESCAPED_UNICODE);

    // Lazy add job_type column (safe, only runs once)
    try {
        $db->exec("ALTER TABLE conversion_jobs ADD COLUMN job_type VARCHAR(20) DEFAULT 'tts' AFTER model_id");
    } catch (Exception $e) { /* Column already exists */
    }

    $firstVoiceId = reset($speakersMap)['voice_id'] ?? '';
    $voiceSettingsJson = $voiceSettings ? json_encode($voiceSettings) : null;

    $stmt = $db->prepare("INSERT INTO conversion_jobs (id, user_id, total_chunks, full_text, voice_id, model_id, job_type, voice_settings, status) VALUES (?, ?, ?, ?, ?, ?, 'conversation', ?, 'pending')");
    $stmt->execute([$jobId, $user['id'], count($lines), $conversationData, $firstVoiceId, $modelId, $voiceSettingsJson]);

    // 7. Log usage
    $textPreview = '';
    foreach (array_slice($lines, 0, 3) as $l) {
        $textPreview .= $l['speaker'] . '> ' . mb_substr($l['text'], 0, 30, 'UTF-8') . '... ';
    }
    $stmtLog = $db->prepare("INSERT INTO usage_logs (user_id, job_id, characters_used, worker_uuid, text_preview) VALUES (?, ?, ?, 'EXTERNAL_API', ?)");
    $stmtLog->execute([$user['id'], $jobId, $totalCost, mb_substr($textPreview, 0, 100, 'UTF-8')]);

    // 8. Dispatch
    dispatchJob($jobId);

    jsonResponse([
        'status' => 'success',
        'job_id' => $jobId,
        'message' => 'Conversation job created successfully',
        'characters_used' => $charsNeeded,
        'total_cost' => $totalCost,
        'remaining_quota' => ($totalAvailable - $totalCost)
    ]);

} catch (Exception $e) {
    error_log("External Conversation API Error: " . $e->getMessage());
    jsonResponse(['error' => 'Đã xảy ra lỗi hệ thống, vui lòng thử lại sau.'], 500);
}
