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

// 2. Check for uploaded file and voice_id
$voiceId = $_POST['voice_id'] ?? '';
if (!$voiceId) {
    jsonResponse(['error' => 'Missing voice_id'], 400);
}

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

// 3. Validate file type
$allowed = ['mp3', 'wav', 'm4a', 'flac', 'mp4', 'mov', 'avi', 'mkv', 'ogg'];
$ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

if (!in_array($ext, $allowed)) {
    jsonResponse(['error' => 'File type not supported. Allowed: ' . implode(', ', $allowed)], 400);
}

// 4. Validate file size (max 50MB)
if ($fileSize > 50 * 1024 * 1024) {
    jsonResponse(['error' => 'File too large. Max 50MB'], 400);
}

// 5. Save file
$uploadDir = __DIR__ . '/../results/voice_changer/uploads/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$newFileName = uniqid('vc_', true) . '.' . $ext;
$destination = $uploadDir . $newFileName;

if (!move_uploaded_file($fileTmp, $destination)) {
    jsonResponse(['error' => 'Failed to save uploaded file'], 500);
}

try {
    $db = getDB();

    // Generate random ID
    $jobId = strtoupper(substr(md5(uniqid('', true)), 0, 8));

    // 6. Create job in voice_changer_jobs
    $stmt = $db->prepare("INSERT INTO voice_changer_jobs (id, user_id, voice_id, source_file, status) VALUES (?, ?, ?, ?, 'pending')");
    $stmt->execute([$jobId, $userId, $voiceId, $newFileName]);

    jsonResponse([
        'status' => 'success',
        'job_id' => $jobId,
        'message' => 'File uploaded and queued for voice changer processing'
    ]);

} catch (Exception $e) {
    jsonResponse(['error' => 'Database error: ' . $e->getMessage()], 500);
}
