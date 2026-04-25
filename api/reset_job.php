<?php
require_once __DIR__ . '/config.php';

// === ACTION: Reset a specific job ===
if (isset($_GET['reset']) && !empty($_GET['reset'])) {
    $jobId = $_GET['reset'];
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, status FROM conversion_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();

        if (!$job) {
            $msg = "❌ Job $jobId không tồn tại.";
        } else {
            $db->prepare("UPDATE conversion_jobs SET status = 'pending', worker_uuid = NULL, processed_chunks = 0, attempts = 0 WHERE id = ?")->execute([$jobId]);

            sleep(1);
            $result = dispatchJob($jobId);

            if (isset($result['error'])) {
                $msg = "⚠️ Job $jobId đã reset về pending nhưng dispatch lỗi: {$result['error']}";
            } else {
                $worker = $result['worker'] ?? 'unknown';
                $msg = "✅ Job $jobId đã reset và dispatch tới worker: $worker";
            }
        }
    } catch (Exception $e) {
        $msg = "❌ Lỗi: " . $e->getMessage();
    }
    header("Location: reset_job.php?msg=" . urlencode($msg));
    exit;
}

// === ACTION: Delete a specific job ===
if (isset($_GET['delete']) && !empty($_GET['delete'])) {
    $jobId = $_GET['delete'];
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT id, status, user_id, full_text FROM conversion_jobs WHERE id = ?");
        $stmt->execute([$jobId]);
        $job = $stmt->fetch();

        if (!$job) {
            $msg = "❌ Job $jobId không tồn tại.";
        } else {
            $db->prepare("DELETE FROM conversion_jobs WHERE id = ?")->execute([$jobId]);
            $msg = "🗑️ Job $jobId đã được xóa.";
        }
    } catch (Exception $e) {
        $msg = "❌ Lỗi: " . $e->getMessage();
    }
    header("Location: reset_job.php?msg=" . urlencode($msg));
    exit;
}

