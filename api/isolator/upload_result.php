<?php
require_once __DIR__ . '/../config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Security check for worker
$workerSecret = $_POST['secret'] ?? '';
if (!verifyWorkerSecret($workerSecret)) {
    jsonResponse(['error' => 'Unauthorized worker'], 403);
}

if (!isset($_FILES['file'])) {
    jsonResponse(['error' => 'No file uploaded'], 400);
}

$jobId = $_POST['job_id'] ?? '';
if (!$jobId) {
    jsonResponse(['error' => 'Missing job_id'], 400);
}

$file = $_FILES['file'];
$fileName = $file['name'];
$fileTmp = $file['tmp_name'];

// Validate extension for result
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
if (!in_array($ext, ['mp3', 'wav', 'm4a'])) {
    jsonResponse(['error' => 'Invalid file extension'], 400);
}

$uploadDir = __DIR__ . '/../results/isolator/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$finalName = "result_" . $jobId . "." . $ext;
$destination = $uploadDir . $finalName;

if (move_uploaded_file($fileTmp, $destination)) {
    jsonResponse(['status' => 'success', 'filename' => $finalName]);
} else {
    jsonResponse(['error' => 'Failed to save result file'], 500);
}
