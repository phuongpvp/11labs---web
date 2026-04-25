<?php
require_once __DIR__ . '/config.php';

// Handle OPTIONS for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

$input = json_decode(file_get_contents('php://input'), true);
$method = $_SERVER['REQUEST_METHOD'];

// Add logging for debugging
if ($method === 'POST') {
    error_log("Progress POST: " . json_encode($input));
}

// GET for Polling (from Customer Portal)
if ($method === 'GET') {
    $jobId = $_GET['job_id'] ?? '';
    if (!$jobId)
        jsonResponse(['error' => 'Missing job_id'], 400);

    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT total_chunks, processed_chunks, status FROM conversion_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();

        if (!$job)
            jsonResponse(['error' => 'Job not found'], 404);

        if ($job['status'] === 'completed') {
            $job['audio_url'] = PHP_BACKEND_URL . '/api/results/' . $jobId . '.mp3';
            $job['srt_url'] = PHP_BACKEND_URL . '/api/results/' . $jobId . '.srt';
            // Alias for frontend compatibility
            $job['audioUrl'] = $job['audio_url'];
            $job['srtUrl'] = $job['srt_url'];
        }
        // Sanitize status for external API consumers — show friendly error for known cases
        $rawStatus = $job['status'];
        if (strpos($rawStatus, 'failed') !== false) {
            $job['status'] = 'failed';
            // Extract the error detail after "failed: "
            $errorDetail = '';
            if (strpos($rawStatus, 'failed:') !== false) {
                $errorDetail = trim(substr($rawStatus, strpos($rawStatus, 'failed:') + 7));
            }
            // Show specific message for known user-actionable errors
            if (stripos($errorDetail, 'Giọng nói') !== false || stripos($errorDetail, 'Voice') !== false) {
                $job['error_message'] = $errorDetail;
            } elseif (stripos($errorDetail, 'Model') !== false) {
                $job['error_message'] = $errorDetail;
            } elseif (stripos($errorDetail, 'chính sách') !== false || stripos($errorDetail, 'Nội dung') !== false || stripos($errorDetail, 'từ chối') !== false || stripos($errorDetail, 'violate') !== false) {
                $job['error_message'] = 'Nội dung hoặc giọng đọc bị chặn bởi chính sách ElevenLabs. Vui lòng thử đổi giọng hoặc chỉnh sửa nội dung.';
            } else {
                // Generic message for internal/unknown errors
                $job['error_message'] = 'Đã xảy ra lỗi khi xử lý, vui lòng thử lại.';
            }
        } elseif ($rawStatus === 'retrying') {
            $job['status'] = 'processing';  // Show as still processing to API consumers
        }

        jsonResponse([
            'status' => 'success',
            'job' => $job
        ]);
    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
    }
}