// === ACTION: Reset ALL stuck jobs ===
if (isset($_GET['reset_all'])) {
    try {
        $db = getDB();
        // Same criteria as the list below
        $stmt = $db->query("
            SELECT id FROM conversion_jobs 
            WHERE (
                (status IS NULL OR status = '' OR status = '-' OR status = '—' OR status = '–')
                OR (status = 'processing' AND updated_at < NOW() - INTERVAL 10 MINUTE)
                OR (status = 'retrying' AND updated_at < NOW() - INTERVAL 10 MINUTE)
                OR (status = 'pending' AND updated_at < NOW() - INTERVAL 10 MINUTE)
                OR (status LIKE 'Hủy%')
                OR (status NOT IN ('completed', 'processing', 'pending', 'retrying') 
                    AND status NOT LIKE 'failed%' 
                    AND created_at < NOW() - INTERVAL 30 MINUTE)
            )
            AND (status IS NULL OR status NOT LIKE 'failed%')
            AND (status IS NULL OR status != 'completed')
            ORDER BY created_at DESC
            LIMIT 50
        ");
        $stuckJobs = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $results = [];

        foreach ($stuckJobs as $jobId) {
            $db->prepare("UPDATE conversion_jobs SET status = 'pending', worker_uuid = NULL, processed_chunks = 0, attempts = 0 WHERE id = ?")->execute([$jobId]);
            sleep(1);
            $result = dispatchJob($jobId);
            $results[] = $jobId . ': ' . (isset($result['error']) ? "⚠️ {$result['error']}" : "✅ → {$result['worker']}");
        }

        $count = count($stuckJobs);
        $msg = "🔄 Đã reset $count job(s). " . implode(' | ', array_slice($results, 0, 5));
        if ($count > 5) $msg .= " ... và " . ($count - 5) . " job khác";
    } catch (Exception $e) {
        $msg = "❌ Lỗi: " . $e->getMessage();
    }
    header("Location: reset_job.php?msg=" . urlencode($msg));
    exit;
}

// === ACTION: Check a specific job ===
$checkedJob = null;
if (isset($_GET['check']) && !empty($_GET['check'])) {
    $jobId = trim($_GET['check']);
    try {
        $db = getDB();
        $stmt = $db->prepare("SELECT j.*, u.email as user_email, w.worker_name, LENGTH(j.full_text) as text_length FROM conversion_jobs j LEFT JOIN users u ON j.user_id = u.id LEFT JOIN workers w ON j.worker_uuid = w.worker_uuid WHERE j.id = ?");
        $stmt->execute([$jobId]);
        $checkedJob = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $workerLogs = [];
        if ($checkedJob) {
            try {
                $stmtLog = $db->prepare("SELECT created_at, worker_name, level, message FROM worker_logs WHERE job_id = ? ORDER BY created_at DESC LIMIT 50");
                $stmtLog->execute([$jobId]);
                $workerLogs = $stmtLog->fetchAll(PDO::FETCH_ASSOC);
            } catch (Exception $e) { }
        } else {
            $msg = "❌ Job $jobId không tồn tại trong hệ thống.";
        }
    } catch (Exception $e) {
        $msg = "❌ Lỗi: " . $e->getMessage();
    }
}

// === DISPLAY: List stuck jobs ===
try {
    $db = getDB();

    // Stuck jobs: empty status, stale processing, 'Hủy' prefix, or any non-standard status >30min
    $stmt = $db->query("
        SELECT j.id, j.user_id, j.status, j.total_chunks, j.processed_chunks, 
               j.worker_uuid, j.model_id, j.voice_id, j.created_at, j.updated_at,
               j.attempts, u.email as user_email, w.worker_name,
               LENGTH(j.full_text) as text_length
        FROM conversion_jobs j
        LEFT JOIN users u ON j.user_id = u.id
        LEFT JOIN workers w ON j.worker_uuid = w.worker_uuid
        WHERE (
            (j.status IS NULL OR j.status = '' OR j.status = '-' OR j.status = '—' OR j.status = '–')
            OR (j.status = 'processing' AND j.updated_at < NOW() - INTERVAL 10 MINUTE)
            OR (j.status = 'retrying' AND j.updated_at < NOW() - INTERVAL 10 MINUTE)
            OR (j.status = 'pending' AND j.updated_at < NOW() - INTERVAL 10 MINUTE)
            OR (j.status LIKE 'Hủy%')
            OR (j.status NOT IN ('completed', 'processing', 'pending', 'retrying') 
                AND j.status NOT LIKE 'failed%' 
                AND j.created_at < NOW() - INTERVAL 30 MINUTE)
        )
        AND (j.status IS NULL OR j.status NOT LIKE 'failed%')
        AND (j.status IS NULL OR j.status != 'completed')
        ORDER BY j.created_at DESC
        LIMIT 50
    ");
    $stuckJobs = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    die("DB Error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stuck Jobs Manager</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            background: #0a0e17;
            color: #e0e6ed;
            padding: 24px;
            min-height: 100vh;
        }
        .header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 20px;
            flex-wrap: wrap;
            gap: 12px;
        }
        .header h1 {
            font-size: 22px;
            color: #fff;
        }
        .header h1 span { color: #ff6b6b; }
        .header .count {
            background: #ff6b6b;
            color: #fff;
            padding: 2px 10px;
            border-radius: 12px;
            font-size: 14px;
            font-weight: 600;
        }
        .actions {
            display: flex;
            gap: 10px;
        }
        .btn {
            padding: 8px 18px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-size: 13px;
            font-weight: 600;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-reset {
            background: #2563eb;
            color: #fff;
        }
        .btn-reset:hover { background: #1d4ed8; }
        .btn-reset-all {
            background: #dc2626;
            color: #fff;
        }
        .btn-reset-all:hover { background: #b91c1c; }
        .btn-refresh {
            background: #1e293b;
            color: #94a3b8;
            border: 1px solid #334155;
        }
        .btn-refresh:hover { background: #334155; color: #fff; }
        .btn-delete {
            background: #7f1d1d;
            color: #fca5a5;
            font-size: 12px;
            padding: 6px 12px;
        }
        .btn-delete:hover { background: #991b1b; color: #fff; }

        .msg-bar {
            background: #1a2332;
            border: 1px solid #334155;
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 16px;
            font-size: 14px;
            line-height: 1.5;
        }
        .msg-bar.success { border-color: #22c55e; background: #0a2015; }
        .msg-bar.error { border-color: #ef4444; background: #200a0a; }

        .empty {
            text-align: center;
            padding: 60px 20px;
            color: #64748b;
        }
        .empty .icon { font-size: 48px; margin-bottom: 12px; }
        .empty p { font-size: 16px; }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        th {
            background: #131a2b;
            color: #94a3b8;
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
            border-bottom: 1px solid #1e293b;
            position: sticky;
            top: 0;
        }
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #1e293b;
            vertical-align: middle;
        }
        tr:hover td { background: #111827; }
        
        .job-id {
            font-family: 'Consolas', monospace;
            font-weight: 700;
            color: #60a5fa;
        }
        .status-empty {
            color: #ef4444;
            font-style: italic;
        }
        .status-processing {
            color: #f59e0b;
        }
        .status-retrying {
            color: #a78bfa;
        }
        .time-ago {
            color: #64748b;
            font-size: 12px;
        }
        .time-ago.danger {
            color: #ef4444;
            font-weight: 600;
        }
        .model-tag {
            background: #1e293b;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            color: #94a3b8;
        }
        .user-email {
            color: #94a3b8;
            font-size: 12px;
            max-width: 180px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .worker-col {
            color: #64748b;
            font-size: 12px;
        }
        .manual-reset-box {
            background: #111827;
            border: 1px solid #2563eb;
            border-radius: 10px;
            padding: 16px 20px;
            margin-bottom: 16px;
        }
        .manual-reset-label {
            font-size: 14px;
            font-weight: 600;
            color: #93c5fd;
            margin-bottom: 10px;
        }
        .manual-reset-form {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .manual-reset-form input[type="text"] {
            flex: 1;
            max-width: 360px;
            padding: 10px 14px;
            border-radius: 8px;
            border: 1px solid #334155;
            background: #0a0e17;
            color: #e0e6ed;
            font-size: 14px;
            font-family: 'Consolas', monospace;
            letter-spacing: 0.5px;
            outline: none;
            transition: border-color 0.2s;
        }
        .manual-reset-form input[type="text"]:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37,99,235,0.25);
        }
        .manual-reset-form input[type="text"]::placeholder {
            color: #475569;
            font-family: 'Segoe UI', system-ui, sans-serif;
            letter-spacing: 0;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔧 <span>Stuck Jobs</span> Manager</h1>
        <div style="display:flex;align-items:center;gap:12px;">
            <?php if (count($stuckJobs) > 0): ?>
                <span class="count"><?= count($stuckJobs) ?> job bị treo</span>
            <?php endif; ?>
            <div class="actions">
                <a href="reset_job.php" class="btn btn-refresh">🔄 Refresh</a>
                <?php if (count($stuckJobs) > 0): ?>
                    <a href="reset_job.php?reset_all=1" class="btn btn-reset-all" onclick="return confirm('Reset tất cả <?= count($stuckJobs) ?> job bị treo?')">⚡ Reset All (<?= count($stuckJobs) ?>)</a>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Manual Check Box -->
    <div class="manual-reset-box" style="border-color: #10b981; margin-bottom: 16px;">
        <div class="manual-reset-label" style="color: #34d399;">👁️ Kiểm tra trạng thái Job</div>
        <form class="manual-reset-form" action="reset_job.php" method="get">
            <input type="text" name="check" placeholder="Nhập Job ID cần kiểm tra (vd: D1577EE1)" autocomplete="off" spellcheck="false" value="<?= isset($_GET['check']) ? htmlspecialchars($_GET['check']) : '' ?>" />
            <button type="submit" class="btn btn-reset" style="background: #059669;">🔍 Kiểm tra</button>
        </form>
    </div>

    <!-- Manual Reset Box -->
    <div class="manual-reset-box">
        <div class="manual-reset-label">🔍 Reset Job thủ công</div>
        <form class="manual-reset-form" action="reset_job.php" method="get">
            <input type="text" name="reset" id="manual-job-id" placeholder="Nhập Job ID (vd: ZEIKTQ0Q)" autocomplete="off" spellcheck="false" />
            <button type="submit" class="btn btn-reset" onclick="return document.getElementById('manual-job-id').value.trim() ? confirm('Reset job ' + document.getElementById('manual-job-id').value.trim() + '?') : (alert('Vui lòng nhập Job ID'), false)">🔁 Reset</button>
        </form>
    </div>

    <?php if (isset($_GET['msg'])): ?>
        <div class="msg-bar <?= strpos($_GET['msg'], '✅') !== false || strpos($_GET['msg'], '🔄') !== false ? 'success' : 'error' ?>">
            <?= htmlspecialchars($_GET['msg']) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($checkedJob) && $checkedJob): ?>
        <div style="background: #064e3b; border: 1px solid #10b981; border-radius: 8px; padding: 16px; margin-bottom: 24px;">
            <h3 style="color: #34d399; margin-bottom: 12px;">📋 Chi tiết Job: <?= htmlspecialchars($checkedJob['id']) ?></h3>
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px; font-size: 14px; color: #e2e8f0; line-height: 1.6;">
                <div><strong>Email:</strong> <?= htmlspecialchars($checkedJob['user_email'] ?? "User #{$checkedJob['user_id']}") ?></div>
                <div><strong>Trạng thái:</strong> <span style="color: #fbbf24; font-weight: bold;"><?= htmlspecialchars($checkedJob['status'] ?: '(trống)') ?></span></div>
                <div><strong>Ký tự:</strong> <?= number_format((int)$checkedJob['text_length']) ?></div>
                <div><strong>Tiến độ:</strong> <?= (int)$checkedJob['processed_chunks'] ?>/<?= (int)$checkedJob['total_chunks'] ?> chunks</div>
                <div><strong>Worker:</strong> <span style="color: #93c5fd;"><?= htmlspecialchars($checkedJob['worker_name'] ?: ($checkedJob['worker_uuid'] ?: '—')) ?></span></div>
                <div><strong>Attempts:</strong> <?= (int)$checkedJob['attempts'] ?> lần thử</div>
                <div><strong>Model:</strong> <?= htmlspecialchars($checkedJob['model_id']) ?></div>
                <div><strong>Tạo lúc:</strong> <?= $checkedJob['created_at'] ?></div>
                <div style="grid-column: 1 / -1;">
                    <strong>API Keys:</strong> <span style="font-family: monospace; color: #94a3b8;"><?= htmlspecialchars($checkedJob['api_key_ids'] ?: '—') ?></span>
                </div>
                <div style="grid-column: 1 / -1; max-height: 100px; overflow-y: auto; background: #022c22; padding: 8px; border-radius: 4px; font-size: 12px; color: #cbd5e1; white-space: pre-wrap;"><strong>Đoạn text cuối (Previous Chunk Text):</strong>
<?= htmlspecialchars($checkedJob['previous_chunk_text'] ?: '—') ?></div>
                <div style="grid-column: 1 / -1; max-height: 300px; overflow-y: auto; background: #022c22; padding: 8px; border-radius: 4px; font-size: 12px; color: #cbd5e1;">
                    <strong style="display:block;margin-bottom:8px;color:#34d399;">📜 Worker Logs (50 dòng gần nhất):</strong>
                    <?php if (empty($workerLogs)): ?>
                        <div style="color: #64748b;">Không có log nào.</div>
                    <?php else: ?>
                        <?php foreach($workerLogs as $log): ?>
                            <div style="margin-bottom: 4px; border-bottom: 1px solid rgba(255,255,255,0.05); padding-bottom: 4px;">
                                <span style="color: #94a3b8;">[<?= $log['created_at'] ?>]</span> 
                                <span style="color: #60a5fa;"><?= htmlspecialchars($log['worker_name']) ?></span>
                                <?php if ($log['level'] === 'error'): ?>
                                    <span style="color: #f87171; font-weight: bold;">(ERROR)</span>
                                <?php elseif ($log['level'] === 'warning'): ?>
                                    <span style="color: #fbbf24; font-weight: bold;">(WARN)</span>
                                <?php endif; ?>: 
                                <span style="<?= $log['level'] === 'error' ? 'color:#f87171;' : ($log['level'] === 'warning' ? 'color:#fbbf24;' : '') ?>"><?= htmlspecialchars($log['message']) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            <div style="margin-top: 16px; display: flex; gap: 10px;">
                <a href="reset_job.php?reset=<?= urlencode($checkedJob['id']) ?>" class="btn btn-reset" onclick="return confirm('Reset job <?= $checkedJob['id'] ?>?')">🔁 Reset Job Này</a>
                <a href="reset_job.php?delete=<?= urlencode($checkedJob['id']) ?>" class="btn btn-delete" onclick="return confirm('Xóa job <?= $checkedJob['id'] ?>? Không thể hoàn tác!')">🗑️ Xóa</a>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($stuckJobs) && !$checkedJob): ?>
        <div class="empty">
            <div class="icon">✅</div>
            <p>Không có job nào bị treo</p>
        </div>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Job ID</th>
                    <th>User</th>
                    <th>Status</th>
                    <th>Chunks</th>
                    <th>Ký tự</th>
                    <th>Model</th>
                    <th>Worker</th>
                    <th>Tạo lúc</th>
                    <th>Cập nhật</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($stuckJobs as $job): ?>
                <?php
                    $status = $job['status'] ?: '(trống)';
                    $statusClass = 'status-empty';
                    if ($job['status'] === 'processing') $statusClass = 'status-processing';
                    if ($job['status'] === 'retrying') $statusClass = 'status-retrying';
                    
                    // Time ago calculation
                    $createdAt = new DateTime($job['created_at']);
                    $updatedAt = new DateTime($job['updated_at']);
                    $now = new DateTime();
                    
                    $createdDiff = $now->diff($createdAt);
                    $updatedDiff = $now->diff($updatedAt);
                    
                    if (!function_exists('formatDiff')) {
                        function formatDiff($diff) {
                            if ($diff->days > 0) return $diff->days . ' ngày trước';
                            if ($diff->h > 0) return $diff->h . ' giờ ' . $diff->i . ' phút trước';
                            if ($diff->i > 0) return $diff->i . ' phút trước';
                            return $diff->s . ' giây trước';
                        }
                    }
                    
                    $createdAgo = formatDiff($createdDiff);
                    $updatedAgo = formatDiff($updatedDiff);
                    $isDanger = ($updatedDiff->h > 0 || $updatedDiff->days > 0);
                ?>
                <tr>
                    <td><span class="job-id"><?= htmlspecialchars($job['id']) ?></span></td>
                    <td><span class="user-email" title="<?= htmlspecialchars($job['user_email'] ?? '') ?>"><?= htmlspecialchars($job['user_email'] ?? "User #{$job['user_id']}") ?></span></td>
                    <td><span class="<?= $statusClass ?>"><?= htmlspecialchars($status) ?></span></td>
                    <td><?= (int)$job['processed_chunks'] ?>/<?= (int)$job['total_chunks'] ?></td>
                    <td><?= number_format((int)$job['text_length']) ?></td>
                    <td><span class="model-tag"><?= htmlspecialchars($job['model_id'] ? str_replace(['eleven_', 'multilingual_'], ['', ''], $job['model_id']) : 'N/A') ?></span></td>
                    <td><span class="worker-col"><?= htmlspecialchars($job['worker_name'] ?: ($job['worker_uuid'] ?: '—')) ?></span></td>
                    <td>
                        <div><?= $createdAt->format('d/m H:i:s') ?></div>
                        <div class="time-ago"><?= $createdAgo ?></div>
                    </td>
                    <td>
                        <div><?= $updatedAt->format('d/m H:i:s') ?></div>
                        <div class="time-ago <?= $isDanger ? 'danger' : '' ?>"><?= $updatedAgo ?></div>
                    </td>
                    <td style="display:flex;gap:6px;">
                        <a href="reset_job.php?reset=<?= urlencode($job['id']) ?>" class="btn btn-reset" onclick="return confirm('Reset job <?= $job['id'] ?>?')">🔁 Reset</a>
                        <a href="reset_job.php?delete=<?= urlencode($job['id']) ?>" class="btn btn-delete" onclick="return confirm('Xóa job <?= $job['id'] ?>? Không thể hoàn tác!')">🗑️ Xóa</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</body>
</html>