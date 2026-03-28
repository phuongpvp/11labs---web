<?php
require_once __DIR__ . '/config.php';
$jobId = 'zzBTsLBFM6AOJtkr1e9b';
try {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM conversion_jobs WHERE id = ?");
    $stmt->execute([$jobId]);
    $job = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($job) {
        $info = "DEBUG JOB INFO: ID: " . $job['id'] . " | Status: " . $job['status'] . " | Voice: " . $job['voice_id'] . " | Model: " . $job['model_id'] . " | Settings: " . ($job['voice_settings'] ? $job['voice_settings'] : 'None');
        logToFile('admin_actions.log', $info);
        echo "Logged info for $jobId";
    } else {
        echo "Job $jobId not found";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>