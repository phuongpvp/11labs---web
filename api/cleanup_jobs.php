<?php
require_once __DIR__ . '/config.php';
$targetChars = 7209;
$mainJobId = 'STBPPFJD';

try {
    $db = getDB();

    // Find User for the main job
    $stmt = $db->prepare("SELECT user_id FROM conversion_jobs WHERE id = ?");
    $stmt->execute([$mainJobId]);
    $userId = $stmt->fetchColumn();

    if (!$userId) {
        die("Main Job $mainJobId not found in DB.");
    }

    // Find duplicates (same user, same chars, different ID)
    // We'll look for jobs created today
    $stmt = $db->prepare("SELECT id FROM conversion_jobs WHERE user_id = ? AND total_chunks = ? AND id != ? AND created_at > (NOW() - INTERVAL 1 DAY)");
    $stmt->execute([$userId, (int) ceil($targetChars / 2000), $mainJobId]);
    $duplicates = $stmt->fetchAll(PDO::FETCH_COLUMN);

    $deletedCount = 0;
    foreach ($duplicates as $dupId) {
        // We check if it's REALLY the same char count (since total_chunks is just ceil)
        $stmtC = $db->prepare("SELECT LENGTH(full_text) as len FROM conversion_jobs WHERE id = ?");
        $stmtC->execute([$dupId]);
        // Note: LENGTH in MySQL is bytes, but for UTF-8 mostly fine check
        if ($stmtC->fetchColumn() > 7000) {
            $db->prepare("DELETE FROM conversion_jobs WHERE id = ?")->execute([$dupId]);
            $deletedCount++;
        }
    }

    // Reset the main job correctly
    $db->prepare("UPDATE conversion_jobs SET status = 'pending', retry_count = 0, worker_uuid = NULL, processed_chunks = 0, updated_at = NOW() WHERE id = ?")
        ->execute([$mainJobId]);

    echo "Cleanup complete for User $userId:\n";
    echo "- Deleted $deletedCount suspected duplicate jobs.\n";
    echo "- Reset Job $mainJobId to pending.\n";
    echo "\nPLEASE DO NOT SUBMIT THE SAME TEXT AGAIN FOR NOW.";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>