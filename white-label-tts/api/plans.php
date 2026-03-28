<?php
// =====================================================
// Quản lý Gói dịch vụ — CRUD + Kích hoạt + Đơn hàng
// =====================================================
require_once __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

$input = getInput();
$action = $input['action'] ?? ($_GET['action'] ?? '');

// ─── Public actions (no admin auth needed) ───

// List active plans for customers
if ($action === 'list_public') {
    $db = getDB();
    $plans = $db->query("SELECT id, name, quota, price, description FROM plans WHERE is_active = 1 ORDER BY sort_order ASC, quota ASC")->fetchAll();
    jsonResponse(['status' => 'success', 'plans' => $plans]);
}

// Customer purchase (auth by token)
if ($action === 'purchase') {
    $token = $input['token'] ?? '';
    $planId = (int) ($input['plan_id'] ?? 0);

    if (!$token || !$planId) {
        jsonResponse(['error' => 'Thiếu thông tin'], 400);
    }

    $db = getDB();

    // Verify customer token
    $stmt = $db->prepare("SELECT id, email FROM customers WHERE auth_token = ? AND is_active = 1");
    $stmt->execute([$token]);
    $customer = $stmt->fetch();
    if (!$customer) {
        jsonResponse(['error' => 'Phiên đăng nhập hết hạn'], 401);
    }

    // Check plan exists
    $stmt = $db->prepare("SELECT * FROM plans WHERE id = ? AND is_active = 1");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();
    if (!$plan) {
        jsonResponse(['error' => 'Gói không tồn tại'], 404);
    }

    // Check no existing pending order for this plan
    $stmt = $db->prepare("SELECT id FROM orders WHERE customer_id = ? AND plan_id = ? AND status = 'pending'");
    $stmt->execute([$customer['id'], $planId]);
    if ($stmt->fetch()) {
        jsonResponse(['error' => 'Bạn đã có đơn đang chờ duyệt cho gói này'], 400);
    }

    // Create order
    $stmt = $db->prepare("INSERT INTO orders (customer_id, plan_id, plan_name, plan_price, quota) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$customer['id'], $planId, $plan['name'], $plan['price'], $plan['quota']]);

    // Telegram notification
    $siteName = '';
    $sf = __DIR__ . '/../data/settings.json';
    if (file_exists($sf)) { $s = json_decode(file_get_contents($sf), true) ?? []; $siteName = $s['site_name'] ?? ''; }
    sendTelegramNotify(
        "🛒 <b>Đơn hàng mới" . ($siteName ? " - {$siteName}" : "") . "</b>\n\n" .
        "👤 Khách: <b>{$customer['email']}</b>\n" .
        "📦 Gói: <b>{$plan['name']}</b>\n" .
        "💰 Giá: {$plan['price']}\n" .
        "📝 Quota: " . number_format($plan['quota']) . " ký tự\n\n" .
        "⏳ Trạng thái: <i>Chờ duyệt</i>"
    );

    jsonResponse([
        'status' => 'success',
        'message' => "Đã gửi yêu cầu mua \"{$plan['name']}\". Vui lòng chuyển khoản và chờ Admin xác nhận."
    ]);
}

// Customer order history (auth by token)
if ($action === 'my_orders') {
    $token = $input['token'] ?? ($_GET['token'] ?? '');
    if (!$token)
        jsonResponse(['error' => 'Unauthorized'], 401);

    $db = getDB();
    $stmt = $db->prepare("SELECT id, email FROM customers WHERE auth_token = ? AND is_active = 1");
    $stmt->execute([$token]);
    $customer = $stmt->fetch();
    if (!$customer)
        jsonResponse(['error' => 'Unauthorized'], 401);

    $stmt = $db->prepare("SELECT id, plan_name, plan_price, quota, status, created_at FROM orders WHERE customer_id = ? ORDER BY created_at DESC LIMIT 20");
    $stmt->execute([$customer['id']]);

    jsonResponse(['status' => 'success', 'orders' => $stmt->fetchAll()]);
}

// ─── Admin actions ───
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
    case 'activate':
        handleActivate($input);
        break;
    case 'list_orders':
        handleListOrders($input);
        break;
    case 'approve_order':
        handleApproveOrder($input);
        break;
    case 'reject_order':
        handleRejectOrder($input);
        break;
    case 'history':
        handleHistory($input);
        break;
    default:
        jsonResponse(['error' => 'Invalid action'], 400);
}

