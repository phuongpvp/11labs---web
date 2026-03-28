<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/telegram.php'; // Required for checkOfflineWorkers

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
$text = $input['text'] ?? '';
$voiceId = $input['voice_id'] ?? '7WNwm0yUcEo1Hsfg5Bhk';
$modelId = $input['model_id'] ?? 'eleven_v3';
$jobId = $input['job_id'] ?? '';
$voiceSettings = $input['voice_settings'] ?? null;
$voiceSettingsJson = $voiceSettings ? json_encode($voiceSettings) : null;

if (!$sessionToken || !$text) {
    jsonResponse(['error' => 'Token and text required'], 400);
}

// Validate text has meaningful content (not just emojis/special chars)
$cleanText = preg_replace('/[\x{1F000}-\x{1FFFF}]|[\x{2600}-\x{27BF}]|[\x{FE00}-\x{FEFF}]|[\x{200B}-\x{200F}]|[\x{2028}-\x{202F}]|[\x{E0000}-\x{E007F}]/u', '', $text);
$cleanText = preg_replace('/[^\p{L}\p{N}]/u', '', $cleanText);
if (mb_strlen(trim($cleanText), 'UTF-8') < 2) {
    jsonResponse(['error' => 'Văn bản quá ngắn hoặc không chứa nội dung hợp lệ (chỉ có emoji/ký tự đặc biệt)'], 400);
}

