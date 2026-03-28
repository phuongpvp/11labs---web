<?php
/**
 * Worker Command API — for Chrome Extension ↔ Admin Panel communication
 * 
 * GET  ?worker=Sv10         → Get pending command for this worker
 * POST {action: 'send', worker: 'Sv10', command: 'disconnect'}  → Admin sends command
 * POST {action: 'report', ...}  → Extension reports result
 * POST {action: 'heartbeat', ...}  → Extension reports online status
 * GET  ?list=1              → Get all workers and their status (for admin)
 */
require_once __DIR__ . '/config.php';

// Handle CORS
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $db = getDB();

    // Ensure table exists
    $db->exec("CREATE TABLE IF NOT EXISTS colab_commands (
        id INTEGER PRIMARY KEY AUTO_INCREMENT,
        worker_name VARCHAR(50) NOT NULL,
        command VARCHAR(50) NOT NULL,
        status VARCHAR(20) DEFAULT 'pending',
        result_message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        executed_at TIMESTAMP NULL
    ) DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS colab_extensions (
        worker_name VARCHAR(50) PRIMARY KEY,
        tab_title VARCHAR(255),
        last_seen TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        is_online TINYINT DEFAULT 1
    ) DEFAULT CHARSET=utf8mb4");

    // Fix encoding for existing tables
    try {
        $db->exec("ALTER TABLE colab_commands CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $db->exec("ALTER TABLE colab_extensions CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    } catch (Exception $e) { /* Already converted */
    }

    // Add scheduled_at column for delayed commands (auto-restart)
    try {
        $db->exec("ALTER TABLE colab_commands ADD COLUMN scheduled_at TIMESTAMP NULL AFTER executed_at");
    } catch (Exception $e) { /* Already exists */
    }

} catch (Exception $e) {
    // Tables already exist, ignore
}

// === GET: Poll for commands or list all ===
if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // List all workers and their extension status (for admin panel)
    if (isset($_GET['list'])) {
        $extensions = $db->query("SELECT ce.*, 
            COALESCE(w.is_online, 0) as is_online,
            CASE WHEN ce.last_seen > (NOW() - INTERVAL 30 SECOND) THEN 1 ELSE 0 END as ext_online,
            w.worker_last_seen,
            w.worker_status
            FROM colab_extensions ce
            LEFT JOIN (
                SELECT REPLACE(REPLACE(LOWER(worker_name), 'server', ''), 'sever', '') as norm_name,
                    MAX(CASE WHEN last_seen > (NOW() - INTERVAL 300 SECOND) THEN 1 ELSE 0 END) as is_online,
                    MAX(last_seen) as worker_last_seen,
                    MAX(status) as worker_status
                FROM workers GROUP BY norm_name
            ) w ON REPLACE(LOWER(ce.worker_name), 'sv', '') = w.norm_name
            ORDER BY ce.worker_name")->fetchAll(PDO::FETCH_ASSOC);

        $recentCommands = $db->query("SELECT * FROM colab_commands ORDER BY created_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['extensions' => $extensions, 'recent_commands' => $recentCommands], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Poll for command (called by extension)
    $worker = $_GET['worker'] ?? '';
    if (!$worker) {
        echo json_encode(['error' => 'Worker name required']);
        exit;
    }

    // Get oldest pending command for this worker (respect scheduled_at for delayed commands)
    $stmt = $db->prepare("SELECT * FROM colab_commands WHERE worker_name = ? AND status = 'pending' AND (scheduled_at IS NULL OR scheduled_at <= NOW()) ORDER BY created_at ASC LIMIT 1");
    $stmt->execute([$worker]);
    $cmd = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($cmd) {
        // Mark as executing
        $db->prepare("UPDATE colab_commands SET status = 'executing' WHERE id = ?")->execute([$cmd['id']]);
        echo json_encode(['command' => $cmd['command'], 'id' => $cmd['id']]);
    } else {
        echo json_encode(['command' => 'none']);
    }
    exit;
}

// === POST: Send command, report result, or heartbeat ===
$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

// Admin sends a command
if ($action === 'send') {
    $worker = $input['worker'] ?? '';
    $command = $input['command'] ?? '';

    if (!$worker || !$command) {
        echo json_encode(['error' => 'Worker and command required']);
        exit;
    }

    $allowed = ['disconnect', 'run_all'];
    if (!in_array($command, $allowed)) {
        echo json_encode(['error' => 'Invalid command. Allowed: ' . implode(', ', $allowed)]);
        exit;
    }

    $stmt = $db->prepare("INSERT INTO colab_commands (worker_name, command) VALUES (?, ?)");
    $stmt->execute([$worker, $command]);

    echo json_encode(['status' => 'success', 'message' => "Command '$command' sent to $worker"]);
    exit;
}

// Extension reports command result
if ($action === 'report') {
    $commandId = $input['command_id'] ?? 0;
    $success = $input['success'] ?? false;
    $message = $input['message'] ?? '';

    $status = $success ? 'completed' : 'failed';
    $stmt = $db->prepare("UPDATE colab_commands SET status = ?, result_message = ?, executed_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $message, $commandId]);

    echo json_encode(['status' => 'ok']);
    exit;
}

// Extension heartbeat
if ($action === 'heartbeat') {
    $worker = $input['worker'] ?? '';
    $tabTitle = $input['tab_title'] ?? '';

    if ($worker) {
        $stmt = $db->prepare("INSERT INTO colab_extensions (worker_name, tab_title, last_seen) 
            VALUES (?, ?, NOW()) 
            ON DUPLICATE KEY UPDATE tab_title = VALUES(tab_title), last_seen = NOW()");
        $stmt->execute([$worker, $tabTitle]);
    }

    echo json_encode(['status' => 'ok']);
    exit;
}

// Remove extension entry (admin cleanup)
if ($action === 'remove') {
    $worker = $input['worker'] ?? '';
    if (!$worker) {
        echo json_encode(['error' => 'Worker name required']);
        exit;
    }

    // Send disconnect command so extension stops heartbeating
    try {
        $stmt = $db->prepare("INSERT INTO colab_commands (worker_name, command) VALUES (?, 'disconnect')");
        $stmt->execute([$worker]);
    } catch (Exception $e) { /* ignore */ }

    // Delete from colab_extensions
    $stmt = $db->prepare("DELETE FROM colab_extensions WHERE worker_name = ?");
    $stmt->execute([$worker]);

    // Also release any ngrok token assigned to this worker
    try {
        $stmt2 = $db->prepare("UPDATE ngrok_keys SET worker_uuid = NULL, worker_ip = NULL, assigned_at = NULL WHERE worker_name = ?");
        $stmt2->execute([$worker]);
    } catch (Exception $e) { /* ignore */ }

    echo json_encode(['status' => 'success', 'message' => "Đã xóa $worker"]);
    exit;
}

echo json_encode(['error' => 'Unknown action']);
?>