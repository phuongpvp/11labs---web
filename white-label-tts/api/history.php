<?php
// =====================================================
// Lịch sử TTS của khách
// =====================================================
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

// Verify customer
$token = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$token = str_replace('Bearer ', '', $token);
$customer = verifyCustomerToken($token);

if (!$customer) {
    jsonResponse(['error' => 'Vui lòng đăng nhập'], 401);
}

$db = getDB();

// POST: Clear all history
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (($input['action'] ?? '') === 'clear_all') {
        $stmt = $db->prepare("DELETE FROM tts_history WHERE customer_id = ?");
        $stmt->execute([$customer['id']]);
        jsonResponse(['status' => 'success', 'message' => 'Đã xóa tất cả lịch sử']);
    }
    jsonResponse(['error' => 'Invalid action'], 400);
}

// GET: List history
// Auto-cleanup old records (throttled to every 10 min)
$lockFile = sys_get_temp_dir() . '/tts_cleanup_' . md5(__DIR__) . '.lock';
if (!file_exists($lockFile) || (time() - filemtime($lockFile)) > 600) {
    @touch($lockFile);
    // Delete history older than 6 hours (SQLite syntax)
    $db->exec("DELETE FROM tts_history WHERE created_at < datetime('now', '-6 hours')");
    // Delete audio files older than 6 hours
    $audioDir = realpath(__DIR__ . '/../audio');
    if ($audioDir && is_dir($audioDir)) {
        $now = time();
        foreach (glob($audioDir . '/*.mp3') as $file) {
            if (($now - filemtime($file)) > 21600) { // 6h = 21600s
                @unlink($file);
            }
        }
    }
}

// Filter by type: tts (default) or conversation
$type = $_GET['type'] ?? 'tts';
// Ensure result_srt column exists
try { $db->exec("ALTER TABLE tts_history ADD COLUMN result_srt TEXT DEFAULT ''"); } catch (Exception $e) {}
if ($type === 'conversation') {
    $stmt = $db->prepare("SELECT job_id, text_preview, characters_used, voice_id, model_id, status, result_file, result_srt, error_message, created_at FROM tts_history WHERE customer_id = ? AND voice_id = 'conversation' ORDER BY created_at DESC LIMIT 50");
} else {
    $stmt = $db->prepare("SELECT job_id, text_preview, characters_used, voice_id, model_id, status, result_file, result_srt, error_message, created_at FROM tts_history WHERE customer_id = ? AND (voice_id != 'conversation' OR voice_id IS NULL) ORDER BY created_at DESC LIMIT 50");
}
$stmt->execute([$customer['id']]);
$history = $stmt->fetchAll();

jsonResponse([
    'status' => 'success',
    'history' => $history
]);
