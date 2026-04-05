<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$workerSecret = $_POST['secret'] ?? '';
if (!verifyWorkerSecret($workerSecret)) {
    jsonResponse(['error' => 'Unauthorized worker'], 403);
}

$jobId = $_POST['job_id'] ?? '';
if (!$jobId || !isset($_FILES['file'])) {
    jsonResponse(['error' => 'Missing job_id or file'], 400);
}

$file = $_FILES['file'];
$uploadDir = __DIR__ . '/../results/voice_changer/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$ext = pathinfo($file['name'], PATHINFO_EXTENSION) ?: 'mp3';
$newFileName = 'result_' . $jobId . '_' . time() . '.' . $ext;
$destination = $uploadDir . $newFileName;

if (!move_uploaded_file($file['tmp_name'], $destination)) {
    jsonResponse(['error' => 'Failed to save result file'], 500);
}

jsonResponse([
    'status' => 'success',
    'result_file' => $newFileName,
    'message' => 'Result uploaded successfully'
]);
