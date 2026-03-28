<?php
// =====================================================
// Đại lý CRUD khách cuối
// =====================================================
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

$input = getInput();
$action = $input['action'] ?? ($_GET['action'] ?? '');

// Verify admin password
verifyAdmin();

switch ($action) {
    case 'list':
        handleList();
        break;
    case 'create':
        handleCreate($input);
        break;
    case 'update':
        handleUpdate($input);
        break;
    case 'delete':
        handleDelete($input);
        break;
    case 'partner_info':
        handlePartnerInfo();
        break;
    default:
        jsonResponse(['error' => 'Invalid action. Use: list, create, update, delete, partner_info'], 400);
}

function handlePartnerInfo()
{
    // Get partner quota from admin server
    $res = callExternalAPI('user.php');
    if ($res['code'] === 200) {
        $data = $res['body'];

        // Calculate total allocated to customers
        $db = getDB();
        $totalAllocated = $db->query("SELECT COALESCE(SUM(quota_allocated), 0) as total FROM customers WHERE is_active = 1")->fetch()['total'];
        $totalUsed = $db->query("SELECT COALESCE(SUM(quota_used), 0) as total FROM customers")->fetch()['total'];
        $customerCount = $db->query("SELECT COUNT(*) as cnt FROM customers")->fetch()['cnt'];

        jsonResponse([
            'status' => 'success',
            'partner' => [
                'quota_total' => $data['quota_total'] ?? 0,
                'quota_used' => $data['quota_used'] ?? 0,
                'remaining' => $data['remaining_quota'] ?? 0,
                'expires_at' => $data['expires_at'] ?? null
            ],
            'customers' => [
                'count' => (int) $customerCount,
                'total_allocated' => (int) $totalAllocated,
                'total_used' => (int) $totalUsed,
                'allocatable' => max(0, ($data['remaining_quota'] ?? 0) - (int) $totalAllocated + (int) $totalUsed)
            ]
        ]);
    } else {
        jsonResponse(['error' => 'Không thể kết nối server. Kiểm tra API Key.'], $res['code'] ?: 500);
    }
}

function handleList()
{
    $db = getDB();
    $customers = $db->query("SELECT id, email, display_name, quota_allocated, quota_used, is_active, plan_name, created_at FROM customers ORDER BY created_at DESC")->fetchAll();

    jsonResponse([
        'status' => 'success',
        'customers' => $customers
    ]);
}

function handleCreate($input)
{
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $name = trim($input['display_name'] ?? '');
    $quota = (int) ($input['quota_allocated'] ?? 0);

    if (!$email || !$password) {
        jsonResponse(['error' => 'Email và mật khẩu là bắt buộc'], 400);
    }

    $db = getDB();

    // Check duplicate
    $stmt = $db->prepare("SELECT id FROM customers WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Email đã tồn tại'], 409);
    }

    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $db->prepare("INSERT INTO customers (email, password_hash, display_name, quota_allocated) VALUES (?, ?, ?, ?)");
    $stmt->execute([$email, $hash, $name ?: explode('@', $email)[0], $quota]);

    jsonResponse([
        'status' => 'success',
        'message' => 'Đã tạo khách hàng',
        'customer_id' => $db->lastInsertId()
    ]);
}

function handleUpdate($input)
{
    $id = (int) ($input['customer_id'] ?? 0);
    if (!$id)
        jsonResponse(['error' => 'customer_id required'], 400);

    $db = getDB();
    $updates = [];
    $params = [];

    if (isset($input['display_name'])) {
        $updates[] = "display_name = ?";
        $params[] = $input['display_name'];
    }
    if (isset($input['quota_allocated'])) {
        $updates[] = "quota_allocated = ?";
        $params[] = (int) $input['quota_allocated'];
    }
    if (isset($input['quota_used'])) {
        $updates[] = "quota_used = ?";
        $params[] = (int) $input['quota_used'];
    }
    if (isset($input['is_active'])) {
        $updates[] = "is_active = ?";
        $params[] = (int) $input['is_active'];
    }
    if (isset($input['password']) && $input['password']) {
        $updates[] = "password_hash = ?";
        $params[] = password_hash($input['password'], PASSWORD_BCRYPT);
    }

    if (empty($updates)) {
        jsonResponse(['error' => 'Không có gì để cập nhật'], 400);
    }

    $params[] = $id;
    $sql = "UPDATE customers SET " . implode(', ', $updates) . " WHERE id = ?";
    $db->prepare($sql)->execute($params);

    jsonResponse(['status' => 'success', 'message' => 'Đã cập nhật']);
}

function handleDelete($input)
{
    $id = (int) ($input['customer_id'] ?? 0);
    if (!$id)
        jsonResponse(['error' => 'customer_id required'], 400);

    $db = getDB();
    $db->prepare("DELETE FROM customers WHERE id = ?")->execute([$id]);
    $db->prepare("DELETE FROM tts_history WHERE customer_id = ?")->execute([$id]);

    jsonResponse(['status' => 'success', 'message' => 'Đã xóa khách hàng']);
}
