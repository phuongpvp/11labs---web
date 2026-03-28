<?php
require_once __DIR__ . '/telegram.php';
require_once __DIR__ . '/config.php';

// Production Mode: Silent execution by default
// Use ?debug=1 to see output
$debug = isset($_GET['debug']) && $_GET['debug'] == '1';

// Basic protection if called via web
// Accept either: secret query param (for workers/hosting cron) OR admin password header (for admin panel)
if (php_sapi_name() !== 'cli') {
    $hasSecret = isset($_GET['secret']) && verifyWorkerSecret($_GET['secret']);
    $adminPass = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
    $hasAdminAuth = $adminPass && verifyAdminPassword($adminPass);
    
    if (!$hasSecret && !$hasAdminAuth) {
        http_response_code(403);
        exit("Access Denied");
    }
}

// Define constant to allow dispatcher.php to run without secret when included here
define('CRON_EXECUTION', true);

if ($debug) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "--- CRON JOB MONITOR --- " . date('Y-m-d H:i:s') . "\n";
}

try {
    // --- 0. Auto Cleanup Results (different retention per type) ---
    // Voice files (results/): 6 hours
    // Music files (results/music/): 3 hours
    // Isolator files (results/isolator/, results/isolator/uploads/): 3 hours
    $cleanupRules = [
        ['dir' => __DIR__ . '/results', 'max_age' => 12 * 3600, 'label' => 'Voice (12h)'],
        ['dir' => __DIR__ . '/results/music', 'max_age' => 3 * 3600, 'label' => 'Music (3h)'],
        ['dir' => __DIR__ . '/results/isolator', 'max_age' => 3 * 3600, 'label' => 'Isolator (3h)'],
        ['dir' => __DIR__ . '/results/isolator/uploads', 'max_age' => 3 * 3600, 'label' => 'Isolator uploads (3h)'],
        ['dir' => __DIR__ . '/results/dubbing', 'max_age' => 6 * 3600, 'label' => 'Dubbing (6h)'],
        ['dir' => __DIR__ . '/results/dubbing/uploads', 'max_age' => 6 * 3600, 'label' => 'Dubbing uploads (6h)'],
    ];
    $cleanupCount = 0;

    foreach ($cleanupRules as $rule) {
        $dir = $rule['dir'];
        $cutoff = time() - $rule['max_age'];
        if (is_dir($dir)) {
            if ($debug)
                echo "\n--- CLEANING: {$rule['label']} ($dir) ---\n";
            $files = glob($dir . '/*.*');
            if ($files) {
                foreach ($files as $file) {
                    if (is_dir($file))
                        continue; // skip subdirectories
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    if (!in_array($ext, ['mp3', 'wav', 'srt', 'mp4', 'mkv', 'm4a']))
                        continue;
                    if (filemtime($file) < $cutoff) {
                        if (@unlink($file)) {
                            $cleanupCount++;
                        }
                    }
                }
            }
        }
    }

    if ($debug)
        echo "Đã xóa $cleanupCount file rác (Voice >6h, Music/Isolator >3h).\n";

    $db = getDB();

    // --- 0.5. DB Cleanup (Retention Policy: 48 hours) ---
    $db->exec("DELETE FROM conversion_jobs WHERE created_at < NOW() - INTERVAL 48 HOUR");
    $db->exec("DELETE FROM usage_logs WHERE created_at < NOW() - INTERVAL 48 HOUR");
    $db->exec("DELETE FROM workers WHERE last_seen < NOW() - INTERVAL 48 HOUR");

    if ($debug)
        echo "Đã dọn dẹp DB (jobs/logs cũ hơn 48h).\n";

    // --- 1. Worker Monitor (Self-healing) ---
    if ($debug)
        echo "\n--- WORKER MONITOR ---\n";
    $offlineCount = checkOfflineWorkers();
    if ($debug) {
        if ($offlineCount > 0) {
            echo "Phát hiện $offlineCount máy đã sập và đã giải cứu Job tương ứng.\n";
        } else {
            echo "Tất cả máy vẫn đang Online tốt.\n";
        }
    }

    // --- 1.5. Ngrok Token Auto-Cleanup (5 minutes rule) ---
    if ($debug)
        echo "\n--- NGROK TOKEN CLEANUP ---\n";
    $released1 = $db->exec("
        UPDATE ngrok_keys
        SET worker_uuid = NULL, worker_ip = NULL, assigned_at = NULL
        WHERE worker_uuid IN (
            SELECT worker_uuid FROM workers WHERE last_seen < (NOW() - INTERVAL 5 MINUTE)
        )
    ");
    $released2 = $db->exec("
        UPDATE ngrok_keys
        SET worker_uuid = NULL, worker_ip = NULL, assigned_at = NULL
        WHERE worker_uuid IS NOT NULL
          AND assigned_at < (NOW() - INTERVAL 5 MINUTE)
          AND worker_uuid NOT IN (SELECT worker_uuid FROM workers)
    ");

    if ($debug) {
        $totalReleased = ($released1 ?: 0) + ($released2 ?: 0);
        if ($totalReleased > 0)
            echo "Đã thu hồi $totalReleased Token Ngrok từ các máy offline > 5 phút.\n";
    }

    // --- 1.6. API Key (ElevenLabs Token) Auto-Cleanup (5 minutes rule) ---
    if ($debug)
        echo "\n--- API KEY CLEANUP ---\n";
    $releasedKeys1 = $db->exec("
        UPDATE api_keys
        SET assigned_worker_uuid = NULL
        WHERE assigned_worker_uuid IN (
            SELECT worker_uuid FROM workers WHERE last_seen < (NOW() - INTERVAL 5 MINUTE)
        )
    ");
    $releasedKeys2 = $db->exec("
        UPDATE api_keys
        SET assigned_worker_uuid = NULL
        WHERE assigned_worker_uuid IS NOT NULL
          AND assigned_worker_uuid NOT IN (SELECT worker_uuid FROM workers)
    ");

    if ($debug) {
        $totalKeys = ($releasedKeys1 ?: 0) + ($releasedKeys2 ?: 0);
        if ($totalKeys > 0)
            echo "Đã giải phóng $totalKeys Token ElevenLabs từ các máy offline > 5 phút.\n";
    }

    // --- 2. Admin Actions ---
    checkLowCreditAlert();
    checkAccountExpiry();

    // --- 3. Job Dispatcher ---
    if ($debug)
        echo "\n--- JOB DISPATCHER ---\n";
    include __DIR__ . '/dispatcher.php';

    // --- 4. Auto Backup Database (Daily at 03:00 AM) ---
    $currentHour = date('H');
    $currentMin = date('i');
    if ($currentHour == '03' && intval($currentMin) < 5) {
        $today = date('Y-m-d');
        $lockFile = __DIR__ . "/logs/backup_{$today}.lock";
        if (!file_exists($lockFile)) {
            if ($debug)
                echo "\n--- AUTO BACKUP ---\n";
            include __DIR__ . '/backup_db.php';
            file_put_contents($lockFile, date('Y-m-d H:i:s'));
            if ($debug)
                echo "Backup process finished.\n";
        }
    }

    if ($debug)
        echo "\nCron complete at " . date('Y-m-d H:i:s') . "\n";

} catch (Exception $e) {
    if ($debug)
        echo "Error: " . $e->getMessage();
    logToFile('cron_error.log', $e->getMessage());
}
?>