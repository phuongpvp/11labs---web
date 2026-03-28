<?php
/**
 * Background Job Dispatcher
 * Periodically searches for 'pending' jobs and attempts to dispatch them to active workers.
 */

if (!defined('CRON_EXECUTION') && php_sapi_name() !== 'cli' && (!isset($_GET['secret']) || !verifyWorkerSecret($_GET['secret']))) {
    // Basic protection if called via web
    http_response_code(403);
    exit("Access Denied");
}

require_once __DIR__ . '/config.php';

echo "--- JOB DISPATCHER --- " . date('Y-m-d H:i:s') . "\n";

try {
    $db = getDB();

    // --- 1. STUCK JOBS RECOVERY ---
    // Find jobs that have been in 'processing' for more than 8 minutes
    // and reset them to 'pending' so they can be re-dispatched.
    $force = isset($_GET['force']) && $_GET['force'] == '1';
    $stuckThreshold = $force ? 0 : 8; // Reset everything if forced

    $stmt = $db->prepare("UPDATE conversion_jobs SET status = 'pending', worker_uuid = NULL, updated_at = NOW() WHERE status = 'processing' AND updated_at < (NOW() - INTERVAL ? MINUTE)");
    $stmt->execute([$stuckThreshold]);
    $stuckCount = $stmt->rowCount();
    if ($stuckCount > 0) {
        $msg = $force ? "FORCE RESET: " : "";
        echo date('H:i:s') . " 🔄 <b>Recovery:</b> {$msg}Successfully reset $stuckCount stuck jobs to 'pending' state.\n";
    }

    // --- 1.5. FAILED JOBS RECOVERY (Admin Triggered) ---
    $retryFailed = isset($_GET['retry_failed']) && $_GET['retry_failed'] == '1';
    if ($retryFailed) {
        // Only retry jobs from the last 24 hours to prevent endless loops of truly broken jobs
        $res = $db->exec("UPDATE conversion_jobs SET status = 'pending', worker_uuid = NULL, created_at = NOW(), updated_at = NOW() WHERE status LIKE 'failed%' AND created_at > (NOW() - INTERVAL 24 HOUR)");
        echo date('H:i:s') . " 🔄 <b>Bulk Retry:</b> Successfully reset $res failed jobs (last 24h) to 'pending' state.\n";
    }

    // --- 2. DISPATCH PENDING JOBS ---
    // Find jobs that are 'pending' from the last 6 hours
    $stmt = $db->query("SELECT id FROM conversion_jobs WHERE status IN ('pending', 'retrying') AND updated_at > (NOW() - INTERVAL 6 HOUR) ORDER BY updated_at ASC LIMIT 10");
    $pendingJobs = $stmt->fetchAll();

    if (count($pendingJobs) === 0) {
        echo "No pending jobs found.\n";
        exit;
    }

    echo "Found " . count($pendingJobs) . " pending jobs. Attempting dispatch...\n";

    foreach ($pendingJobs as $job) {
        $res = dispatchJob($job['id']);
        if (isset($res['status']) && $res['status'] === 'success') {
            echo "✅ Job {$job['id']} dispatched to worker: {$res['worker']}\n";
        } else {
            echo "❌ Job {$job['id']} failed: " . ($res['error'] ?? 'Unknown error') . "\n";
        }
    }

} catch (Exception $e) {
    echo "Fatal Error: " . $e->getMessage() . "\n";
}
