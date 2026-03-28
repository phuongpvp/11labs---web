<?php
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json');

// Handle CORS
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$headers = getallheaders();
$authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
$token = str_replace('Bearer ', '', $authHeader);

$userData = verifyToken($token);
if (!$userData || !isset($userData['user_id'])) {
    jsonResponse(['status' => 'error', 'error' => 'Unauthorized'], 401);
}
$userId = $userData['user_id'];

try {
    $db = getDB();

    // Lấy danh sách các file cần xóa trước khi xóa data trong DB
    $stmt = $db->prepare("SELECT source_file, result_file FROM isolation_jobs WHERE user_id = ?");
    $stmt->execute([$userId]);
    $jobs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $filesDeleted = 0;
    foreach ($jobs as $job) {
        $filesToCheck = [
            __DIR__ . '/../results/isolator/uploads/' . $job['source_file'],
            __DIR__ . '/../results/isolator/' . $job['result_file']
        ];

        foreach ($filesToCheck as $filePath) {
            if ($filePath && file_exists($filePath)) {
                if (@unlink($filePath)) {
                    $filesDeleted++;
                }
            }
        }
    }

    // Xóa tất cả các bản ghi trong DB
    $stmtDelete = $db->prepare("DELETE FROM isolation_jobs WHERE user_id = ?");
    $stmtDelete->execute([$userId]);
    $rowCount = $stmtDelete->rowCount();

    jsonResponse([
        'status' => 'success',
        'message' => 'Cleared history successfully',
        'jobs_deleted' => $rowCount,
        'files_deleted' => $filesDeleted
    ]);

} catch (Exception $e) {
    logToFile('isolator_error.log', "Clear history error: " . $e->getMessage());
    jsonResponse(['status' => 'error', 'error' => 'Lỗi server khi xóa lịch sử', 'details' => $e->getMessage()]);
}
?>