// ─── Affiliate Bonus Helper ───
function giveAffiliateBonus($db, $customerId, $planQuota, $planName) {
    $settingsFile = __DIR__ . '/../data/settings.json';
    $settings = file_exists($settingsFile) ? (json_decode(file_get_contents($settingsFile), true) ?? []) : [];
    if (empty($settings['affiliate_enabled'])) return;
    $rate = floatval($settings['affiliate_commission_rate'] ?? 10);
    if ($rate <= 0) return;

    $stmt = $db->prepare("SELECT referred_by FROM customers WHERE id = ?");
    $stmt->execute([$customerId]);
    $referrerId = $stmt->fetchColumn();
    if (!$referrerId) return;

    $bonus = (int)round($planQuota * $rate / 100);
    if ($bonus <= 0) return;

    // Add bonus to referrer
    $db->prepare("UPDATE customers SET quota_allocated = quota_allocated + ? WHERE id = ?")->execute([$bonus, $referrerId]);

    // Log
    $db->prepare("INSERT INTO affiliate_bonus_logs (referrer_id, referred_id, bonus_amount, plan_quota, plan_name) VALUES (?, ?, ?, ?, ?)")
       ->execute([$referrerId, $customerId, $bonus, $planQuota, $planName]);
}

function handleList()
{
    $db = getDB();
    $plans = $db->query("SELECT * FROM plans ORDER BY sort_order ASC, quota ASC")->fetchAll();
    jsonResponse(['status' => 'success', 'plans' => $plans]);
}

function handleCreate($input)
{
    $name = trim($input['name'] ?? '');
    $quota = (int) ($input['quota'] ?? 0);
    $price = trim($input['price'] ?? '');
    $description = trim($input['description'] ?? '');
    $sortOrder = (int) ($input['sort_order'] ?? 0);

    if (!$name || $quota <= 0) {
        jsonResponse(['error' => 'Tên gói và quota phải lớn hơn 0'], 400);
    }

    $db = getDB();
    $stmt = $db->prepare("INSERT INTO plans (name, quota, price, description, sort_order) VALUES (?, ?, ?, ?, ?)");
    $stmt->execute([$name, $quota, $price, $description, $sortOrder]);

    jsonResponse([
        'status' => 'success',
        'message' => 'Đã tạo gói dịch vụ',
        'plan_id' => $db->lastInsertId()
    ]);
}

function handleUpdate($input)
{
    $id = (int) ($input['plan_id'] ?? 0);
    if (!$id)
        jsonResponse(['error' => 'plan_id required'], 400);

    $db = getDB();
    $updates = [];
    $params = [];

    if (isset($input['name'])) {
        $updates[] = "name = ?";
        $params[] = trim($input['name']);
    }
    if (isset($input['quota'])) {
        $updates[] = "quota = ?";
        $params[] = (int) $input['quota'];
    }
    if (isset($input['price'])) {
        $updates[] = "price = ?";
        $params[] = trim($input['price']);
    }
    if (isset($input['description'])) {
        $updates[] = "description = ?";
        $params[] = trim($input['description']);
    }
    if (isset($input['is_active'])) {
        $updates[] = "is_active = ?";
        $params[] = (int) $input['is_active'];
    }
    if (isset($input['sort_order'])) {
        $updates[] = "sort_order = ?";
        $params[] = (int) $input['sort_order'];
    }

    if (empty($updates)) {
        jsonResponse(['error' => 'Không có gì để cập nhật'], 400);
    }

    $params[] = $id;
    $sql = "UPDATE plans SET " . implode(', ', $updates) . " WHERE id = ?";
    $db->prepare($sql)->execute($params);

    jsonResponse(['status' => 'success', 'message' => 'Đã cập nhật gói']);
}

function handleDelete($input)
{
    $id = (int) ($input['plan_id'] ?? 0);
    if (!$id)
        jsonResponse(['error' => 'plan_id required'], 400);

    $db = getDB();
    $db->prepare("DELETE FROM plans WHERE id = ?")->execute([$id]);

    jsonResponse(['status' => 'success', 'message' => 'Đã xóa gói']);
}

