<?php
require_once __DIR__ . '/api/config.php';
$db = getDB();

echo "--- WORKERS ---\n";
$stmt = $db->query('SELECT worker_uuid, worker_name, status, last_seen, alert_sent FROM workers');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n--- NGROK KEYS ---\n";
$stmt = $db->query('SELECT id, token, worker_uuid, worker_name, worker_ip, assigned_at, is_active FROM ngrok_keys');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
?>