<?php
require_once __DIR__ . '/../config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

// Handle POST actions (delete_worker, cleanup_workers)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $adminPassword = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
    if (!$adminPassword || !verifyAdminPassword($adminPassword)) {
        jsonResponse(['error' => 'Unauthorized'], 403);
    }

    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
    $db = getDB();

    if ($action === 'delete_worker') {
        $uuid = $input['worker_uuid'] ?? '';
        if (!$uuid) jsonResponse(['error' => 'UUID required'], 400);

        // Get worker name before delete for ngrok cleanup
        $stmt = $db->prepare("SELECT worker_name FROM workers WHERE worker_uuid = ?");
        $stmt->execute([$uuid]);
        $worker = $stmt->fetch(PDO::FETCH_ASSOC);

        $db->prepare("DELETE FROM workers WHERE worker_uuid = ?")->execute([$uuid]);

        // Release ngrok token
        if ($worker && $worker['worker_name']) {
            try {
                $db->prepare("UPDATE ngrok_keys SET worker_uuid = NULL, worker_ip = NULL, assigned_at = NULL WHERE worker_uuid = ?")->execute([$uuid]);
            } catch (Exception $e) { /* ignore */ }
        }

        jsonResponse(['success' => true, 'message' => 'Đã xóa worker']);
    }

    if ($action === 'cleanup_workers') {
        $stmt = $db->prepare("DELETE FROM workers WHERE last_seen < (NOW() - INTERVAL 300 SECOND)");
        $stmt->execute();
        $deleted = $stmt->rowCount();
        jsonResponse(['success' => true, 'message' => "Đã dọn $deleted worker offline"]);
    }

    jsonResponse(['error' => 'Invalid action'], 400);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Get admin password from header
$adminPassword = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
if (!$adminPassword || !verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Unauthorized'], 403);
}

// Helper functions (Defined outside try for stability)
function getLogContent($filename, $maxLines = 100)
{
    $path = __DIR__ . '/../logs/' . $filename;
    if (file_exists($path)) {
        $lines = file($path);
        $lines = array_slice($lines, -$maxLines);
        return implode("", $lines);
    }
    return null;
}

function getLatestErrorLog()
{
    $logDir = __DIR__ . '/../logs/';
    if (!is_dir($logDir))
        return "No logs found.";
    $files = glob($logDir . 'error_*.log');
    if (!$files) {
        return file_exists($logDir . 'error.log') ? getLogContent('error.log') : "No error logs found.";
    }
    rsort($files);
    $latestFile = basename($files[0]);
    return "File: $latestFile\n" . getLogContent($latestFile);
}

