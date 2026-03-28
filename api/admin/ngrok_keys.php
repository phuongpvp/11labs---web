<?php
// =====================================================
// admin/ngrok_keys.php - Quản lý Pool Token Ngrok
// GET  => Lấy danh sách tất cả token
// POST action=add    => Thêm token(s) mới
// POST action=delete => Xóa token
// POST action=reset  => Giải phóng token về trạng thái rảnh
// POST action=toggle => Bật/Tắt token
// =====================================================

require_once dirname(__DIR__) . '/config.php';

// Xác thực Admin (dùng cùng header như các API admin khác)
$adminPass = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
if (!$adminPass || !verifyAdminPassword($adminPass)) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}

$db = getDB();
$method = $_SERVER['REQUEST_METHOD'];

// ======================== GET: Lấy danh sách ========================
if ($method === 'GET') {
    $stmt = $db->query("
        SELECT
            nk.id,
            CONCAT(SUBSTRING(nk.token, 1, 8), '...', SUBSTRING(nk.token, -6)) AS token_masked,
            nk.worker_name,
            nk.worker_uuid,
            nk.worker_ip,
            nk.assigned_at,
            nk.is_active,
            nk.created_at,
            CASE
                WHEN nk.worker_uuid IS NULL THEN 'free'
                WHEN w.last_seen < (NOW() - INTERVAL 5 MINUTE) THEN 'expired'
                ELSE 'in_use'
            END AS status,
            w.last_seen
        FROM ngrok_keys nk
        LEFT JOIN workers w ON nk.worker_uuid = w.worker_uuid
        ORDER BY CAST(REGEXP_REPLACE(COALESCE(nk.worker_name, '999'), '[^0-9]', '') AS UNSIGNED) ASC, nk.id ASC
    ");
    $keys = $stmt->fetchAll();
    jsonResponse(['success' => true, 'keys' => $keys]);
}

// ======================== POST: Các thao tác ========================
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    // Thêm token(s)
    if ($action === 'add') {
        $rawTokens = $input['tokens'] ?? '';
        $workerNames = $input['worker_names'] ?? [];

        // Tách nhiều token bằng dòng mới hoặc dấu phẩy
        $tokenList = array_filter(array_map('trim', preg_split('/[\n,]+/', $rawTokens)));

        if (empty($tokenList)) {
            jsonResponse(['error' => 'No valid tokens provided'], 400);
        }

        $stmt = $db->prepare("INSERT INTO ngrok_keys (token, worker_name) VALUES (?, ?)");
        $added = 0;
        $duplicates = 0;

        foreach ($tokenList as $idx => $token) {
            if (empty($token))
                continue;

            // Kiểm tra trùng
            $checkStmt = $db->prepare("SELECT id FROM ngrok_keys WHERE token = ?");
            $checkStmt->execute([$token]);
            if ($checkStmt->fetch()) {
                $duplicates++;
                continue;
            }

            $name = $workerNames[$idx] ?? null;
            $stmt->execute([$token, $name]);
            $added++;
        }

        jsonResponse([
            'success' => true,
            'added' => $added,
            'duplicates' => $duplicates,
            'message' => "Đã thêm $added token" . ($duplicates > 0 ? ", bỏ qua $duplicates token trùng" : "")
        ]);
    }

    // Xóa token
    if ($action === 'delete') {
        $id = intval($input['id'] ?? 0);
        if (!$id)
            jsonResponse(['error' => 'Invalid ID'], 400);

        $db->prepare("DELETE FROM ngrok_keys WHERE id = ?")->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'Token đã được xóa']);
    }

    // Giải phóng token về trạng thái rảnh
    if ($action === 'reset') {
        $id = intval($input['id'] ?? 0);
        if ($id) {
            // Reset 1 token
            $db->prepare("UPDATE ngrok_keys SET worker_uuid = NULL, worker_ip = NULL, assigned_at = NULL WHERE id = ?")
                ->execute([$id]);
            jsonResponse(['success' => true, 'message' => 'Token đã được giải phóng']);
        } else {
            // Reset toàn bộ
            $db->exec("UPDATE ngrok_keys SET worker_uuid = NULL, worker_ip = NULL, assigned_at = NULL");
            jsonResponse(['success' => true, 'message' => 'Tất cả token đã được giải phóng']);
        }
    }

    // Giải phóng token theo worker_name (dùng khi Disconnect worker)
    if ($action === 'reset_by_worker') {
        $workerName = trim($input['worker_name'] ?? '');
        if (!$workerName)
            jsonResponse(['error' => 'Worker name required'], 400);

        // Find worker_uuid from colab_extensions table
        $stmtW = $db->prepare("SELECT worker_name FROM colab_extensions WHERE worker_name = ?");
        $stmtW->execute([$workerName]);
        $ext = $stmtW->fetch();

        if ($ext) {
            // Release ngrok token matching this worker_name
            $stmtR = $db->prepare("UPDATE ngrok_keys SET worker_uuid = NULL, worker_ip = NULL, assigned_at = NULL WHERE worker_name = ?");
            $stmtR->execute([$workerName]);
            $affected = $stmtR->rowCount();
            jsonResponse(['success' => true, 'message' => "Đã nhả $affected token của $workerName"]);
        } else {
            jsonResponse(['success' => true, 'message' => 'Worker không tìm thấy, token có thể đã được nhả']);
        }
    }

    // Cập nhật tên token
    if ($action === 'update_name') {
        $id = intval($input['id'] ?? 0);
        $name = trim($input['worker_name'] ?? '');
        if (!$id)
            jsonResponse(['error' => 'Invalid ID'], 400);

        $db->prepare("UPDATE ngrok_keys SET worker_name = ? WHERE id = ?")->execute([$name ?: null, $id]);
        jsonResponse(['success' => true, 'message' => 'Tên đã được cập nhật']);
    }

    // Bật/Tắt token
    if ($action === 'toggle') {
        $id = intval($input['id'] ?? 0);
        if (!$id)
            jsonResponse(['error' => 'Invalid ID'], 400);

        $db->prepare("UPDATE ngrok_keys SET is_active = 1 - is_active WHERE id = ?")->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'Trạng thái token đã được cập nhật']);
    }

    // Cập nhật token value
    if ($action === 'update_token') {
        $id = intval($input['id'] ?? 0);
        $newToken = trim($input['token'] ?? '');
        if (!$id)
            jsonResponse(['error' => 'Invalid ID'], 400);
        if (!$newToken)
            jsonResponse(['error' => 'Token không được để trống'], 400);

        // Kiểm tra trùng
        $checkStmt = $db->prepare("SELECT id FROM ngrok_keys WHERE token = ? AND id != ?");
        $checkStmt->execute([$newToken, $id]);
        if ($checkStmt->fetch()) {
            jsonResponse(['error' => 'Token này đã tồn tại!'], 409);
        }

        // Lấy worker_name trước khi reset để dọn dẹp entry cũ
        $nameStmt = $db->prepare("SELECT worker_name, worker_uuid FROM ngrok_keys WHERE id = ?");
        $nameStmt->execute([$id]);
        $oldKey = $nameStmt->fetch(PDO::FETCH_ASSOC);
        $workerName = $oldKey['worker_name'] ?? '';
        $oldUuid = $oldKey['worker_uuid'] ?? '';

        $db->prepare("UPDATE ngrok_keys SET token = ?, worker_uuid = NULL, worker_ip = NULL, assigned_at = NULL WHERE id = ?")->execute([$newToken, $id]);

        // Dọn entry offline cũ khi đổi token
        if ($workerName) {
            try {
                $db->prepare("DELETE FROM colab_extensions WHERE worker_name = ?")->execute([$workerName]);
            } catch (Exception $e) { /* ignore */ }
        }
        // Xóa worker offline cũ theo worker_name (match cả Sever/Server format)
        if ($workerName) {
            try {
                $db->prepare("DELETE FROM workers WHERE (worker_name = ? OR worker_name = ?) AND last_seen < (NOW() - INTERVAL 300 SECOND)")
                    ->execute([$workerName, str_replace('Sever', 'Server', $workerName)]);
            } catch (Exception $e) { /* ignore */ }
        }
        if ($oldUuid) {
            try {
                $db->prepare("DELETE FROM workers WHERE worker_uuid = ? AND last_seen < (NOW() - INTERVAL 300 SECOND)")->execute([$oldUuid]);
            } catch (Exception $e) { /* ignore */ }
        }

        jsonResponse(['success' => true, 'message' => 'Token đã được cập nhật']);
    }

    jsonResponse(['error' => 'Invalid action'], 400);
}

jsonResponse(['error' => 'Method not allowed'], 405);