// POST for Reporting (from Colab) or Registration (from convert.php)
if ($method === 'POST') {
    $action = $input['action'] ?? '';
    $jobId = $input['job_id'] ?? '';

    try {
        $db = getDB();

        // --- STANDALONE ACTIONS (No Job ID required) ---

        if ($action === 'sync_key') {
            $keyId = $input['key_id'] ?? null;
            if ($keyId) {
                // V17.9: Only update credits if worker explicitly provides them
                // Previously defaulted to 0 when get_api_credits failed, causing false zero
                if (isset($input['credits']) && $input['credits'] !== null) {
                    $credits = $input['credits'];
                    $cleanCredits = str_replace('.', '', $credits);
                    $stmt = $db->prepare("UPDATE api_keys SET credits_remaining = ?, last_checked = NOW() WHERE id = ?");
                    $stmt->execute([$cleanCredits, $keyId]);
                    logToFile('admin_actions.log', "WORKER SYNC: Key $keyId reported $cleanCredits credits (Raw: $credits).");
                } else {
                    // No credits data — only update timestamp, don't touch credits_remaining
                    $db->prepare("UPDATE api_keys SET last_checked = NOW() WHERE id = ?")->execute([$keyId]);
                    logToFile('admin_actions.log', "WORKER SYNC: Key $keyId synced (no credit data — keeping existing value).");
                }
                jsonResponse(['status' => 'success', 'message' => 'Key synced']);
            }
            jsonResponse(['error' => 'Missing key_id'], 400);
        }

        if ($action === 'flag_key') {
            $keyId = $input['key_id'] ?? null;
            $reason = $input['reason'] ?? 'Unknown error';
            if ($keyId) {
                // If it's a quota issue, set credits to 0
                if (strpos(strtolower($reason), 'quota') !== false || strpos(strtolower($reason), 'enough') !== false) {
                    $db->prepare("UPDATE api_keys SET credits_remaining = 0, last_checked = NOW() WHERE id = ?")->execute([$keyId]);
                } else {
                    // 401/429 Cooldown
                    $db->prepare("UPDATE api_keys SET cooldown_until = (NOW() + INTERVAL 15 MINUTE), last_checked = NOW() WHERE id = ?")->execute([$keyId]);
                }
                logToFile('admin_actions.log', "Worker flagged Key $keyId: $reason");
                jsonResponse(['status' => 'success', 'message' => 'Key flagged']);
            }
            jsonResponse(['error' => 'Missing key_id'], 400);
        }

        if ($action === 'worker_failed') {
            $workerUuid = $input['worker_uuid'] ?? '';
            if ($workerUuid) {
                $db->prepare("UPDATE workers SET failed_jobs = failed_jobs + 1 WHERE worker_uuid = ?")->execute([$workerUuid]);
                jsonResponse(['status' => 'success']);
            }
            jsonResponse(['error' => 'Missing worker_uuid'], 400);
        }

        if ($action === 'release_job') {
            // Worker releases a partially-completed job for another worker to continue
            $jobId = $input['job_id'] ?? '';
            $partialAudio = $input['partial_audio_base64'] ?? '';
            $processedChars = (int) ($input['processed_chars'] ?? 0);
            $workerUuid = $input['worker_uuid'] ?? '';
            $previousChunkText = $input['previous_chunk_text'] ?? '';

            if (!$jobId)
                jsonResponse(['error' => 'Missing job_id'], 400);

            // Lazy schema: add columns if not exist
            try {
                $db->exec("ALTER TABLE conversion_jobs ADD COLUMN partial_audio_path VARCHAR(500) NULL");
            } catch (Exception $e) {
            }
            try {
                $db->exec("ALTER TABLE conversion_jobs ADD COLUMN processed_chars INT DEFAULT 0");
            } catch (Exception $e) {
            }
            try {
                $db->exec("ALTER TABLE conversion_jobs ADD COLUMN previous_chunk_text TEXT NULL");
            } catch (Exception $e) {
            }

            // Save partial audio to disk
            $partialPath = null;
            if ($partialAudio) {
                $resultsDir = __DIR__ . '/results';
                if (!is_dir($resultsDir))
                    mkdir($resultsDir, 0755, true);
                $partialPath = $resultsDir . '/' . $jobId . '_partial.mp3';
                file_put_contents($partialPath, base64_decode($partialAudio));
                logToFile('admin_actions.log', "RELEASE_JOB: Saved partial audio for $jobId ($processedChars chars done)");
            }

            // Fetch job for refund calculation
            $stmt = $db->prepare("SELECT j.user_id, j.full_text, j.model_id, j.attempts, u.parent_id 
                                  FROM conversion_jobs j JOIN users u ON j.user_id = u.id WHERE j.id = ?");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch();

            if (!$job)
                jsonResponse(['error' => 'Job not found'], 404);

            $attempts = (int) ($job['attempts'] ?? 0);
            $totalChars = mb_strlen($job['full_text'], 'UTF-8');

            // Increment attempts counter
            $db->prepare("UPDATE conversion_jobs SET attempts = attempts + 1 WHERE id = ?")->execute([$jobId]);
            $attempts++;

            // === RELEASE LIMIT: Nếu đã release >= 5 lần → fail luôn, không nhả tiếp ===
            if ($attempts >= 5) {
                logToFile('admin_actions.log', "RELEASE_JOB LIMIT: Job $jobId đã release $attempts lần. Hủy job và hoàn trả ký tự.");

                // Full refund
                if ($totalChars > 0) {
                    $db->beginTransaction();
                    try {
                        $stmtU = $db->prepare("SELECT quota_used, team_quota_used, parent_id FROM users WHERE id = ? FOR UPDATE");
                        $stmtU->execute([$job['user_id']]);
                        $userData = $stmtU->fetch();

                        if ($userData) {
                            $personalRefund = min($totalChars, $userData['quota_used']);
                            $teamRefund = $totalChars - $personalRefund;

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
                        $db->commit();
                    } catch (Exception $e) {
                        $db->rollBack();
                    }
                }

                // Mark as failed
                $failMsg = "failed: Bị lỗi hoặc kiệt sức trên nhiều máy chủ sau $attempts lần nhảy. Vui lòng thử lại sau. (Đã hoàn trả $totalChars ký tự)";
                $db->prepare("UPDATE conversion_jobs SET status = ?, worker_uuid = NULL WHERE id = ?")
                    ->execute([mb_substr($failMsg, 0, 255, 'UTF-8'), $jobId]);

                // Log refund to worker_logs for admin visibility
                try {
                    $refundMsg = "🚫 Job $jobId bị hủy sau $attempts lần nhảy máy chủ. Đã hoàn trả $totalChars ký tự cho User {$job['user_id']}.";
                    $stmtL = $db->prepare("INSERT INTO worker_logs (worker_uuid, worker_name, job_id, message, level) VALUES (?, ?, ?, ?, ?)");
                    $stmtL->execute(['SYSTEM', 'System Engine', $jobId, $refundMsg, 'error']);
                } catch (Exception $e) {}

                // Cooldown the failing worker
                if ($workerUuid) {
                    $db->prepare("UPDATE workers SET cooldown_until = (NOW() + INTERVAL 5 MINUTE) WHERE worker_uuid = ?")
                        ->execute([$workerUuid]);
                }

                jsonResponse(['status' => 'failed', 'message' => "Job cancelled after $attempts release attempts"]);
            }

            // === NORMAL RELEASE: attempts < 5, nhả cho máy khác ===

            // Update job: reset to pending with partial progress info
            $partialDbPath = $partialPath ? ($jobId . '_partial.mp3') : null;
            $stmt = $db->prepare("UPDATE conversion_jobs SET status = 'pending', worker_uuid = NULL, 
                                  partial_audio_path = ?, processed_chars = ?, previous_chunk_text = ? WHERE id = ?");
            $stmt->execute([$partialDbPath, $processedChars, $previousChunkText ?: null, $jobId]);

            // Cooldown the failing worker
            if ($workerUuid) {
                $db->prepare("UPDATE workers SET cooldown_until = (NOW() + INTERVAL 5 MINUTE) WHERE worker_uuid = ?")
                    ->execute([$workerUuid]);
            }

            logToFile('admin_actions.log', "RELEASE_JOB: Job $jobId released (attempt $attempts/5). $processedChars/$totalChars chars done. Redispatching...");

            // Immediate redispatch
            sleep(2);
            $result = dispatchJob($jobId);
            if (isset($result['error'])) {
                logToFile('admin_actions.log', "RELEASE_JOB REDISPATCH FAILED: Job $jobId - {$result['error']}");
            } else {
                logToFile('admin_actions.log', "RELEASE_JOB REDISPATCH OK: Job $jobId -> worker {$result['worker']}");
            }

            jsonResponse(['status' => 'success', 'message' => 'Job released and redispatched']);
        }

        // --- JOB-SPECIFIC ACTIONS (JobID required) ---

        if (!$jobId)
            jsonResponse(['error' => 'Missing job_id'], 400);

        if ($action === 'register') {
            $totalChunks = $input['total_chunks'] ?? 0;
            $userId = $input['user_id'] ?? 0;

            // Simple table creation if not exists (Lazy init)
            $db->exec("CREATE TABLE IF NOT EXISTS conversion_jobs (
                id VARCHAR(64) PRIMARY KEY,
                user_id INT,
                total_chunks INT,
                processed_chunks INT DEFAULT 0,
                status VARCHAR(255) DEFAULT 'processing',
                api_key_ids TEXT,
                worker_uuid VARCHAR(255),
                full_text TEXT,
                voice_id VARCHAR(255),
                model_id VARCHAR(255),
                voice_settings TEXT,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )");

            // Ensure column exists for older databases
            try {
                $db->exec("ALTER TABLE conversion_jobs ADD COLUMN api_key_ids TEXT AFTER status");
            } catch (Exception $e) {
                // Ignore if exists
            }

            $stmt = $db->prepare("INSERT INTO conversion_jobs (id, user_id, total_chunks) VALUES (?, ?, ?)");
            $stmt->execute([$jobId, $userId, $totalChunks]);

            jsonResponse(['status' => 'success', 'message' => 'Job registered']);
        }

        if ($action === 'update') {
            $processed = $input['processed_chunks'] ?? 0;
            $status = $input['status'] ?? 'processing';
            $total = $input['total_chunks'] ?? null;

            // Fetch current job stats for refund calculation if failing
            $stmt = $db->prepare("SELECT j.user_id, j.total_chunks, j.processed_chunks, j.status as old_status, j.full_text, j.api_key_ids, u.parent_id 
                                  FROM conversion_jobs j 
                                  JOIN users u ON j.user_id = u.id 
                                  WHERE j.id = ?");
            $stmt->execute([$jobId]);
            $job = $stmt->fetch();

            if (!$job)
                jsonResponse(['error' => 'Job not found', 'cancel' => true], 404);

            // Log Success to completed_jobs.log
            if ($status === 'completed' && $job['old_status'] !== 'completed') {
                $apiKeyInfo = $db->prepare("SELECT api_key_ids FROM conversion_jobs WHERE id = ?");
                $apiKeyInfo->execute([$jobId]);
                $keyIds = $apiKeyInfo->fetchColumn() ?: 'N/A';

                $logMsg = "Job $jobId COMPLETED. User: {$job['user_id']}. Key(s): $keyIds";
                logToFile('completed_jobs.log', $logMsg);
                error_log("Progress: Logged completion for $jobId to completed_jobs.log");
            }

            // Handle Full Refund if transition to 'failed' (only from first failure, NOT from retrying→failed)
            if (strpos($status, 'failed') !== false && strpos($job['old_status'], 'failed') === false && $job['old_status'] !== 'retrying') {
                $fullText = $job['full_text'] ?? '';
                $totalChars = mb_strlen($fullText, 'UTF-8');

                // Log the failure
                logToFile('failed_jobs.log', "Job $jobId failed for User {$job['user_id']}. Status: $status. Text preview: " . mb_substr($fullText, 0, 50, 'UTF-8') . "...");

                // V17.8: AUTO-COOLDOWN for 401/429 errors only
                // REMOVED AUTO-ZERO for quota_exceeded — worker already syncs real credits via sync_key action.
                // The old AUTO-ZERO was OVERWRITING accurate credits (e.g. 866) with 0, causing keys to appear depleted.
                if (strpos($status, 'detected_unusual_activity') !== false || strpos($status, 'too_many_concurrent_requests') !== false) {
                    $keyIdsRaw = $job['api_key_ids'] ?? '';
                    if ($keyIdsRaw) {
                        $ids = explode(',', str_replace(' ', '', $keyIdsRaw));
                        foreach ($ids as $kid) {
                            if (is_numeric($kid)) {
                                $stmtFlag = $db->prepare("UPDATE api_keys SET cooldown_until = (NOW() + INTERVAL 15 MINUTE) WHERE id = ?");
                                $stmtFlag->execute([$kid]);
                                logToFile('admin_actions.log', "AUTO-COOLDOWN: Key $kid flagged for 15 mins. Reason: 401/429 Unusual/Concurrency.");
                            }
                        }
                        $status .= " [Hệ thống đã tự động xử lý Key lỗi]";
                    }
                }

                if ($totalChars > 0) {
                    // Start transaction to update quotas safely
                    $db->beginTransaction();
                    try {
                        // Fetch fresh user data
                        $stmtU = $db->prepare("SELECT quota_used, team_quota_used, parent_id FROM users WHERE id = ? FOR UPDATE");
                        $stmtU->execute([$job['user_id']]);
                        $userData = $stmtU->fetch();

                        if ($userData) {
                            $personalRefund = min($totalChars, $userData['quota_used']);
                            $teamRefund = $totalChars - $personalRefund;

                            // 1. Refund Personal
                            if ($personalRefund > 0) {
                                $db->prepare("UPDATE users SET quota_used = quota_used - ? WHERE id = ?")
                                    ->execute([$personalRefund, $job['user_id']]);
                            }

                            // 2. Refund Team (if applicable)
                            if ($teamRefund > 0 && !empty($userData['parent_id'])) {
                                // Refund Member's individual shared limit
                                $db->prepare("UPDATE users SET team_quota_used = team_quota_used - ? WHERE id = ?")
                                    ->execute([$teamRefund, $job['user_id']]);

                                // Refund Parent's main quota
                                $db->prepare("UPDATE users SET quota_used = quota_used - ? WHERE id = ?")
                                    ->execute([$teamRefund, $userData['parent_id']]);
                            }
                        }
                        $db->commit();
                        $status .= " (Đã hoàn trả $totalChars ký tự)";

                        // V17.2: Log credit refund to worker_logs for admin visibility
                        try {
                            $refundMsg = "Hệ thống đã hoàn trả $totalChars ký tự cho User {$job['user_id']} vì Job $jobId bị gián đoạn.";
                            $stmtL = $db->prepare("INSERT INTO worker_logs (worker_uuid, worker_name, job_id, message, level) VALUES (?, ?, ?, ?, ?)");
                            $stmtL->execute(['SYSTEM', 'System Engine', $jobId, $refundMsg, 'info']);
                        } catch (Exception $e) {
                        }

                    } catch (Exception $e) {
                        $db->rollBack();
                        error_log("Refund Error for Job $jobId: " . $e->getMessage());
                        $status .= " (Lỗi hoàn trả)";
                    }
                }
            }

            // Truncate status AFTER all modifications (max 255 chars to be safe)
            $status = mb_substr($status, 0, 255, 'UTF-8');

            if ($total !== null) {
                $stmt = $db->prepare("UPDATE conversion_jobs SET processed_chunks = ?, status = ?, total_chunks = ? WHERE id = ?");
                $stmt->execute([$processed, $status, $total, $jobId]);
            } else {
                $stmt = $db->prepare("UPDATE conversion_jobs SET processed_chunks = ?, status = ? WHERE id = ?");
                $stmt->execute([$processed, $status, $jobId]);
            }

            // === STEP 1: Cooldown + Telegram + Auto-restart for IP blocks (MUST run BEFORE redispatch) ===
            if (strpos($status, 'detected_unusual_activity') !== false || strpos($status, '401') !== false) {
                $workerUuid = $input['worker_uuid'] ?? '';
                if (!$workerUuid) {
                    $stmtW = $db->prepare("SELECT worker_uuid FROM conversion_jobs WHERE id = ?");
                    $stmtW->execute([$jobId]);
                    $workerUuid = $stmtW->fetchColumn();
                }

                if ($workerUuid) {
                    // Cooldown worker so dispatcher won't pick it again
                    $stmtCooldown = $db->prepare("UPDATE workers SET cooldown_until = (NOW() + INTERVAL 5 MINUTE) WHERE worker_uuid = ?");
                    $stmtCooldown->execute([$workerUuid]);

                    // Telegram Alert for Admin
                    $stmtWInfo = $db->prepare("SELECT ip_address, url, worker_name FROM workers WHERE worker_uuid = ?");
                    $stmtWInfo->execute([$workerUuid]);
                    $wInfo = $stmtWInfo->fetch();
                    if ($wInfo) {
                        // === LOG: Key usage diagnostics before IP block ===
                        try {
                            // Ensure ip_block_logs table exists
                            $db->exec("CREATE TABLE IF NOT EXISTS ip_block_logs (
                                id INT AUTO_INCREMENT PRIMARY KEY,
                                worker_name VARCHAR(100),
                                worker_uuid VARCHAR(64),
                                worker_ip VARCHAR(50),
                                reason VARCHAR(100),
                                key_details JSON,
                                jobs_completed INT DEFAULT 0,
                                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                            ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

                            // Add jobs_completed and chars_used columns if missing (for existing tables)
                            try {
                                $db->exec("ALTER TABLE ip_block_logs ADD COLUMN jobs_completed INT DEFAULT 0 AFTER key_details");
                            } catch (Exception $e) {
                            }
                            try {
                                $db->exec("ALTER TABLE ip_block_logs ADD COLUMN chars_used INT DEFAULT 0 AFTER jobs_completed");
                            } catch (Exception $e) {
                            }

                            // Count completed jobs and total chars for this worker since it connected
                            $stmtJobCount = $db->prepare("
                                SELECT COUNT(*) as cnt, COALESCE(SUM(LENGTH(full_text)), 0) as total_chars
                                FROM conversion_jobs 
                                WHERE worker_uuid = ? AND status = 'completed'
                                AND updated_at >= (SELECT connected_at FROM workers WHERE worker_uuid = ? LIMIT 1)
                            ");
                            $stmtJobCount->execute([$workerUuid, $workerUuid]);
                            $jobStats = $stmtJobCount->fetch(PDO::FETCH_ASSOC);
                            $jobsCompleted = (int) ($jobStats['cnt'] ?? 0);
                            $charsUsed = (int) ($jobStats['total_chars'] ?? 0);

                            // Get all keys assigned to this blocked worker
                            $stmtKeyCount = $db->prepare("
                                SELECT ak.id, LEFT(ak.key_encrypted, 12) as key_preview, 
                                       CAST(REPLACE(ak.credits_remaining, '.', '') AS SIGNED) as credits,
                                       ak.assigned_worker_uuid
                                FROM api_keys ak 
                                WHERE ak.assigned_worker_uuid = ? AND ak.status = 'active'
                            ");
                            $stmtKeyCount->execute([$workerUuid]);
                            $assignedKeys = $stmtKeyCount->fetchAll(PDO::FETCH_ASSOC);

                            // For each key, find which OTHER workers have used the same key recently
                            $keyDetailsArray = [];
                            foreach ($assignedKeys as $key) {
                                // Find other workers that share this key's IP or had this key recently
                                $stmtOtherWorkers = $db->prepare("
                                    SELECT DISTINCT w.worker_name 
                                    FROM workers w 
                                    WHERE w.worker_uuid != ? 
                                      AND w.worker_uuid IN (
                                          SELECT assigned_worker_uuid FROM api_keys 
                                          WHERE id = ? AND assigned_worker_uuid IS NOT NULL
                                      )
                                ");
                                $stmtOtherWorkers->execute([$workerUuid, $key['id']]);
                                $otherWorkers = $stmtOtherWorkers->fetchAll(PDO::FETCH_COLUMN);

                                $keyDetailsArray[] = [
                                    'key_id' => $key['id'],
                                    'key_preview' => $key['key_preview'],
                                    'credits' => $key['credits'],
                                    'shared_with' => $otherWorkers
                                ];
                            }

                            $totalCredits = array_sum(array_column($assignedKeys, 'credits'));
                            $keyIds = array_map(fn($k) => "#{$k['id']}({$k['credits']}cr)", $assignedKeys);

                            // Determine block reason
                            $blockReason = 'unknown';
                            if (strpos($status, 'detected_unusual_activity') !== false) {
                                $blockReason = 'ip_blocked';
                            } elseif (strpos($status, 'quota_exceeded') !== false) {
                                $blockReason = 'quota_exceeded';
                            } elseif (strpos($status, '401') !== false) {
                                $blockReason = 'auth_error';
                            }

                            // Save to DB
                            $stmtInsert = $db->prepare("INSERT INTO ip_block_logs (worker_name, worker_uuid, worker_ip, reason, key_details, jobs_completed, chars_used) VALUES (?, ?, ?, ?, ?, ?, ?)");
                            $stmtInsert->execute([
                                $wInfo['worker_name'] ?? $workerUuid,
                                $workerUuid,
                                $wInfo['ip_address'] ?? 'N/A',
                                $blockReason,
                                json_encode($keyDetailsArray, JSON_UNESCAPED_UNICODE),
                                $jobsCompleted,
                                $charsUsed
                            ]);

                            // Still log to file for backup
                            logToFile('ip_block_analysis.log', sprintf(
                                "BLOCKED: %s | IP: %s | Reason: %s | Jobs Done: %d | Chars Used: %d | Keys: %d | Total Credits: %d | Key Details: [%s]",
                                $wInfo['worker_name'] ?? $workerUuid,
                                $wInfo['ip_address'] ?? 'N/A',
                                $blockReason,
                                $jobsCompleted,
                                $charsUsed,
                                count($assignedKeys),
                                $totalCredits,
                                implode(', ', $keyIds)
                            ));
                        } catch (Exception $e) { /* Ignore log errors */
                        }

                        require_once __DIR__ . '/telegram.php';
                        notifyWorkerBlocked($workerUuid, $wInfo['ip_address'], $wInfo['url'], $status, $wInfo['worker_name'] ?? '');

                        // AUTO-RESTART: ONLY for detected_unusual_activity (IP blocked)
                        if (
                            strpos($status, 'detected_unusual_activity') !== false
                            && strpos($status, 'quota_exceeded') === false
                        ) {
                            $wName = $wInfo['worker_name'] ?? '';
                            $extName = preg_replace('/^(?:Sever|Server|Sv)/i', 'Sv', $wName);
                            if ($extName) {
                                $scheduled = scheduleWorkerRestart($extName, "IP blocked: $status", $wInfo['url'] ?? '');
                                if ($scheduled) {
                                    logToFile('auto_restart.log', "AUTO-RESTART triggered for $extName (worker: $wName, uuid: $workerUuid)");
                                }
                            }
                        }
                    }
                }
            }

            // === STEP 2: Auto-Redispatch failed jobs to a different worker ===
            // Worker is already in cooldown (step 1), so dispatcher picks a different one.
            try {
                $rawStatus = $input['status'] ?? '';
                // WHITELIST approach: only retry for known transient/infrastructure errors
                // Everything else (TOS, paid tier, voice not found, etc.) is permanent — do NOT retry
                $isRetryable = strpos($rawStatus, 'failed') !== false && (
                    strpos($rawStatus, 'detected_unusual_activity') !== false
                    || strpos($rawStatus, 'quota_exceeded') !== false
                    || strpos($rawStatus, '429') !== false
                    || strpos($rawStatus, '401') !== false
                    || strpos($rawStatus, 'timeout') !== false
                    || strpos($rawStatus, 'connection') !== false
                    || strpos($rawStatus, 'server_error') !== false
                    || strpos($rawStatus, '500') !== false
                    || strpos($rawStatus, '502') !== false
                    || strpos($rawStatus, '503') !== false
                );

                if ($isRetryable) {
                    $attempts = 0;
                    try {
                        $stmtAttempts = $db->prepare("SELECT attempts FROM conversion_jobs WHERE id = ?");
                        $stmtAttempts->execute([$jobId]);
                        $attempts = (int) $stmtAttempts->fetchColumn();
                    } catch (Exception $eAttempts) {
                    }

                    $isIpBlock = strpos($rawStatus, 'detected_unusual_activity') !== false;
                    $logPrefix = $isIpBlock ? 'AUTO-REDISPATCH (IP BLOCK)' : 'AUTO-REDISPATCH';
                    logToFile('admin_actions.log', "$logPrefix: Job $jobId failed. Attempts: $attempts. Raw status: " . substr($rawStatus, 0, 80));

                    if ($attempts < 5) {
                        // Set 'retrying' immediately so API consumers don't see 'failed'
                        $db->prepare("UPDATE conversion_jobs SET status = 'retrying', worker_uuid = NULL WHERE id = ?")
                            ->execute([$jobId]);
                        logToFile('admin_actions.log', "$logPrefix: Job $jobId set to retrying. Dispatching to another worker...");

                        sleep(2);
                        $redispatch = dispatchJob($jobId);
                        if (isset($redispatch['error'])) {
                            logToFile('admin_actions.log', "$logPrefix FAILED: Job $jobId - {$redispatch['error']}");
                            // Redispatch failed — set back to pending for cron recovery
                            $db->prepare("UPDATE conversion_jobs SET status = 'pending' WHERE id = ?")->execute([$jobId]);
                        } else {
                            logToFile('admin_actions.log', "$logPrefix SUCCESS: Job $jobId -> worker {$redispatch['worker']}");
                        }
                    } else {
                        logToFile('admin_actions.log', "$logPrefix SKIP: Job $jobId already at $attempts/5 attempts.");
                    }

                    jsonResponse(['status' => 'success', 'message' => 'Job updated and auto-redispatched', 'refunded' => $refundChars ?? 0]);
                }
            } catch (Exception $eRedispatch) {
                logToFile('admin_actions.log', "AUTO-REDISPATCH ERROR: Job $jobId - " . $eRedispatch->getMessage());
            }

            // V15.2 Update: Trigger Next Job in queue for this user
            if ($status === 'completed' || strpos($status, 'failed') !== false) {

                // V20: Reduced delay to 2-3s (was 5-10s) — faster throughput for multi-job users
                $delay = rand(2, 3);
                sleep($delay);

                // V20: Dispatch up to 10 pending jobs to fill parallel slots according to plan limits
                // dispatchJob() already enforces plan-based parallel limits internally
                $stmt_next = $db->prepare("SELECT id FROM conversion_jobs WHERE user_id = ? AND status = 'pending' ORDER BY created_at ASC LIMIT 10");
                $stmt_next->execute([$job['user_id']]);
                $nextJobs = $stmt_next->fetchAll(PDO::FETCH_COLUMN);
                foreach ($nextJobs as $nextJobId) {
                    dispatchJob($nextJobId);
                }
            }

            jsonResponse(['status' => 'success', 'message' => 'Job updated', 'refunded' => $refundChars ?? 0]);
        }

        if ($action === 'sync_key') {
            // Already handled above as standalone, but kept for legacy/safety
            jsonResponse(['status' => 'success', 'message' => 'Key already synced via standalone block']);
        }

        if ($action === 'flag_key') {
            $keyId = $input['key_id'] ?? null;
            $reason = $input['reason'] ?? 'unknown';
            if ($keyId) {
                // Set cooldown for 15 minutes
                $stmt = $db->prepare("UPDATE api_keys SET cooldown_until = (NOW() + INTERVAL 15 MINUTE) WHERE id = ?");
                $stmt->execute([$keyId]);
                logToFile('admin_actions.log', "AUTO-COOLDOWN: Key $keyId flagged for 15 mins. Reason: $reason");
            }
            jsonResponse(['status' => 'success', 'message' => 'Key moved to cooldown']);
        }

        if ($action === 'worker_failed') {
            $workerUuid = $input['worker_uuid'] ?? '';
            if ($workerUuid) {
                $db->prepare("UPDATE workers SET failed_jobs = failed_jobs + 1 WHERE worker_uuid = ?")
                    ->execute([$workerUuid]);
            }
            jsonResponse(['status' => 'success', 'message' => 'Worker failure recorded']);
        }

        jsonResponse(['error' => 'Invalid action'], 400);

    } catch (Exception $e) {
        jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
    }
}
