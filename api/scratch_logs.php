<?php
require_once __DIR__ . '/config.php';
$db = getDB();
$stmt = $db->prepare("SELECT * FROM worker_logs WHERE job_id = '91A385E0' ORDER BY created_at DESC LIMIT 50");
$stmt->execute();
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($logs as $log) {
    echo "[{$log['created_at']}] {$log['worker_name']} ({$log['level']}): {$log['message']}\n";
}
