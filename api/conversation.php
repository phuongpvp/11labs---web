<?php
/**
 * CONVERSATION API — Tạo hội thoại đa giọng nói
 * File mới hoàn toàn, KHÔNG chỉnh sửa bất kỳ file nào khác.
 * Tái sử dụng: verifyToken, getDB, dispatchJob từ config.php
 */
require_once __DIR__ . '/config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$sessionToken = $input['token'] ?? '';
$lines = $input['lines'] ?? [];        // [{speaker: "A", text: "..."}, ...]
$speakersMap = $input['speakers'] ?? []; // {A: {voice_id, voice_name}, B: {...}}
$modelId = $input['model_id'] ?? 'eleven_flash_v2_5';
$voiceSettings = $input['voice_settings'] ?? null;
$pauseDuration = floatval($input['pause_duration'] ?? 0.5); // seconds

if (!$sessionToken || empty($lines) || empty($speakersMap)) {
    jsonResponse(['error' => 'Token, lines and speakers required'], 400);
}

// Validate each line has a speaker with voice_id assigned
foreach ($lines as $line) {
    $sp = $line['speaker'] ?? '';
    if (!$sp || !isset($speakersMap[$sp]) || empty($speakersMap[$sp]['voice_id'])) {
        jsonResponse(['error' => "Người nói '$sp' chưa được gán giọng nói"], 400);
    }
}

