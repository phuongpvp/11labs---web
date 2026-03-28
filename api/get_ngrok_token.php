<?php
// =====================================================
// get_ngrok_token.php - Tự động cấp phát Ngrok Token
// =====================================================
// Colab gọi API này khi khởi động để nhận token Ngrok.
// Logic:
//   1. Thu hồi token của các máy chết (last_seen > 5 phút)
//   2. Nếu IP này đã được cấp token trước đó => trả lại token cũ
//   3. Nếu IP mới => lấy token rảnh trong pool, cấp cho máy này
// =====================================================

require_once __DIR__ . '/config.php';

// Chỉ cho phép POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$secret = $input['secret'] ?? '';
$workerUuid = $input['worker_uuid'] ?? '';

// Xác thực secret để bảo mật
if (!verifyWorkerSecret($secret)) {
    jsonResponse(['error' => 'Invalid secret'], 403);
}

if (!$workerUuid) {
    jsonResponse(['error' => 'worker_uuid is required'], 400);
}

// Lấy IP của Colab
$workerIp = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
// Lấy IP đầu tiên nếu có nhiều IP trong chuỗi (proxy)
$workerIp = trim(explode(',', $workerIp)[0]);

try {
    $db = getDB();

    // =====================================================
    // BƯỚC 1: Xóa các UUID "ma" (worker chết hoặc kẹt)
    // =====================================================
    // 1. Thu hồi từ các worker đã có trong bảng workers nhưng offline > 5 phút
    $deadWorkers = $db->query("
        SELECT worker_uuid FROM workers
        WHERE last_seen < (NOW() - INTERVAL 5 MINUTE)
    ")->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($deadWorkers)) {
        $placeholders = implode(',', array_fill(0, count($deadWorkers), '?'));
        $stmtRelease = $db->prepare("
            UPDATE ngrok_keys
            SET worker_uuid = NULL, worker_ip = NULL, assigned_at = NULL
            WHERE worker_uuid IN ($placeholders)
        ");
        $stmtRelease->execute($deadWorkers);
    }

    // 2. Thu hồi các token đã gán > 5 phút nhưng worker KHÔNG bao giờ có mặt trong bảng workers (kẹt lúc khởi động)
    $db->exec("
        UPDATE ngrok_keys
        SET worker_uuid = NULL, worker_ip = NULL, assigned_at = NULL
        WHERE worker_uuid IS NOT NULL
          AND assigned_at < (NOW() - INTERVAL 5 MINUTE)
          AND worker_uuid NOT IN (SELECT worker_uuid FROM workers)
    ");

    $excludeTokens = $input['exclude_tokens'] ?? [];
    if (!is_array($excludeTokens))
        $excludeTokens = [];

    // =====================================================
    // BƯỚC 2: Kiểm tra UUID này đã có token chưa (Restart máy cũ)
    // =====================================================
    $stmt = $db->prepare("SELECT * FROM ngrok_keys WHERE worker_uuid = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$workerUuid]);
    $existingKeyByUuid = $stmt->fetch();

    if ($existingKeyByUuid) {
        if (in_array($existingKeyByUuid['token'], $excludeTokens)) {
            // Token này bị lỗi rate-limit -> Giải phóng nó để lấy token khác ở Bước 3
            $db->prepare("UPDATE ngrok_keys SET worker_uuid = NULL, worker_ip = NULL, assigned_at = NULL WHERE id = ?")
                ->execute([$existingKeyByUuid['id']]);
            $existingKeyByUuid = null; // Rơi xuống Bước 3
        } else {
            $db->prepare("UPDATE ngrok_keys SET assigned_at = NOW() WHERE id = ?")
                ->execute([$existingKeyByUuid['id']]);

            jsonResponse([
                'success' => true,
                'token' => $existingKeyByUuid['token'],
                'worker_name' => $existingKeyByUuid['worker_name'] ?? ('Worker-' . substr($workerUuid, 0, 6)),
                'source' => 'reuse_uuid'
            ]);
        }
    }

    // Nếu không trùng UUID, check xem IP này có đang giữ token nào "treo" không (Của UUID cũ)
    // Nếu có => GIẢI PHÓNG NÓ NGAY để tránh xung đột session Ngrok
    $stmt = $db->prepare("SELECT id FROM ngrok_keys WHERE worker_ip = ? AND is_active = 1");
    $stmt->execute([$workerIp]);
    $oldKeyByIp = $stmt->fetch();
    if ($oldKeyByIp) {
        $db->prepare("UPDATE ngrok_keys SET worker_uuid = NULL, worker_ip = NULL, assigned_at = NULL WHERE id = ?")
            ->execute([$oldKeyByIp['id']]);
    }

    // =====================================================
    // BƯỚC 3: Lấy token rảnh mới từ pool
    // =====================================================
    $targetName = $input['target_name'] ?? '';

    $excludeSql = "";
    $params = [];
    if (!empty($excludeTokens)) {
        $placeholders = implode(',', array_fill(0, count($excludeTokens), '?'));
        $excludeSql = "AND token NOT IN ($placeholders)";
        $params = $excludeTokens;
    }

    $db->beginTransaction();

    $freeKey = null;

    // Ưu tiên cấp đúng tên Server được yêu cầu từ Colab
    if (!empty($targetName)) {
        // Build all name variants (Sv2, Sever2, Server2) to match DB
        $num = preg_replace('/^(?:Sever|Server|Sv)/i', '', $targetName);
        $nameVariants = ["Sv$num", "Sever$num", "Server$num", $targetName];
        $nameVariants = array_unique($nameVariants);
        $namePlaceholders = implode(',', array_fill(0, count($nameVariants), '?'));

        $targetParams = array_merge($nameVariants, $params);
        $stmtTarget = $db->prepare("
            SELECT * FROM ngrok_keys
            WHERE is_active = 1
              AND worker_uuid IS NULL
              AND worker_name IN ($namePlaceholders)
              $excludeSql
            LIMIT 1
            FOR UPDATE
        ");
        $stmtTarget->execute($targetParams);
        $freeKey = $stmtTarget->fetch();
    }

    // Nếu có target_name nhưng token đúng tên đang bận → KHÔNG lấy bừa, trả lỗi retry
    if (!$freeKey && !empty($targetName)) {
        $db->rollBack();
        jsonResponse([
            'error' => "Token for $targetName is currently busy. Will retry.",
            'retry' => true
        ], 503);
    }

    // Chỉ fallback lấy token rảnh bất kỳ khi KHÔNG có target_name
    if (!$freeKey) {
        $stmtFree = $db->prepare("
            SELECT * FROM ngrok_keys
            WHERE is_active = 1
              AND worker_uuid IS NULL
              $excludeSql
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
        ");
        $stmtFree->execute($params);
        $freeKey = $stmtFree->fetch();
    }

    if (!$freeKey) {
        $db->rollBack();
        jsonResponse(['error' => 'No available Ngrok tokens. Please add more tokens in Admin panel.'], 503);
    }

    // Gán token này cho máy hiện tại
    $db->prepare("
        UPDATE ngrok_keys
        SET worker_uuid = ?, worker_ip = ?, assigned_at = NOW()
        WHERE id = ?
    ")->execute([$workerUuid, $workerIp, $freeKey['id']]);

    $db->commit();

    jsonResponse([
        'success' => true,
        'token' => $freeKey['token'],
        'worker_name' => $freeKey['worker_name'] ?? ('Worker-' . substr($workerUuid, 0, 6)),
        'source' => !empty($targetName) && $freeKey['worker_name'] === $targetName ? 'target_name' : 'new'
    ]);

} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    logToFile('error_' . date('Y-m-d') . '.log', "get_ngrok_token error: " . $e->getMessage());
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
