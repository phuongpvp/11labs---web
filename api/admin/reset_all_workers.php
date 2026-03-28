<?php
require_once __DIR__ . '/../config.php';

$adminPassword = $_SERVER['HTTP_X_ADMIN_PASSWORD'] ?? '';
if (!verifyAdminPassword($adminPassword)) {
    die("Unauthorized");
}

$db = getDB();
$stmt = $db->query("SELECT worker_uuid, url FROM workers WHERE last_seen > (NOW() - INTERVAL 10 MINUTE)");
$workers = $stmt->fetchAll();

echo "<h1>Resetting All Active Workers</h1>";
echo "<ul>";

foreach ($workers as $w) {
    echo "<li>Worker {$w['worker_uuid']} ({$w['url']}): ";

    $ch = curl_init(rtrim($w['url'], '/') . '/api/reset_worker');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        echo "<b style='color:green'>SUCCESS</b> - Queue cleared.";
    } else {
        echo "<b style='color:red'>FAILED</b> - HTTP $httpCode ($response)";
    }
    echo "</li>";
}

echo "</ul>";
echo "<p><a href='health.php'>Back to Health Monitor</a></p>";
?>