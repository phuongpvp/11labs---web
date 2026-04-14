<?php
// =====================================================
// Nhận audio data từ trình duyệt và lưu vào thư mục audio/
// (Không cần PHP gọi ra ngoài)
// =====================================================
require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

// Verify customer
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);
$customer = verifyCustomerToken($token);

if (!$customer) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

// Check for multipart upload (audio blob)
if (isset($_FILES['audio'])) {
    $jobId = $_POST['job_id'] ?? 'unknown';
    $file = $_FILES['audio'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        jsonResponse(['error' => 'Upload failed', 'code' => $file['error']], 500);
    }

    // Reject files that are too small (likely error responses saved as MP3)
    if ($file['size'] < 1000) {
        jsonResponse(['error' => 'Audio file too small (' . $file['size'] . ' bytes), likely corrupted'], 400);
    }

    // Create audio directory
    $audioDir = __DIR__ . '/../audio';
    if (!is_dir($audioDir)) {
        mkdir($audioDir, 0755, true);
    }

    $filename = strtolower($jobId) . '_' . time() . '.mp3';
    $filePath = $audioDir . '/' . $filename;

    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        $response = [
            'status' => 'success',
            'local_url' => 'audio/' . $filename,
            'size' => filesize($filePath),
            'filename' => $filename
        ];

        // Also save SRT if provided
        if (isset($_FILES['srt']) && $_FILES['srt']['error'] === UPLOAD_ERR_OK) {
            $srtFilename = str_replace('.mp3', '.srt', $filename);
            $srtPath = $audioDir . '/' . $srtFilename;
            if (move_uploaded_file($_FILES['srt']['tmp_name'], $srtPath)) {
                $response['srt_local_url'] = 'audio/' . $srtFilename;
            }
        }

        jsonResponse($response);
    } else {
        jsonResponse(['error' => 'Failed to save file'], 500);
    }
}

// Check for base64 JSON upload
$input = json_decode(file_get_contents('php://input'), true);
if ($input && isset($input['audio_data'])) {
    $jobId = $input['job_id'] ?? 'unknown';
    $audioData = base64_decode($input['audio_data']);

    if (!$audioData) {
        jsonResponse(['error' => 'Invalid audio data'], 400);
    }

    $audioDir = __DIR__ . '/../audio';
    if (!is_dir($audioDir)) {
        mkdir($audioDir, 0755, true);
    }

    $filename = strtolower($jobId) . '_' . time() . '.mp3';
    $filePath = $audioDir . '/' . $filename;

    if (file_put_contents($filePath, $audioData)) {
        jsonResponse([
            'status' => 'success',
            'local_url' => 'audio/' . $filename,
            'size' => strlen($audioData),
            'filename' => $filename
        ]);
    } else {
        jsonResponse(['error' => 'Failed to save file'], 500);
    }
}

jsonResponse(['error' => 'No audio data received'], 400);