try {
    $db = getDB();

    // Verify token
    $tokenData = verifyToken($sessionToken);
    if (!$tokenData) {
        jsonResponse(['error' => 'Phiên đăng nhập hết hạn hoặc không hợp lệ'], 401);
    }

    $userId = $tokenData['user_id'];

    // === ANTI-SPAM: Rate Limit + Parallel Limit (Tiered by Plan, Team-aware) ===
    $stmtUserInfo = $db->prepare("SELECT plan, parent_id FROM users WHERE id = ?");
    $stmtUserInfo->execute([$userId]);
    $userInfo = $stmtUserInfo->fetch();
    $userPlan = $userInfo['plan'] ?? 'trial';
    $parentId = $userInfo['parent_id'] ?? null;

    $isTeamContext = false;
    $teamRootId = null;

    if ($parentId) {
        $stmtParentPlan = $db->prepare("SELECT plan FROM users WHERE id = ?");
        $stmtParentPlan->execute([$parentId]);
        $effectivePlan = $stmtParentPlan->fetchColumn() ?: 'trial';
        $isTeamContext = true;
        $teamRootId = $parentId;
    } else {
        $stmtHasMembers = $db->prepare("SELECT COUNT(*) FROM users WHERE parent_id = ?");
        $stmtHasMembers->execute([$userId]);
        if ($stmtHasMembers->fetchColumn() > 0) {
            $isTeamContext = true;
            $teamRootId = $userId;
        }
        $effectivePlan = $userPlan;
    }

    $planLimits = [
        'supper_vip' => ['processing' => 10, 'pending' => 7, 'cooldown' => 3],
        'premium' => ['processing' => 8, 'pending' => 5, 'cooldown' => 3],
        'pro' => ['processing' => 5, 'pending' => 3, 'cooldown' => 5],
        'basic' => ['processing' => 3, 'pending' => 2, 'cooldown' => 5],
        'trial' => ['processing' => 1, 'pending' => 1, 'cooldown' => 10],
    ];
    $limits = $planLimits[$effectivePlan] ?? $planLimits['trial'];

    $stmtRecent = $db->prepare("SELECT created_at FROM conversion_jobs WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    $stmtRecent->execute([$userId]);
    $lastJob = $stmtRecent->fetch();
    if ($lastJob) {
        $elapsed = time() - strtotime($lastJob['created_at']);
        if ($elapsed < $limits['cooldown']) {
            jsonResponse(['error' => 'Vui lòng chờ vài giây trước khi gửi tiếp. (' . ($limits['cooldown'] - $elapsed) . 's)'], 429);
        }
    }
    if ($isTeamContext) {
        $stmtProc = $db->prepare("SELECT COUNT(*) FROM conversion_jobs WHERE user_id IN (SELECT id FROM users WHERE id = ? OR parent_id = ?) AND status = 'processing' AND updated_at > (NOW() - INTERVAL 10 MINUTE)");
        $stmtProc->execute([$teamRootId, $teamRootId]);
    } else {
        $stmtProc = $db->prepare("SELECT COUNT(*) FROM conversion_jobs WHERE user_id = ? AND status = 'processing' AND updated_at > (NOW() - INTERVAL 10 MINUTE)");
        $stmtProc->execute([$userId]);
    }
    if ($stmtProc->fetchColumn() >= $limits['processing']) {
        $teamMsg = $isTeamContext ? ' (chia sẻ trong nhóm)' : '';
        jsonResponse(['error' => "Đã đạt giới hạn {$limits['processing']} job đang xử lý{$teamMsg}. Vui lòng chờ hoàn thành."], 429);
    }
    $stmtPend = $db->prepare("SELECT COUNT(*) FROM conversion_jobs WHERE user_id = ? AND status = 'pending'");
    $stmtPend->execute([$userId]);
    if ($stmtPend->fetchColumn() >= $limits['pending']) {
        jsonResponse(['error' => "Bạn đang có {$limits['pending']} job trong hàng đợi. Vui lòng chờ hoàn thành."], 429);
    }

    // Calculate total characters
    $charsNeeded = 0;
    foreach ($lines as $line) {
        $charsNeeded += mb_strlen($line['text'] ?? '', 'UTF-8');
    }

    if ($charsNeeded === 0) {
        jsonResponse(['error' => 'Nội dung hội thoại trống'], 400);
    }

    // === QUOTA CHECK (copy logic từ convert.php) ===
    $db->beginTransaction();

    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? FOR UPDATE");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        $db->rollBack();
        jsonResponse(['error' => 'Tài khoản không tìm thấy hoặc đã bị khóa'], 403);
    }

    if (strtotime($user['expires_at']) < time()) {
        $db->rollBack();
        jsonResponse(['error' => 'Gói cước của bạn đã hết hạn'], 403);
    }

    $isTeamMember = !empty($user['parent_id']);
    $personalRemaining = max(0, $user['quota_total'] - $user['quota_used']);
    $teamRemaining = 0;
    $parent = null;

    if ($isTeamMember) {
        $stmtParent = $db->prepare("SELECT id, quota_total, quota_used FROM users WHERE id = ? FOR UPDATE");
        $stmtParent->execute([$user['parent_id']]);
        $parent = $stmtParent->fetch();

        if ($parent) {
            $parentRemaining = max(0, $parent['quota_total'] - $parent['quota_used']);
            $memberTeamLimitRemaining = max(0, $user['team_quota_limit'] - $user['team_quota_used']);
            $teamRemaining = min($memberTeamLimitRemaining, $parentRemaining);
        }
    }

    $totalAvailable = $personalRemaining + $teamRemaining;

    // Model cost multiplier
    $modelLower = strtolower($modelId);
    $multiplier = 1;
    if (strpos($modelLower, 'v3') !== false) {
        $multiplier = 2;
    }
    $totalCost = ceil($charsNeeded * $multiplier);

    if ($totalCost > $totalAvailable) {
        $db->rollBack();
        $msg = "Hết dung lượng. Còn: " . number_format($totalAvailable) . " (Cá nhân: " . number_format($personalRemaining) . ", Nhóm: " . number_format($teamRemaining) . ")";
        jsonResponse(['error' => $msg], 403);
    }

    $quotaRemaining = $totalAvailable;

    // System credits check
    $stmtTotal = $db->query("SELECT SUM(credits_remaining) FROM api_keys WHERE status = 'active' AND (cooldown_until IS NULL OR cooldown_until < NOW())");
    $systemTotalCredits = (int) $stmtTotal->fetchColumn();

    if ($systemTotalCredits < $charsNeeded) {
        $db->rollBack();
        jsonResponse(['error' => "Hệ thống quá tải (Thiếu Key). Cần: {$charsNeeded} ký tự. Hệ thống còn: " . number_format($systemTotalCredits)], 503);
    }

    // === DEDUCT QUOTA ===
    try {
        $toDeduct = $totalCost;
        $personalDeduct = min($toDeduct, $personalRemaining);
        $toDeduct -= $personalDeduct;

        if ($personalDeduct > 0) {
            $stmt = $db->prepare("UPDATE users SET quota_used = quota_used + ? WHERE id = ?");
            $stmt->execute([$personalDeduct, $userId]);
        }

        if ($toDeduct > 0 && $isTeamMember) {
            $stmt = $db->prepare("UPDATE users SET team_quota_used = team_quota_used + ? WHERE id = ?");
            $stmt->execute([$toDeduct, $userId]);
            $stmt = $db->prepare("UPDATE users SET quota_used = quota_used + ? WHERE id = ?");
            $stmt->execute([$toDeduct, $user['parent_id']]);
        }

        $jobId = 'CV-' . substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 7);

        // Pack conversation data as JSON into full_text
        // Worker sẽ parse JSON này để biết giọng nào nói gì
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

        // Insert job — voice_id = first speaker's voice (for display), full_text = JSON data
        $firstVoiceId = reset($speakersMap)['voice_id'] ?? '';
        $stmt = $db->prepare("INSERT INTO conversion_jobs (id, user_id, total_chunks, full_text, voice_id, model_id, job_type, voice_settings, status) VALUES (?, ?, ?, ?, ?, ?, 'conversation', ?, 'pending')");
        $voiceSettingsJson = $voiceSettings ? json_encode($voiceSettings) : null;
        $stmt->execute([$jobId, $userId, count($lines), $conversationData, $firstVoiceId, $modelId, $voiceSettingsJson]);

        // Log
        $textPreview = '';
        foreach (array_slice($lines, 0, 3) as $l) {
            $textPreview .= $l['speaker'] . '> ' . mb_substr($l['text'], 0, 30, 'UTF-8') . '... ';
        }
        $stmt = $db->prepare("INSERT INTO usage_logs (user_id, job_id, characters_used, api_key_id, worker_uuid, text_preview) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $jobId, $totalCost, null, 'PENDING', mb_substr($textPreview, 0, 100, 'UTF-8')]);

        $db->commit();

    } catch (Exception $e) {
        if ($db->inTransaction())
            $db->rollBack();
        jsonResponse(['error' => 'Không thể khởi tạo Job: ' . $e->getMessage()], 500);
    }

    // === DISPATCH ===
    $dispatch = dispatchJob($jobId);

    if (isset($dispatch['error'])) {
        jsonResponse([
            'status' => 'success',
            'job_id' => $jobId,
            'message' => 'Job đã được đưa vào hàng đợi: ' . $dispatch['error'],
            'quota_remaining' => $quotaRemaining - $totalCost
        ]);
    }

    jsonResponse([
        'status' => 'success',
        'job_id' => $jobId,
        'characters_used' => $charsNeeded,
        'total_cost' => $totalCost,
        'quota_remaining' => $quotaRemaining - $totalCost
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Lỗi Server: ' . $e->getMessage()], 500);
}
?>