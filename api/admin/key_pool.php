<?php
require_once __DIR__ . '/../config.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    jsonResponse(['status' => 'ok']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

$input = json_decode(file_get_contents('php://input'), true);
$adminPassword = $input['admin_password'] ?? '';
$action = $input['action'] ?? '';

if (!$adminPassword) {
    jsonResponse(['error' => 'Admin password required'], 400);
}

if (!verifyAdminPassword($adminPassword)) {
    jsonResponse(['error' => 'Invalid admin password'], 403);
}

try {
    $db = getDB();

    // Auto-create table
    $db->exec("CREATE TABLE IF NOT EXISTS key_pool (
        id INT AUTO_INCREMENT PRIMARY KEY,
        key_text VARCHAR(500) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    switch ($action) {
        case 'load':
            $stmt = $db->query("SELECT id, key_text FROM key_pool ORDER BY id ASC");
            $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);
            jsonResponse(['status' => 'success', 'keys' => $keys, 'count' => count($keys)]);
            break;

        case 'save':
            // Replace entire pool
            $keysText = $input['keys_text'] ?? '';
            $keys = array_filter(array_map('trim', explode("\n", $keysText)));

            $db->beginTransaction();
            $db->exec("DELETE FROM key_pool");
            $stmt = $db->prepare("INSERT INTO key_pool (key_text) VALUES (?)");
            foreach ($keys as $key) {
                if ($key)
                    $stmt->execute([$key]);
            }
            $db->commit();

            jsonResponse(['status' => 'success', 'count' => count($keys)]);
            break;

        case 'add':
            // Add new keys to pool (append)
            $keysText = $input['keys_text'] ?? '';
            $keys = array_filter(array_map('trim', explode("\n", $keysText)));

            $stmt = $db->prepare("INSERT INTO key_pool (key_text) VALUES (?)");
            $added = 0;
            foreach ($keys as $key) {
                if ($key) {
                    $stmt->execute([$key]);
                    $added++;
                }
            }

            jsonResponse(['status' => 'success', 'added' => $added]);
            break;

        case 'consume':
            // Take N keys from pool (remove them) and return
            $count = intval($input['count'] ?? 10);
            $stmt = $db->prepare("SELECT id, key_text FROM key_pool ORDER BY id ASC LIMIT ?");
            $stmt->execute([$count]);
            $keys = $stmt->fetchAll(PDO::FETCH_ASSOC);

            if ($keys) {
                $ids = array_column($keys, 'id');
                $placeholders = implode(',', array_fill(0, count($ids), '?'));
                $db->prepare("DELETE FROM key_pool WHERE id IN ($placeholders)")->execute($ids);
            }

            jsonResponse(['status' => 'success', 'keys' => $keys, 'consumed' => count($keys)]);
            break;

        case 'return':
            // Return failed keys back to pool
            $failedKeys = $input['keys'] ?? [];
            $stmt = $db->prepare("INSERT INTO key_pool (key_text) VALUES (?)");
            foreach ($failedKeys as $key) {
                if (trim($key))
                    $stmt->execute([trim($key)]);
            }
            jsonResponse(['status' => 'success', 'returned' => count($failedKeys)]);
            break;

        case 'clear':
            $db->exec("DELETE FROM key_pool");
            jsonResponse(['status' => 'success']);
            break;

        default:
            jsonResponse(['error' => 'Invalid action'], 400);
    }

} catch (Exception $e) {
    jsonResponse(['error' => 'Server error: ' . $e->getMessage()], 500);
}
?>