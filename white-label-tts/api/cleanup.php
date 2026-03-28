<?php
// =====================================================
// Auto-cleanup: Xóa lịch sử + file audio quá 6 giờ
// Cron: */30 * * * * curl --silent https://11labsstudio.com/api/cleanup.php > /dev/null
// =====================================================
require_once __DIR__ . '/config.php';

try {
    $db = getDB();

    // 1. Lấy danh sách file audio của các record sắp xóa (SQLite syntax)
    $stmt = $db->prepare("SELECT result_file FROM tts_history WHERE created_at < datetime('now', '-6 hours') AND result_file IS NOT NULL AND result_file != ''");
    $stmt->execute();
    $oldFiles = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 2. Xóa lịch sử cũ từ DB
    $stmt = $db->prepare("DELETE FROM tts_history WHERE created_at < datetime('now', '-6 hours')");
    $stmt->execute();
    $deletedRows = $stmt->rowCount();

    // 3. Xóa file audio tương ứng
    $deletedFiles = 0;
    $audioDir = realpath(__DIR__ . '/../audio');
    if ($audioDir && is_dir($audioDir)) {
        foreach ($oldFiles as $fileUrl) {
            $filename = basename($fileUrl);
            $filePath = $audioDir . '/' . $filename;
            if (file_exists($filePath)) {
                @unlink($filePath);
                $deletedFiles++;
            }
        }

        // 4. Quét thêm file audio cũ (phòng trường hợp không có trong DB)
        $now = time();
        $maxAge = 6 * 3600;
        foreach (glob($audioDir . '/*.mp3') as $file) {
            if (($now - filemtime($file)) > $maxAge) {
                @unlink($file);
                $deletedFiles++;
            }
        }
    }

    // Output
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'success',
        'deleted_records' => $deletedRows,
        'deleted_files' => $deletedFiles,
        'remaining_files' => $audioDir ? count(glob($audioDir . '/*.mp3')) : 0
    ]);

} catch (Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
