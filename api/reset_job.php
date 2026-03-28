<?php
require_once __DIR__ . '/config.php';
$jobId = 'zzBTsLBFM6AOJtkr1e9b';

try {
    $db = getDB();

    // Check current status
    $stmt = $db->prepare("SELECT status FROM conversion_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $currentStatus = $stmt->fetchColumn();

    // Force back to pending
    $stmt = $db->prepare("UPDATE conversion_jobs SET status = 'pending', worker_uuid = NULL, processed_chunks = 0 WHERE id = ?");
    $stmt->execute([$jobId]);

    echo "Job $jobId reset from '$currentStatus' to 'pending'. It will be dispatched again shortly.";

    // Manually trigger dispatcher to pick it up immediately
    require_once __DIR__ . '/dispatcher.php';
    dispatchJob($jobId);

} catch (Exception $e) {
    echo "Error resetting job: " . $e->getMessage();
}
?>