try {
    $db = getDB();

    // ...
    // Verify token
    $tokenData = verifyToken($sessionToken);
    if (!$tokenData) {
        jsonResponse(['error' => 'Phiên đăng nhập hết hạn hoặc không hợp lệ'], 401);
    }

    $userId = $tokenData['user_id'];

    // === ANTI-SPAM: Rate Limit + Parallel Limit (Tiered by Plan, Team-aware) ===
    $stmtUserInfo = $db->prepare("SELECT plan, parent_id, partner_api_key FROM users WHERE id = ?");
    $stmtUserInfo->execute([$userId]);
    $userInfo = $stmtUserInfo->fetch();
    $userPlan = $userInfo['plan'] ?? 'trial';
    $parentId = $userInfo['parent_id'] ?? null;
    $isPartner = !empty($userInfo['partner_api_key']);

    // Determine effective plan and team context
    $isTeamContext = false;
    $teamRootId = null;

    if ($parentId) {
        // User is a team MEMBER → use parent's plan, shared team pool
        $stmtParentPlan = $db->prepare("SELECT plan, partner_api_key FROM users WHERE id = ?");
        $stmtParentPlan->execute([$parentId]);
        $parentInfo = $stmtParentPlan->fetch();
        $effectivePlan = $parentInfo['plan'] ?? 'trial';
        $isTeamContext = true;
        $teamRootId = $parentId;
        // If parent is a partner, member also gets unlimited
        if (!empty($parentInfo['partner_api_key']))
            $isPartner = true;
    } else {
        // Check if user is a team OWNER (has members)
        $stmtHasMembers = $db->prepare("SELECT COUNT(*) FROM users WHERE parent_id = ?");
        $stmtHasMembers->execute([$userId]);
        if ($stmtHasMembers->fetchColumn() > 0) {
            $isTeamContext = true;
            $teamRootId = $userId;
        }
        $effectivePlan = $userPlan;
    }

    // Partner (đại lý) → không giới hạn luồng
    if (!$isPartner) {
        $planLimits = [
            'supper_vip' => ['processing' => 10, 'pending' => 7, 'cooldown' => 3],
            'premium' => ['processing' => 8, 'pending' => 5, 'cooldown' => 3],
            'pro' => ['processing' => 5, 'pending' => 3, 'cooldown' => 5],
            'basic' => ['processing' => 3, 'pending' => 2, 'cooldown' => 5],
            'trial' => ['processing' => 1, 'pending' => 1, 'cooldown' => 10],
        ];
        $limits = $planLimits[$effectivePlan] ?? $planLimits['trial'];

        // 1. Cooldown between submissions (per-user, effective plan tier)
        $stmtRecent = $db->prepare("SELECT created_at FROM conversion_jobs WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
        $stmtRecent->execute([$userId]);
        $lastJob = $stmtRecent->fetch();
        if ($lastJob) {
            $elapsed = time() - strtotime($lastJob['created_at']);
            if ($elapsed < $limits['cooldown']) {
                jsonResponse(['error' => 'Vui lòng chờ vài giây trước khi gửi tiếp. (' . ($limits['cooldown'] - $elapsed) . 's)'], 429);
            }
        }

        // 2. Max processing jobs (TEAM POOL if team, otherwise per-user)
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

        // 3. Max pending jobs in queue (per-user, effective plan tier)
        $stmtPend = $db->prepare("SELECT COUNT(*) FROM conversion_jobs WHERE user_id = ? AND status = 'pending'");
        $stmtPend->execute([$userId]);
        if ($stmtPend->fetchColumn() >= $limits['pending']) {
            jsonResponse(['error' => "Bạn đang có {$limits['pending']} job trong hàng đợi. Vui lòng chờ hoàn thành."], 429);
        }
    }

    // Start transaction with row locking — with deadlock retry
    $maxRetries = 3;
    for ($retry = 0; $retry < $maxRetries; $retry++) {
        try {
            $db->beginTransaction();

            // Lock the user row to prevent concurrent requests from reading stale quota
            $stmt = $db->prepare("SELECT * FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();

            if (!$user) {
                $db->rollBack();
                jsonResponse(['error' => 'Tài khoản không tìm thấy hoặc đã bị khóa'], 403);
            }

            // Check expiry
            if (strtotime($user['expires_at']) < time()) {
                $db->rollBack();
                jsonResponse(['error' => 'Gói cước của bạn đã hết hạn'], 403);
            }

            // Calculate characters
            $charsNeeded = mb_strlen($text, 'UTF-8');

            // --- ADDITIVE QUOTA CHECK ---
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

            if ($charsNeeded > $totalAvailable) {
                $db->rollBack();
                $msg = "Hết dung lượng. Còn: " . number_format($totalAvailable) . " (Cá nhân: " . number_format($personalRemaining) . ", Nhóm: " . number_format($teamRemaining) . ")";
                jsonResponse(['error' => $msg], 403);
            }

            $quotaRemaining = $totalAvailable; // For display

            // V15.6 Update: Simplified Key Check. Actual selection and deduction now handled by dispatchJob.
            $stmtTotal = $db->query("SELECT SUM(credits_remaining) FROM api_keys WHERE status = 'active' AND (cooldown_until IS NULL OR cooldown_until < NOW())");
            $systemTotalCredits = (int) $stmtTotal->fetchColumn();

            if ($systemTotalCredits < $charsNeeded) {
                $db->rollBack();
                jsonResponse(['error' => "Hệ thống quá tải (Thiếu Key). Cần: {$charsNeeded} ký tự. Hệ thống còn: " . number_format($systemTotalCredits)], 503);
            }

            // Register and Insert Job (Quota deduction)
            // 1. DEDUCT QUOTA (From User/Team)
            $totalCost = $charsNeeded;

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

            if (!$jobId) {
                $jobId = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 8);
            }

            // 2. Insert job record as PENDING
            $stmt = $db->prepare("INSERT INTO conversion_jobs (id, user_id, total_chunks, full_text, voice_id, model_id, voice_settings, status) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$jobId, $userId, ceil($charsNeeded / 4500), $text, $voiceId, $modelId, $voiceSettingsJson]);

            // 3. Log initial entry
            $stmt = $db->prepare("INSERT INTO usage_logs (user_id, job_id, characters_used, api_key_id, worker_uuid, text_preview) VALUES (?, ?, ?, ?, ?, ?)");
            $stmt->execute([$userId, $jobId, $totalCost, null, 'PENDING', mb_substr($text, 0, 100, 'UTF-8')]);

            $db->commit();
            break; // Transaction succeeded, exit retry loop

        } catch (Exception $e) {
            if ($db->inTransaction())
                $db->rollBack();
            // Check if it's a deadlock error (SQLSTATE 40001 or error code 1213)
            if (($e->getCode() == '40001' || strpos($e->getMessage(), '1213') !== false) && $retry < $maxRetries - 1) {
                usleep(rand(50000, 200000)); // Wait 50-200ms before retry
                continue;
            }
            jsonResponse(['error' => 'Không thể khởi tạo Job: ' . $e->getMessage()], 500);
        }
    }

    // 3. DISPATCH IMMEDIATELY
    $dispatch = dispatchJob($jobId);

    if (isset($dispatch['error'])) {
        // Job is already in DB as 'pending', so dispatcher will pick it up later
        // But we return success to user because the job is queued
        jsonResponse([
            'status' => 'success',
            'job_id' => $jobId,
            'message' => 'Job đã được đưa vào hàng đợi: ' . $dispatch['error'],
            'quota_remaining' => $quotaRemaining - $charsNeeded
        ]);
    }

    jsonResponse([
        'status' => 'success',
        'job_id' => $jobId,
        'characters_used' => $charsNeeded,
        'quota_remaining' => $quotaRemaining - $charsNeeded
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Lỗi Server: ' . $e->getMessage()], 500);
}
?>