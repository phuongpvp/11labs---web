<?php
require_once __DIR__ . '/../config.php';

ini_set('upload_max_filesize', '50M');
ini_set('post_max_size', '55M');
ini_set('max_execution_time', '120');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

$jobId = $_POST['job_id'] ?? '';
$workerSecret = $_POST['secret'] ?? '';

if (!verifyWorkerSecret($workerSecret)) {
    jsonResponse(['error' => 'Unauthorized'], 403);
}

if (!$jobId) {
    jsonResponse(['error' => 'Missing job_id'], 400);
}

if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    jsonResponse(['error' => 'No file uploaded'], 400);
}

$uploadDir = __DIR__ . '/../results/sfx/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$filename = 'sfx_' . $jobId . '_' . time() . '.mp3';
$targetPath = $uploadDir . $filename;

if (!move_uploaded_file($_FILES['file']['tmp_name'], $targetPath)) {
    jsonResponse(['error' => 'Failed to move uploaded file'], 500);
}

jsonResponse([
    'status' => 'success',
    'filename' => $filename
]);
