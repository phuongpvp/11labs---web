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
$jobs = $input['jobs'] ?? []; // Array of {text, voice_id, model_id, voice_settings}

if (!$sessionToken || empty($jobs)) {
    jsonResponse(['error' => 'Token and jobs array required'], 400);
}

try {
    $db = getDB();
    $tokenData = verifyToken($sessionToken);
    if (!$tokenData) {
        jsonResponse(['error' => 'Phiên đăng nhập hết hạn hoặc không hợp lệ'], 401);
    }

    $userId = $tokenData['user_id'];

    // Migration: Add original_filename and sort_order columns if not exist
    try {
        $db->exec("ALTER TABLE conversion_jobs ADD COLUMN original_filename VARCHAR(255) NULL AFTER full_text");
    } catch (Exception $e) { /* Column exists */
    }
    try {
        $db->exec("ALTER TABLE conversion_jobs ADD COLUMN sort_order INT NULL AFTER original_filename");
    } catch (Exception $e) { /* Column exists */
    }

    // Start transaction
    $db->beginTransaction();

    // Lock user for quota check
    $stmt = $db->prepare("SELECT * FROM users WHERE id = ? FOR UPDATE");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();

    if (!$user) {
        $db->rollBack();
        jsonResponse(['error' => 'Tài khoản không tìm thấy'], 403);
    }

    if (strtotime($user['expires_at']) < time()) {
        $db->rollBack();
        jsonResponse(['error' => 'Gói cước của bạn đã hết hạn'], 403);
    }

    $totalCharsNeeded = 0;
    $autoSplit = $input['auto_split'] ?? false;
    $finalJobs = [];

    foreach ($jobs as $job) {
        $text = $job['text'] ?? '';
        if ($autoSplit) {
            // Updated Regex: Bắt số ở đầu dòng hoặc sau các dấu ngắt câu hoặc xuống dòng.
            // Only split when a number+period is the SOLE content on its own line
            // e.g. "1.\n" or "2.\n" — NOT "10. Warum..." which is mid-paragraph
            $segments = preg_split('/^\s*(\d+)\.\s*\r?\n/mu', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

            // If no markers found, treating as a single block
            if (count($segments) <= 1) {
                $finalJobs[] = $job;
            } else {
                for ($i = 0; $i < count($segments); $i++) {
                    // Check if this segment is a number (the captured marker)
                    if (is_numeric($segments[$i]) && isset($segments[$i + 1])) {
                        $num = $segments[$i];
                        $content = trim($segments[$i + 1]);
                        $i++; // Skip the content index in next loop

                        if ($content) {
                            $subJob = $job;
                            $subJob['text'] = $content;
                            // IMPORTANT: Generate a NEW unique Job ID for each segment
                            $subJob['job_id'] = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 8);

                            // Smart naming for segments: "1", "2"... 
                            // If user uploaded multiple files, we prefix with base name to avoid collisions
                            $origName = $job['original_filename'] ?? '';
                            $baseName = pathinfo($origName, PATHINFO_FILENAME);

                            if (count($jobs) === 1) {
                                // Just "1", "2" if only one input was split
                                $subJob['original_filename'] = "$num.txt";
                            } else {
                                // "Filename_1", "Filename_2" if multiple files uploaded
                                $subJob['original_filename'] = "{$baseName}_$num.txt";
                            }
                            $finalJobs[] = $subJob;
                        }
                    } else if ($i === 0 && !is_numeric($segments[$i])) {
                        // Text BEFORE the first "1. " marker - we'll ignore it or keep as segment 0
                        $content = trim($segments[$i]);
                        if ($content) {
                            $subJob = $job;
                            $subJob['text'] = $content;
                            $subJob['job_id'] = substr(str_shuffle("0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ"), 0, 8);

                            $origName = $job['original_filename'] ?? '';
                            $baseName = pathinfo($origName, PATHINFO_FILENAME);
                            if (count($jobs) === 1) {
                                $subJob['original_filename'] = "0.txt";
                            } else {
                                $subJob['original_filename'] = "{$baseName}_0.txt";
                            }
                            $finalJobs[] = $subJob;
                        }
                    }
                }
            }
        } else {
            $finalJobs[] = $job;
        }
    }
    $jobs = $finalJobs;

    foreach ($jobs as $job) {
        $text = $job['text'] ?? '';
        $mId = $job['model_id'] ?? 'eleven_v3';
        $multiplier = (strpos(strtolower($mId), 'v3') !== false) ? 2 : 1;
        $totalCharsNeeded += mb_strlen($text, 'UTF-8') * $multiplier;
    }

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

    if ($totalCharsNeeded > $totalAvailable) {
        $db->rollBack();
        $msg = "Hết dung lượng. Còn: " . number_format($totalAvailable) . " (Cá nhân: " . number_format($personalRemaining) . ", Nhóm: " . number_format($teamRemaining) . ")";
        jsonResponse(['error' => $msg], 403);
    }

    $quotaRemaining = $totalAvailable; // For display

    // Auto-deactivate exhausted keys
    $db->exec("UPDATE api_keys SET status = 'inactive' WHERE credits_remaining <= 0 AND status = 'active'");

    // For batching, we'll just check if we have enough active keys in general
    $stmt = $db->query("SELECT SUM(credits_remaining) as total_credits FROM api_keys WHERE status = 'active'");
    $credits = $stmt->fetch();
    if (($credits['total_credits'] ?? 0) < $totalCharsNeeded) {
        $db->rollBack();
        jsonResponse(['error' => "Hệ thống đang quá tải key. Vui lòng thử lại sau."], 503);
    }

    $currentPersonalRemaining = $personalRemaining;


    $registeredJobs = [];
    foreach ($jobs as $jobData) {
        $jobId = $jobData['job_id'] ?? bin2hex(random_bytes(16));
        $text = $jobData['text'] ?? '';
        $vId = $jobData['voice_id'] ?? '7WNwm0yUcEo1Hsfg5Bhk';
        $mId = $jobData['model_id'] ?? 'eleven_v3';
        $vSettings = $jobData['voice_settings'] ?? null;
        $originalFilename = $jobData['original_filename'] ?? null;
        $sortOrder = isset($jobData['sort_order']) ? (int) $jobData['sort_order'] : null;
        $jobChars = mb_strlen($text, 'UTF-8');

        // Insert Job Record as PENDING
        $stmtInsert = $db->prepare("INSERT INTO conversion_jobs (id, user_id, total_chunks, full_text, original_filename, sort_order, voice_id, model_id, voice_settings, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
        $stmtInsert->execute([$jobId, $userId, ceil($jobChars / 4500), $text, $originalFilename, $sortOrder, $vId, $mId, $vSettings ? json_encode($vSettings) : null]);

        // Update Quota (From User/Team)
        $multiplier = (strpos(strtolower($mId), 'v3') !== false) ? 2 : 1;
        $jobTotalCost = $jobChars * $multiplier;

        $toDeduct = $jobTotalCost;
        $personalDeduct = min($toDeduct, $currentPersonalRemaining);
        $toDeduct -= $personalDeduct;
        $currentPersonalRemaining -= $personalDeduct;

        if ($personalDeduct > 0) {
            $db->prepare("UPDATE users SET quota_used = quota_used + ? WHERE id = ?")->execute([$personalDeduct, $userId]);
        }

        if ($toDeduct > 0 && $isTeamMember) {
            $db->prepare("UPDATE users SET team_quota_used = team_quota_used + ? WHERE id = ?")->execute([$toDeduct, $userId]);
            $db->prepare("UPDATE users SET quota_used = quota_used + ? WHERE id = ?")->execute([$toDeduct, $user['parent_id']]);
        }

        // Log initial usage
        $db->prepare("INSERT INTO usage_logs (user_id, job_id, characters_used, api_key_id, worker_uuid, text_preview) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$userId, $jobId, $jobTotalCost, null, 'PENDING', mb_substr($text, 0, 100, 'UTF-8')]);

        $registeredJobs[] = ['id' => $jobId];
    }
    $db->commit();

    // 3. Dispatch each job immediately after commit
    foreach ($registeredJobs as $reg) {
        dispatchJob($reg['id']);
    }

    // Prepare response jobs with minimal info
    $responseJobs = [];
    foreach ($registeredJobs as $regJob) {
        $responseJobs[] = ['id' => $regJob['id']];
    }

    jsonResponse([
        'status' => 'success',
        'jobs' => $registeredJobs,
        'quota_remaining' => $totalAvailable - $totalCharsNeeded
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    jsonResponse(['error' => 'Lỗi Server: ' . $e->getMessage()], 500);
}