try {
    require_once __DIR__ . '/../telegram.php';
    $db = getDB();

    // V17+: Ensure worker_logs table exists and uses UTF-8
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS worker_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            worker_uuid VARCHAR(64),
            worker_name VARCHAR(255),
            job_id VARCHAR(64) NULL,
            message TEXT,
            level VARCHAR(20) DEFAULT 'info',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->exec("ALTER TABLE worker_logs CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Exception $e) { /* Table migration failed but continue */
    }

    // Lazy schema: ensure key_type column exists in users table
    try {
        $db->exec("ALTER TABLE users ADD COLUMN key_type VARCHAR(20) DEFAULT 'partner' AFTER partner_api_key");
    } catch (Exception $e) { /* Ignore if exists */
    }
    // Lazy schema: ensure max_parallel column exists
    try {
        $db->exec("ALTER TABLE users ADD COLUMN max_parallel INT DEFAULT NULL");
    } catch (Exception $e) { /* Ignore if exists */
    }

    // Summary Statistics
    $userStats = $db->query("SELECT COUNT(*) as total_users, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_users, SUM(quota_used) as total_chars_used FROM users")->fetch();
    $keyStats = $db->query("SELECT COUNT(*) as total_keys, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_keys, SUM(credits_remaining) as total_credits, SUM(CASE WHEN status = 'active' THEN CAST(REPLACE(credits_remaining, '.', '') AS SIGNED) ELSE 0 END) as active_credits FROM api_keys")->fetch();
    $todayStats = $db->query("SELECT COUNT(*) as conversions_today, SUM(characters_used) as chars_today FROM usage_logs WHERE DATE(created_at) = CURDATE()")->fetch();
    $yesterdayStats = $db->query("SELECT COUNT(*) as conversions_yesterday, SUM(characters_used) as chars_yesterday FROM usage_logs WHERE DATE(created_at) = CURDATE() - INTERVAL 1 DAY")->fetch();

    $revenueStats = $db->query("SELECT SUM(amount) as all_time_revenue, SUM(CASE WHEN DATE(created_at) = CURDATE() THEN amount ELSE 0 END) as revenue_today FROM payments WHERE status = 'completed'")->fetch();
    $totalHarvested = $db->query("SELECT COALESCE(SUM(amount), 0) FROM admin_harvests")->fetchColumn();
    $revenueStats['total_withdrawn'] = $totalHarvested;
    $revenueStats['harvestable_balance'] = max(0, $revenueStats['all_time_revenue'] - $totalHarvested);
    $revenueStats['total_revenue'] = $revenueStats['harvestable_balance'];

    // Lists
    $topUsers = $db->query("SELECT u.id, u.email, u.plan, u.custom_plan_name, (u.quota_used + u.team_quota_used) as quota_used, (u.quota_total + u.team_quota_limit) as quota_total, u.expires_at, u.status, u.partner_api_key, COALESCE(u.key_type, 'partner') as key_type, u.max_parallel, u.created_at, p.email as parent_email, (COALESCE((SELECT SUM(characters_used) FROM usage_logs WHERE user_id = u.id AND DATE(created_at) = CURDATE()), 0) + COALESCE((SELECT SUM(points_used) FROM music_jobs WHERE user_id = u.id AND DATE(created_at) = CURDATE()), 0) + COALESCE((SELECT SUM(points_used) FROM isolation_jobs WHERE user_id = u.id AND DATE(created_at) = CURDATE()), 0) + COALESCE((SELECT SUM(points_used) FROM sfx_jobs WHERE user_id = u.id AND DATE(created_at) = CURDATE()), 0) + COALESCE((SELECT SUM(points_used) FROM stt_jobs WHERE user_id = u.id AND DATE(created_at) = CURDATE()), 0) + COALESCE((SELECT SUM(points_used) FROM dubbing_jobs WHERE user_id = u.id AND DATE(created_at) = CURDATE()), 0) + COALESCE((SELECT SUM(points_used) FROM voice_changer_jobs WHERE user_id = u.id AND DATE(created_at) = CURDATE()), 0)) as today_used FROM users u LEFT JOIN users p ON u.parent_id = p.id ORDER BY FIELD(u.plan, 'supper_vip', 'vip', 'pro', 'basic', 'trial') ASC, (u.quota_used + u.team_quota_used) DESC")->fetchAll();
    $recentPayments = $db->query("SELECT p.id, u.email, p.plan_id, p.amount, p.status, p.created_at FROM payments p JOIN users u ON p.user_id = u.id ORDER BY p.created_at DESC LIMIT 20")->fetchAll();
    $keys = $db->query("SELECT id, LEFT(key_encrypted, 20) as key_preview, credits_remaining, status, last_checked, assigned_worker_uuid, reset_at, created_at FROM api_keys ORDER BY credits_remaining DESC")->fetchAll();

    // Recent Logs Merge (TTS + Isolation + Music + SFX)
    $recentLogs1 = $db->query("SELECT cj.id, u.email, CHAR_LENGTH(cj.full_text) as characters_used, LEFT(cj.full_text, 80) as text_preview, cj.created_at, cj.status as job_status, cj.worker_uuid, cj.voice_id FROM conversion_jobs cj JOIN users u ON cj.user_id = u.id ORDER BY cj.created_at DESC LIMIT 30")->fetchAll();
    $recentLogs2 = $db->query("SELECT ij.id, u.email, ij.points_used as characters_used, ij.source_file as text_preview, ij.created_at, ij.status as job_status, ij.worker_uuid FROM isolation_jobs ij JOIN users u ON ij.user_id = u.id ORDER BY ij.created_at DESC LIMIT 30")->fetchAll();
    foreach ($recentLogs2 as &$l) {
        $l['text_preview'] = '🎵 Tách giọng: ' . $l['text_preview'];
    }
    $recentLogs3 = $db->query("SELECT mj.id, u.email, mj.points_used as characters_used, LEFT(mj.prompt, 80) as text_preview, mj.created_at, mj.status as job_status, mj.worker_uuid FROM music_jobs mj JOIN users u ON mj.user_id = u.id ORDER BY mj.created_at DESC LIMIT 30")->fetchAll();
    foreach ($recentLogs3 as &$l) {
        $l['text_preview'] = '🎶 Tạo nhạc: ' . $l['text_preview'];
    }
    $recentLogs4 = [];
    try {
        $recentLogs4 = $db->query("SELECT sj.id, u.email, sj.points_used as characters_used, LEFT(sj.prompt, 80) as text_preview, sj.created_at, sj.status as job_status, sj.worker_uuid FROM sfx_jobs sj JOIN users u ON sj.user_id = u.id ORDER BY sj.created_at DESC LIMIT 30")->fetchAll();
        foreach ($recentLogs4 as &$l) {
            $l['text_preview'] = '🔊 Hiệu ứng: ' . $l['text_preview'];
        }
    } catch (Exception $e) { /* sfx_jobs table may not exist yet */
    }
    $recentLogs5 = [];
    try {
        $recentLogs5 = $db->query("SELECT sj.id, u.email, sj.points_used as characters_used, LEFT(sj.original_filename, 80) as text_preview, sj.created_at, sj.status as job_status, sj.worker_uuid FROM stt_jobs sj JOIN users u ON sj.user_id = u.id ORDER BY sj.created_at DESC LIMIT 30")->fetchAll();
        foreach ($recentLogs5 as &$l) {
            $l['text_preview'] = '🎤 Giọng→Chữ: ' . $l['text_preview'];
        }
    } catch (Exception $e) { /* stt_jobs table may not exist yet */
    }
    $recentLogs6 = [];
    try {
        $recentLogs6 = $db->query("SELECT dj.id, u.email, dj.points_used as characters_used, CONCAT('🌍 Lồng tiếng: ', dj.original_filename, ' (', dj.source_lang, ' → ', dj.target_lang, ')') as text_preview, dj.created_at, dj.status as job_status, dj.worker_uuid FROM dubbing_jobs dj JOIN users u ON dj.user_id = u.id ORDER BY dj.created_at DESC LIMIT 30")->fetchAll();
    } catch (Exception $e) { /* dubbing_jobs table may not exist yet */
    }
    $recentLogs7 = [];
    try {
        $recentLogs7 = $db->query("SELECT vj.id, u.email, vj.points_used as characters_used, CONCAT('🎤 Đổi giọng: ', vj.source_file) as text_preview, vj.created_at, vj.status as job_status, vj.worker_uuid, vj.voice_id FROM voice_changer_jobs vj JOIN users u ON vj.user_id = u.id ORDER BY vj.created_at DESC LIMIT 30")->fetchAll();
    } catch (Exception $e) { /* voice_changer_jobs table may not exist yet */
    }
    $recentLogs = array_merge($recentLogs1, $recentLogs2, $recentLogs3, $recentLogs4, $recentLogs5, $recentLogs6, $recentLogs7);
    usort($recentLogs, function ($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });
    $recentLogs = array_slice($recentLogs, 0, 30);

    // Completed Logs (TTS + Isolation + Music + SFX)
    $dbCompleted1 = $db->query("SELECT cj.id, u.email, cj.updated_at, CHAR_LENGTH(cj.full_text) as chars, 'TTS' as type, cj.api_key_ids FROM conversion_jobs cj JOIN users u ON cj.user_id = u.id WHERE cj.status LIKE 'completed%' ORDER BY cj.updated_at DESC LIMIT 30")->fetchAll();
    $dbCompleted2 = $db->query("SELECT ij.id, u.email, ij.created_at as updated_at, ij.points_used as chars, 'ISOLATOR' as type FROM isolation_jobs ij JOIN users u ON ij.user_id = u.id WHERE ij.status LIKE 'completed%' ORDER BY ij.created_at DESC LIMIT 30")->fetchAll();
    // Ensure music_jobs.api_key_id column exists
    try {
        $db->exec("ALTER TABLE music_jobs ADD COLUMN api_key_id INT DEFAULT NULL AFTER points_used");
    } catch (Exception $e) { /* already exists */
    }
    $dbCompleted3 = $db->query("SELECT mj.id, u.email, mj.updated_at, mj.points_used as chars, 'MUSIC' as type, mj.api_key_id as api_key_ids FROM music_jobs mj JOIN users u ON mj.user_id = u.id WHERE mj.status = 'completed' ORDER BY mj.updated_at DESC LIMIT 30")->fetchAll();
    $dbCompleted4 = [];
    try {
        $dbCompleted4 = $db->query("SELECT sj.id, u.email, sj.updated_at, sj.points_used as chars, 'SFX' as type, sj.api_key_id as api_key_ids FROM sfx_jobs sj JOIN users u ON sj.user_id = u.id WHERE sj.status = 'completed' ORDER BY sj.updated_at DESC LIMIT 30")->fetchAll();
    } catch (Exception $e) { /* sfx_jobs table may not exist yet */
    }
    $dbCompleted5 = [];
    try {
        $dbCompleted5 = $db->query("SELECT sj.id, u.email, sj.updated_at, sj.points_used as chars, 'STT' as type, sj.api_key_id as api_key_ids FROM stt_jobs sj JOIN users u ON sj.user_id = u.id WHERE sj.status = 'completed' ORDER BY sj.updated_at DESC LIMIT 30")->fetchAll();
    } catch (Exception $e) { /* stt_jobs table may not exist yet */
    }
    $dbCompleted6 = [];
    try {
        $dbCompleted6 = $db->query("SELECT dj.id, u.email, dj.updated_at, dj.points_used as chars, 'DUBBING' as type, NULL as api_key_ids FROM dubbing_jobs dj JOIN users u ON dj.user_id = u.id WHERE dj.status = 'dubbed' ORDER BY dj.updated_at DESC LIMIT 30")->fetchAll();
    } catch (Exception $e) { /* dubbing_jobs table may not exist yet */
    }
    $dbCompleted7 = [];
    try {
        $dbCompleted7 = $db->query("SELECT vj.id, u.email, vj.completed_at as updated_at, vj.points_used as chars, 'VOICE_CHANGER' as type, vj.api_key_ids FROM voice_changer_jobs vj JOIN users u ON vj.user_id = u.id WHERE vj.status = 'completed' ORDER BY vj.completed_at DESC LIMIT 30")->fetchAll();
    } catch (Exception $e) { /* voice_changer_jobs table may not exist yet */
    }
    $dbCompleted = array_merge($dbCompleted1, $dbCompleted2, $dbCompleted3, $dbCompleted4, $dbCompleted5, $dbCompleted6, $dbCompleted7);
    usort($dbCompleted, function ($a, $b) {
        return strtotime($b['updated_at']) <=> strtotime($a['updated_at']);
    });

    $completedLogStr = "";
    foreach (array_slice($dbCompleted, 0, 50) as $row) {
        $typeLabel = $row['type'] === 'ISOLATOR' ? ' [Tách giọng]' : ($row['type'] === 'MUSIC' ? ' [Tạo nhạc]' : ($row['type'] === 'SFX' ? ' [Hiệu ứng]' : ($row['type'] === 'STT' ? ' [Giọng→Chữ]' : ($row['type'] === 'DUBBING' ? ' [Lồng tiếng]' : ($row['type'] === 'VOICE_CHANGER' ? ' [Đổi giọng]' : '')))));
        $keyInfo = (!empty($row['api_key_ids'])) ? " Key(s): " . $row['api_key_ids'] : '';
        $completedLogStr .= "[" . $row['updated_at'] . "] Job " . $row['id'] . " COMPLETED. User: " . $row['email'] . " (" . $row['chars'] . " ký tự){$typeLabel}{$keyInfo}\n";
    }

    // Worker Events
    $workerLogs = $db->query("SELECT * FROM worker_logs ORDER BY created_at DESC LIMIT 50")->fetchAll();
    $workers = $db->query("SELECT * FROM workers ORDER BY last_seen DESC LIMIT 50")->fetchAll();

    // Settings
    $settings = [];
    $stmtS = $db->query("SELECT config_key, config_value FROM system_config");
    while ($row = $stmtS->fetch()) {
        $settings[$row['config_key']] = $row['config_value'];
    }

    // IP Block Logs
    $ipBlockLogs = [];
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS ip_block_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            worker_name VARCHAR(100),
            worker_uuid VARCHAR(64),
            worker_ip VARCHAR(50),
            reason VARCHAR(100),
            key_details JSON,
            jobs_completed INT DEFAULT 0,
            chars_used INT DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $ipBlockLogs = $db->query("SELECT * FROM ip_block_logs ORDER BY created_at DESC LIMIT 50")->fetchAll();
    } catch (Exception $e) { /* table may not exist yet */
    }

    jsonResponse([
        'status' => 'success',
        'summary' => ['users' => $userStats, 'keys' => $keyStats, 'today' => $todayStats, 'yesterday' => $yesterdayStats, 'revenue' => $revenueStats],
        'top_users' => $topUsers,
        'api_keys' => $keys,
        'recent_logs' => $recentLogs,
        'recent_payments' => $recentPayments,
        'workers' => $workers,
        'packages' => getSubscriptionPackages(),
        'settings' => $settings,
        'ip_block_logs' => $ipBlockLogs,
        'logs' => [
            'failed' => getLogContent('failed_jobs.log') ?? "Chưa có Job thất bại.",
            'completed' => $completedLogStr ?: "Chưa có Job nào.",
            'key_errors' => getLatestErrorLog(),
            'worker_events' => $workerLogs
        ]
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}