function handleActivate($input)
{
    $customerId = (int) ($input['customer_id'] ?? 0);
    $planId = (int) ($input['plan_id'] ?? 0);

    if (!$customerId || !$planId) {
        jsonResponse(['error' => 'customer_id và plan_id là bắt buộc'], 400);
    }

    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM plans WHERE id = ? AND is_active = 1");
    $stmt->execute([$planId]);
    $plan = $stmt->fetch();
    if (!$plan)
        jsonResponse(['error' => 'Gói không tồn tại hoặc đã bị tắt'], 404);

    $stmt = $db->prepare("SELECT * FROM customers WHERE id = ?");
    $stmt->execute([$customerId]);
    $customer = $stmt->fetch();
    if (!$customer)
        jsonResponse(['error' => 'Khách hàng không tồn tại'], 404);

    $stmt = $db->prepare("UPDATE customers SET quota_allocated = ?, quota_used = 0, plan_name = ? WHERE id = ?");
    $stmt->execute([$plan['quota'], $plan['name'], $customerId]);

    $stmt = $db->prepare("INSERT INTO plan_activations (customer_id, plan_id, plan_name, quota_granted) VALUES (?, ?, ?, ?)");
    $stmt->execute([$customerId, $planId, $plan['name'], $plan['quota']]);

    // Affiliate bonus
    giveAffiliateBonus($db, $customerId, $plan['quota'], $plan['name']);

    jsonResponse([
        'status' => 'success',
        'message' => "Đã kích hoạt \"{$plan['name']}\" cho \"{$customer['email']}\" — {$plan['quota']} ký tự"
    ]);
}

function handleListOrders($input)
{
    $db = getDB();
    $status = $input['status'] ?? '';

    $query = "SELECT o.*, c.email as customer_email FROM orders o LEFT JOIN customers c ON o.customer_id = c.id";
    $params = [];

    if ($status) {
        $query .= " WHERE o.status = ?";
        $params[] = $status;
    }

    $query .= " ORDER BY o.created_at DESC LIMIT 100";
    $stmt = $db->prepare($query);
    $stmt->execute($params);

    jsonResponse(['status' => 'success', 'orders' => $stmt->fetchAll()]);
}

function handleApproveOrder($input)
{
    $orderId = (int) ($input['order_id'] ?? 0);
    if (!$orderId)
        jsonResponse(['error' => 'order_id required'], 400);

    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending'");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();
    if (!$order)
        jsonResponse(['error' => 'Đơn không tồn tại hoặc đã xử lý'], 404);

    // Activate plan for customer
    $stmt = $db->prepare("UPDATE customers SET quota_allocated = ?, quota_used = 0, plan_name = ? WHERE id = ?");
    $stmt->execute([$order['quota'], $order['plan_name'], $order['customer_id']]);

    // Log activation
    $stmt = $db->prepare("INSERT INTO plan_activations (customer_id, plan_id, plan_name, quota_granted) VALUES (?, ?, ?, ?)");
    $stmt->execute([$order['customer_id'], $order['plan_id'], $order['plan_name'], $order['quota']]);

    // Update order status
    $db->prepare("UPDATE orders SET status = 'approved', updated_at = datetime('now') WHERE id = ?")->execute([$orderId]);

    // Get customer email for message
    $stmt = $db->prepare("SELECT email FROM customers WHERE id = ?");
    $stmt->execute([$order['customer_id']]);
    $email = $stmt->fetchColumn();

    // Affiliate bonus
    giveAffiliateBonus($db, $order['customer_id'], $order['quota'], $order['plan_name']);

    jsonResponse([
        'status' => 'success',
        'message' => "Đã duyệt đơn #{$orderId} — Kích hoạt \"{$order['plan_name']}\" ({$order['quota']} ký tự) cho {$email}"
    ]);
}

function handleRejectOrder($input)
{
    $orderId = (int) ($input['order_id'] ?? 0);
    if (!$orderId)
        jsonResponse(['error' => 'order_id required'], 400);

    $db = getDB();

    $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND status = 'pending'");
    $stmt->execute([$orderId]);
    if (!$stmt->fetch())
        jsonResponse(['error' => 'Đơn không tồn tại hoặc đã xử lý'], 404);

    $db->prepare("UPDATE orders SET status = 'rejected', updated_at = datetime('now') WHERE id = ?")->execute([$orderId]);

    jsonResponse(['status' => 'success', 'message' => "Đã từ chối đơn #{$orderId}"]);
}

function handleHistory($input)
{
    $customerId = (int) ($input['customer_id'] ?? 0);
    $db = getDB();
    $query = "SELECT * FROM plan_activations";
    $params = [];
    if ($customerId) {
        $query .= " WHERE customer_id = ?";
        $params[] = $customerId;
    }
    $query .= " ORDER BY activated_at DESC LIMIT 50";
    $stmt = $db->prepare($query);
    $stmt->execute($params);
    jsonResponse(['status' => 'success', 'activations' => $stmt->fetchAll()]);
}
