<?php
require __DIR__ . '/api/config.php';
$db = getDB();
$stmt = $db->query('SELECT NOW() as mysql_time');
$res = $stmt->fetch();
echo "MySQL time: " . $res['mysql_time'] . "<br>";
echo "PHP time: " . date('Y-m-d H:i:s') . "<br>";
