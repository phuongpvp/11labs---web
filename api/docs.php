<?php
/**
 * Documents/Blog API
 * GET  ?action=list                → Public list of published articles
 * GET  ?action=detail&id=X         → Article detail (plan-gated)
 * GET  ?action=categories          → Distinct categories
 * POST action=create/update/delete → Admin CRUD
 */
require_once __DIR__ . '/config.php';

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

try {
    $db = getDB();

    // Lazy table creation
    $db->exec("CREATE TABLE IF NOT EXISTS documents (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(500) NOT NULL,
        summary TEXT,
        content LONGTEXT,
        thumbnail TEXT,
        category VARCHAR(100) DEFAULT 'guide',
        min_plan VARCHAR(50) DEFAULT 'pro',
        status VARCHAR(20) DEFAULT 'draft',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

} catch (Exception $e) { /* Table exists */
}

$method = $_SERVER['REQUEST_METHOD'];

// ========================
// GET — Public endpoints
// ========================
if ($method === 'GET') {
    $action = $_GET['action'] ?? 'list';

    if ($action === 'list') {
        $category = $_GET['category'] ?? '';
        if ($category) {
            $stmt = $db->prepare("SELECT id, title, summary, thumbnail, category, min_plan, created_at FROM documents WHERE status = 'published' AND category = ? ORDER BY created_at DESC");
            $stmt->execute([$category]);
        } else {
            $stmt = $db->query("SELECT id, title, summary, thumbnail, category, min_plan, created_at FROM documents WHERE status = 'published' ORDER BY created_at DESC");
        }
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'documents' => $docs], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'categories') {
        $stmt = $db->query("SELECT DISTINCT category FROM documents WHERE status = 'published' AND category IS NOT NULL AND category != '' ORDER BY category");
        $cats = $stmt->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['status' => 'success', 'categories' => $cats], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'detail') {
        $id = (int) ($_GET['id'] ?? 0);
        if (!$id) {
            echo json_encode(['error' => 'Missing id']);
            exit;
        }

        // Check if admin is requesting (for edit)
        $tokenParam = $_GET['token'] ?? '';
        $isAdmin = ($tokenParam === 'admin');

        if ($isAdmin) {
            // Admin can fetch ANY document (published, draft, etc.) with FULL content
            $stmt = $db->prepare("SELECT * FROM documents WHERE id = ?");
        } else {
            $stmt = $db->prepare("SELECT * FROM documents WHERE id = ? AND status = 'published'");
        }
        $stmt->execute([$id]);
        $doc = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$doc) {
            echo json_encode(['error' => 'Not found']);
            exit;
        }

        // Check user plan
        $authHeader = $_GET['token'] ?? '';
        if (!$authHeader) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? '';
            $authHeader = str_replace('Bearer ', '', $authHeader);
        }

        $canView = false;
        $userPlan = 'free';

        if ($authHeader) {
            try {
                $payload = json_decode(base64_decode(explode('.', $authHeader)[0]), true);
                $userId = $payload['user_id'] ?? 0;
                if ($userId) {
                    $stmtU = $db->prepare("SELECT plan, custom_plan_name FROM users WHERE id = ?");
                    $stmtU->execute([$userId]);
                    $user = $stmtU->fetch();
                    if ($user) {
                        $userPlan = strtolower($user['custom_plan_name'] ?: $user['plan'] ?: 'free');
                    }
                }
            } catch (Exception $e) { /* Invalid token */
            }
        }

        // Plan hierarchy: supper > pro > basic > free
        $planLevel = ['free' => 0, 'basic' => 1, 'pro' => 2, 'supper' => 3, 'supper vip' => 3];
        $userLevel = 0;
        foreach ($planLevel as $p => $l) {
            if (strpos($userPlan, $p) !== false && $l > $userLevel)
                $userLevel = $l;
        }
        $requiredLevel = $planLevel[$doc['min_plan']] ?? 2;
        $canView = $isAdmin || ($userLevel >= $requiredLevel);

        if (!$canView) {
            // Return limited content
            $doc['content'] = mb_substr(strip_tags($doc['content']), 0, 150, 'UTF-8') . '...';
            $doc['locked'] = true;
        } else {
            $doc['locked'] = false;
        }

        echo json_encode(['status' => 'success', 'document' => $doc], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Admin list (all, including drafts)
    if ($action === 'admin_list') {
        $stmt = $db->query("SELECT id, title, summary, thumbnail, category, min_plan, status, created_at, updated_at FROM documents ORDER BY created_at DESC");
        $docs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['status' => 'success', 'documents' => $docs], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

// ========================
// POST — Admin CRUD
// ========================
if ($method === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';

    if ($action === 'create') {
        $stmt = $db->prepare("INSERT INTO documents (title, summary, content, thumbnail, category, min_plan, status) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $input['title'] ?? 'Untitled',
            $input['summary'] ?? '',
            $input['content'] ?? '',
            $input['thumbnail'] ?? '',
            $input['category'] ?? 'guide',
            $input['min_plan'] ?? 'pro',
            $input['status'] ?? 'draft'
        ]);
        echo json_encode(['status' => 'success', 'id' => $db->lastInsertId()], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'update') {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) {
            echo json_encode(['error' => 'Missing id']);
            exit;
        }

        $stmt = $db->prepare("UPDATE documents SET title = ?, summary = ?, content = ?, thumbnail = ?, category = ?, min_plan = ?, status = ? WHERE id = ?");
        $stmt->execute([
            $input['title'] ?? '',
            $input['summary'] ?? '',
            $input['content'] ?? '',
            $input['thumbnail'] ?? '',
            $input['category'] ?? 'guide',
            $input['min_plan'] ?? 'pro',
            $input['status'] ?? 'draft',
            $id
        ]);
        echo json_encode(['status' => 'success'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($action === 'delete') {
        $id = (int) ($input['id'] ?? 0);
        if (!$id) {
            echo json_encode(['error' => 'Missing id']);
            exit;
        }

        $db->prepare("DELETE FROM documents WHERE id = ?")->execute([$id]);
        echo json_encode(['status' => 'success'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    echo json_encode(['error' => 'Unknown action']);
    exit;
}

echo json_encode(['error' => 'Method not allowed']);
?>