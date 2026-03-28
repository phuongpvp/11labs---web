<?php
require_once __DIR__ . '/config.php';

// Handle OPTIONS for CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$jobId = $input['job_id'] ?? '';
$audioBase64 = $input['audio_base64'] ?? '';
$srtContent = $input['srt_content'] ?? '';

if (!$jobId || !$audioBase64) {
    jsonResponse(['error' => 'Missing required data'], 400);
}

try {
    $resultsDir = __DIR__ . '/results';
    if (!is_dir($resultsDir)) {
        mkdir($resultsDir, 0755, true);
    }

    $audioPath = $resultsDir . '/' . $jobId . '.mp3';
    $srtPath = $resultsDir . '/' . $jobId . '.srt';

    // Save Audio
    file_put_contents($audioPath, base64_decode($audioBase64));

    // Save SRT if exists
    if ($srtContent) {
        file_put_contents($srtPath, $srtContent);
    }

    // Update Database — mark completed FIRST
    $db = getDB();
    // Lazy schema: ensure column exists
    try { $db->exec("ALTER TABLE conversion_jobs ADD COLUMN previous_chunk_text TEXT NULL"); } catch (Exception $e) {}
    $stmt = $db->prepare("UPDATE conversion_jobs SET status = 'completed', processed_chunks = total_chunks, partial_audio_path = NULL, processed_chars = 0, previous_chunk_text = NULL WHERE id = ?");
    $stmt->execute([$jobId]);

    // Cleanup partial audio file if exists
    $partialFile = $resultsDir . '/' . $jobId . '_partial.mp3';
    if (file_exists($partialFile)) {
        unlink($partialFile);
    }

    // THEN trigger next Job(s) in queue for this user
    // V15.8: Try to trigger up to 5 pending jobs to fill parallel slots
    $stmt_user = $db->prepare("SELECT user_id FROM conversion_jobs WHERE id = ?");
    $stmt_user->execute([$jobId]);
    $userId = $stmt_user->fetchColumn();

    if ($userId) {
        $stmt_next = $db->prepare("SELECT id FROM conversion_jobs WHERE user_id = ? AND status = 'pending' ORDER BY created_at ASC LIMIT 5");
        $stmt_next->execute([$userId]);
        $nextJobs = $stmt_next->fetchAll(PDO::FETCH_COLUMN);
        foreach ($nextJobs as $njId) {
            dispatchJob($njId);
        }
    }

    jsonResponse(['status' => 'success', 'message' => 'Job marked as completed']);

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
