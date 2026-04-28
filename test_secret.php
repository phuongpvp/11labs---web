<?php
require __DIR__ . '/api/config.php';
$db = getDB();
$w = $db->query('SELECT url FROM workers WHERE last_seen > (NOW() - INTERVAL 300 SECOND) LIMIT 1')->fetch();
if (!$w) die('no worker');

$url = rtrim($w['url'], '/') . '/api/test_key';
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['api_key' => 'fake_key', 'secret' => WORKER_SECRET]));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
echo "URL: $url\n";
echo "Response: $res\n";
echo "Code: $code\n";
