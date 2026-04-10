<?php
require_once __DIR__ . '/config.php';
$jobId = 'PXWEFD1Q';

try {
    $db = getDB();

    // Check current status
    $stmt = $db->prepare("SELECT status, attempts, processed_chunks, total_chunks FROM conversion_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch();

    if (!$job) {
        echo "Job $jobId not found!";
        exit;
    }

    echo "Current: status='{$job['status']}', attempts={$job['attempts']}, chunks={$job['processed_chunks']}/{$job['total_chunks']}\n";

    // Force back to pending + reset attempts so it won't be immediately cancelled
    $stmt = $db->prepare("UPDATE conversion_jobs SET status = 'pending', worker_uuid = NULL, processed_chunks = 0, attempts = 0 WHERE id = ?");
    $stmt->execute([$jobId]);

    echo "Job $jobId reset to 'pending' with attempts=0. Dispatching now...\n";

    // dispatchJob() is defined in config.php (already loaded)
    $result = dispatchJob($jobId);
    
    if (isset($result['error'])) {
        echo "Dispatch error: {$result['error']}\n";
    } else {
        echo "Dispatched to worker: " . ($result['worker'] ?? 'unknown') . "\n";
    }

} catch (Exception $e) {
    echo "Error resetting job: " . $e->getMessage();
}
?>