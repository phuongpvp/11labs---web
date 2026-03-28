<?php
require_once __DIR__ . '/../config.php';

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// 1. Verify user token
$headers = getallheaders();
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
$token = str_replace('Bearer ', '', $auth);
$userData = verifyToken($token);

if (!$userData) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$userId = $userData['user_id'];

// 2. Check for uploaded file
if (!isset($_FILES['file'])) {
    jsonResponse(['error' => 'No file uploaded'], 400);
}

$file = $_FILES['file'];
$fileName = $file['name'];
$fileTmp = $file['tmp_name'];
$fileSize = $file['size'];
$fileError = $file['error'];

if ($fileError !== 0) {
    jsonResponse(['error' => 'File upload error code: ' . $fileError], 500);
}

// 3. Validate file type (mp3, wav, m4a, mp4, etc.)
$allowed = ['mp3', 'wav', 'm4a', 'flac', 'mp4', 'mov', 'avi', 'mkv'];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if (!in_array($ext, $allowed)) {
    jsonResponse(['error' => 'File type not supported. Allowed: ' . implode(', ', $allowed)], 400);
}

// 4. Validate file size (e.g., max 100MB)
if ($fileSize > 100 * 1024 * 1024) {
    jsonResponse(['error' => 'File too large. Max 100MB'], 400);
}

// 5. Save file
$uploadDir = __DIR__ . '/../results/isolator/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$newFileName = uniqid('isolate_', true) . '.' . $ext;
$destination = $uploadDir . $newFileName;

if (!move_uploaded_file($fileTmp, $destination)) {
    jsonResponse(['error' => 'Failed to save uploaded file'], 500);
}

try {
    $db = getDB();

    // Migration: Change ID to VARCHAR for alphanumeric support like conversion_jobs
    try {
        $db->exec("ALTER TABLE isolation_jobs MODIFY COLUMN id VARCHAR(50) NOT NULL");
    } catch (Exception $e) {
    }

    // Generate random 8-character uppercase ID like conversion_jobs
    $jobId = strtoupper(substr(md5(uniqid('', true)), 0, 8));

    // 6. Create job in isolation_jobs
    $stmt = $db->prepare("INSERT INTO isolation_jobs (id, user_id, source_file, status) VALUES (?, ?, ?, 'pending')");
    $stmt->execute([$jobId, $userId, $newFileName]);

    jsonResponse([
        'status' => 'success',
        'job_id' => $jobId,
        'message' => 'File uploaded and queued for processing'
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
}
