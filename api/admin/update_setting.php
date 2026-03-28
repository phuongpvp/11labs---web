<?php
require_once __DIR__ . '/../config.php';

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['admin_password']) || !verifyAdminPassword($input['admin_password'])) {
    jsonResponse(['error' => 'Unauthorized'], 401);
}
if (!$input || !isset($input['key']) || !isset($input['value'])) {
    jsonResponse(['error' => 'Invalid input'], 400);
}

$updated = setSystemSetting($input['key'], $input['value']);

if ($updated) {
    jsonResponse(['status' => 'success', 'message' => 'Đã cập nhật cài đặt']);
} else {
    jsonResponse(['error' => 'Không thể cập nhật cài đặt'], 500);
}
?>